<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/VideoEncoder.php';
require_once __DIR__ . '/VideoQuality.php';

/**
 * Trimming a video from the Video Stats screen.
 *
 * Three ways to cut, each a range of the video to *remove*:
 *
 *  - `start` — 0ms to X: drop the opening.
 *  - `end`   — X to the end: drop the tail.
 *  - `cut`   — A to B, strictly inside the video: drop a middle section and
 *              join what is either side of it.
 *
 * A trim never touches the video. It is made into a second archive beside it,
 * `{ID}_trim.mp4.epc`, by the same detached convert job a Convert or a Reduce
 * uses — sharing that job's id is what stops a trim and a conversion of the
 * same video running over each other. The user then plays the trimmed copy and
 * either keeps it, which swaps it in for the original, or deletes it.
 *
 * The trim is re-encoded, not stream-copied. Copying is far faster but can only
 * cut on a keyframe, which can be seconds away from the millisecond asked for.
 *
 * What was asked for, and what the copy measured, ride in the video's own
 * metadata under `trim` until it is kept or deleted — Keep needs them to move
 * the category timestamps and the preview capture point along with the cut.
 */
final class VideoTrim
{
    public const MODES = ['start', 'end', 'cut'];

    public function __construct(
        private VideoEncoder $encoder = new VideoEncoder(),
        private StashCrypto $crypto = new StashCrypto(),
        private Datastore $datastore = new Datastore(),
    ) {
    }

    public static function archivePath(string $user, string $id): string
    {
        return Datastore::videoDir($user, $id) . "/{$id}_trim.mp4.epc";
    }

    public static function exists(string $user, string $id): bool
    {
        return is_file(self::archivePath($user, $id));
    }

    /**
     * The range to remove, in milliseconds. `end` has no `to_ms` until the
     * worker has measured the real duration: the index only knows it to the
     * second for videos uploaded before it was recorded exactly.
     *
     * @return array{mode: string, from_ms: int, to_ms: ?int}
     */
    public static function spec(string $mode, int $fromMs, ?int $toMs): array
    {
        return match ($mode) {
            'start' => ['mode' => 'start', 'from_ms' => 0, 'to_ms' => $toMs],
            'end' => ['mode' => 'end', 'from_ms' => $fromMs, 'to_ms' => null],
            default => ['mode' => 'cut', 'from_ms' => $fromMs, 'to_ms' => $toMs],
        };
    }

    /**
     * Why this trim cannot be made, or null if it can. $durationMs is null
     * when the exact length is not known yet; the upper bounds are then left
     * for the worker, which checks again against the decrypted file.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     */
    public static function validate(array $spec, ?int $durationMs): ?string
    {
        $from = $spec['from_ms'];
        $to = $spec['to_ms'];
        $length = $durationMs !== null ? self::formatMs($durationMs) : null;
        $inside = static fn(int $ms): bool => $durationMs === null || $ms < $durationMs;

        return match ($spec['mode']) {
            'start' => ($to === null || $to <= 0 || !$inside($to))
                ? 'Start has to end after 0ms and before the end of the video' . ($length ? " ({$length})." : '.')
                : null,
            'end' => ($from <= 0 || !$inside($from))
                ? 'End has to begin after 0ms and before the end of the video' . ($length ? " ({$length})." : '.')
                : null,
            'cut' => match (true) {
                $to === null || $to <= $from => 'A cut has to end after it starts.',
                $from <= 0 || !$inside($to) => 'A cut has to fall inside the video, after 0ms and before '
                    . ($length ?? 'its end') . '. To trim from either end, use Start or End.',
                default => null,
            },
            default => 'Pick Start, End or Cut.',
        };
    }

    /**
     * How long the trimmed video will be.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     */
    public static function keptMs(array $spec, int $durationMs): int
    {
        return match ($spec['mode']) {
            'start' => $durationMs - (int) $spec['to_ms'],
            'end' => $spec['from_ms'],
            default => $durationMs - ((int) $spec['to_ms'] - $spec['from_ms']),
        };
    }

    /**
     * The ffmpeg graph that removes the range. Every segment has its
     * timestamps reset to start at zero, and a cut is the two segments either
     * side joined with concat. A silent video has no [0:a] to name, and a
     * graph that names it fails outright, so audio is only built when there is
     * some.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     * @return array{graph: string, maps: list<string>}
     */
    public static function filterGraph(array $spec, bool $audio): array
    {
        $from = self::seconds($spec['from_ms']);
        $to = self::seconds((int) $spec['to_ms']);
        $maps = $audio ? ['[v]', '[a]'] : ['[v]'];

        if ($spec['mode'] !== 'cut') {
            $range = $spec['mode'] === 'start' ? "start={$to}" : "end={$from}";
            $graph = "[0:v]trim={$range},setpts=PTS-STARTPTS[v]";

            if ($audio) {
                $graph .= ";[0:a]atrim={$range},asetpts=PTS-STARTPTS[a]";
            }

            return ['graph' => $graph, 'maps' => $maps];
        }

        $parts = [
            '[0:v]split[v0][v1]',
            "[v0]trim=end={$from},setpts=PTS-STARTPTS[va]",
            "[v1]trim=start={$to},setpts=PTS-STARTPTS[vb]",
        ];

        if ($audio) {
            array_push(
                $parts,
                '[0:a]asplit[a0][a1]',
                "[a0]atrim=end={$from},asetpts=PTS-STARTPTS[aa]",
                "[a1]atrim=start={$to},asetpts=PTS-STARTPTS[ab]",
                '[va][aa][vb][ab]concat=n=2:v=1:a=1[v][a]',
            );
        } else {
            $parts[] = '[va][vb]concat=n=2:v=1:a=0[v]';
        }

        return ['graph' => implode(';', $parts), 'maps' => $maps];
    }

    /**
     * Where a point in the original lands in the trimmed video. A point inside
     * the removed range moves to the edge of the cut.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     */
    public static function mapSeconds(float $seconds, array $spec): float
    {
        $from = $spec['from_ms'] / 1000;
        $to = (int) $spec['to_ms'] / 1000;

        return match ($spec['mode']) {
            'start' => max(0.0, $seconds - $to),
            'end' => min($seconds, $from),
            default => $seconds < $from ? $seconds : ($seconds < $to ? $from : $seconds - ($to - $from)),
        };
    }

    /**
     * True when the point was inside the part the trim removes.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     */
    public static function removes(float $seconds, array $spec): bool
    {
        $ms = $seconds * 1000;

        return match ($spec['mode']) {
            'start' => $ms < (int) $spec['to_ms'],
            'end' => $ms >= $spec['from_ms'],
            default => $ms >= $spec['from_ms'] && $ms < (int) $spec['to_ms'],
        };
    }

    /**
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     */
    public static function describe(array $spec): string
    {
        return match ($spec['mode']) {
            'start' => 'Removed 0ms to ' . self::formatMs((int) $spec['to_ms']),
            'end' => 'Removed ' . self::formatMs($spec['from_ms']) . ' to the end',
            default => 'Removed ' . self::formatMs($spec['from_ms']) . ' to ' . self::formatMs((int) $spec['to_ms']),
        };
    }

    /**
     * 723029 → "12m3s29ms". Leading zero units are left off, so 4500 is
     * "4s500ms".
     */
    public static function formatMs(int $ms): string
    {
        $hours = intdiv($ms, 3_600_000);
        $minutes = intdiv($ms % 3_600_000, 60_000);
        $seconds = intdiv($ms % 60_000, 1000);
        $millis = $ms % 1000;

        $out = '';
        if ($hours > 0) {
            $out .= "{$hours}h";
        }
        if ($out !== '' || $minutes > 0) {
            $out .= "{$minutes}m";
        }
        if ($out !== '' || $seconds > 0) {
            $out .= "{$seconds}s";
        }

        return $out . "{$millis}ms";
    }

    /**
     * Worker side: decrypts the video, encodes the trimmed copy and stores it
     * as `{ID}_trim.mp4.epc`. The video itself is only read.
     *
     * @param array{mode: string, from_ms: int, to_ms: ?int} $spec
     * @param callable(float, float): void|null $onProgress
     * @param callable(string): void|null $onStage
     * @return array{ok: bool, message: string}
     */
    public function make(
        string $user,
        string $key,
        string $id,
        array $spec,
        ?callable $onProgress = null,
        ?callable $onStage = null,
    ): array {
        $videoDir = Datastore::videoDir($user, $id);
        $videoArchive = "{$videoDir}/{$id}.mp4.epc";
        $trimArchive = self::archivePath($user, $id);

        if (!is_file($videoArchive)) {
            return ['ok' => false, 'message' => 'There is no video file to trim.'];
        }

        if (is_file($trimArchive)) {
            return ['ok' => false, 'message' => 'This video already has a trimmed copy. Keep or delete it first.'];
        }

        $workDir = Datastore::tmpfsWorkDir('trim');

        try {
            $onStage && $onStage('Decrypting…');

            $extractDir = "{$workDir}/extract";

            if (!$this->crypto->extract($videoArchive, $extractDir, $key)) {
                return ['ok' => false, 'message' => 'The video could not be opened.'];
            }

            $original = glob("{$extractDir}/*")[0] ?? null;
            $duration = $original !== null ? $this->encoder->durationSeconds($original) : null;

            if ($duration === null || $duration <= 0) {
                return ['ok' => false, 'message' => 'The video\'s length could not be read.'];
            }

            // Checked again here, against the real file: the form only knew
            // the length as well as the index did.
            $durationMs = (int) floor($duration * 1000);

            if ($spec['mode'] === 'end') {
                $spec['to_ms'] = $durationMs;
            }

            $problem = self::validate($spec, $durationMs);

            if ($problem !== null) {
                return ['ok' => false, 'message' => $problem];
            }

            $onStage && $onStage('Trimming…');

            $trimmed = "{$workDir}/{$id}_trim.mp4";

            if (!$this->encoder->convertToMp4Hevc(
                $original,
                $trimmed,
                $onProgress,
                filterGraph: self::filterGraph($spec, $this->encoder->hasAudio($original)),
                outputSeconds: self::keptMs($spec, $durationMs) / 1000,
            )) {
                return ['ok' => false, 'message' => 'The trim failed or stalled. The video is untouched.'];
            }

            $probe = $this->encoder->probe($trimmed) ?? [];
            $trimmedMs = (int) round(($this->encoder->durationSeconds($trimmed) ?? 0) * 1000);

            $onStage && $onStage('Encrypting…');

            // Deleted while it was being trimmed: saving now would at best fail
            // and at worst (v1's 7z did this) recreate the directory around a
            // lone trimmed copy no screen can reach.
            if (!is_dir($videoDir)) {
                return ['ok' => false, 'message' => 'The video was deleted before the trim finished.'];
            }

            if (!$this->crypto->replace($trimmed, $trimArchive, $key)) {
                return ['ok' => false, 'message' => 'The trimmed copy could not be saved. The video is untouched.'];
            }

            $metadata = $this->datastore->loadVideoMetadata($user, $key, $id);

            // Keep cannot move the categories without this, so a copy whose
            // details were not recorded is not offered at all.
            $saved = $metadata !== null && $this->datastore->saveVideoMetadata($user, $key, $id, [
                ...$metadata,
                'trim' => [
                    ...$spec,
                    'duration_ms' => $trimmedMs,
                    'width' => $probe['width'] ?? null,
                    'height' => $probe['height'] ?? null,
                    'fps' => $probe['fps'] ?? null,
                    'made_at' => time(),
                ],
            ]);

            if (!$saved) {
                @unlink($trimArchive);

                return ['ok' => false, 'message' => 'The trimmed copy could not be recorded. The video is untouched.'];
            }

            return ['ok' => true, 'message' => 'Trimmed copy ready (' . self::formatMs($trimmedMs) . '). Play it, then keep or delete it.'];
        } finally {
            Datastore::wipe($workDir);
        }
    }

    /**
     * Swaps the trimmed copy in for the video, keeping its title and
     * everything else, and moves every timestamp that points into the video
     * along with the cut.
     *
     * The order is media → per-video metadata → index (Docs/PLAN.md 4.29), so
     * an interruption leaves an index that still describes what is on disk.
     * The swap is a rename: both archives are already encrypted under the same
     * key and sit in the same directory, so there is nothing to re-encode
     * and no moment where the video is missing.
     *
     * @return array{ok: bool, message: string, frame_at: ?float}
     */
    public function keep(string $user, string $key, string $id): array
    {
        $trimArchive = self::archivePath($user, $id);
        $metadata = $this->datastore->loadVideoMetadata($user, $key, $id);
        $trim = $metadata['trim'] ?? null;

        if (!is_file($trimArchive) || !is_array($trim)) {
            return ['ok' => false, 'message' => 'There is no trimmed copy to keep.', 'frame_at' => null];
        }

        if (!rename($trimArchive, Datastore::videoDir($user, $id) . "/{$id}.mp4.epc")) {
            return ['ok' => false, 'message' => 'The trimmed copy could not be swapped in. The video is unchanged.', 'frame_at' => null];
        }

        $keptMs = (int) $trim['duration_ms'];
        $measured = [
            'length_seconds' => (int) round($keptMs / 1000),
            'duration_ms' => $keptMs,
            'width' => $trim['width'] ?? null,
            'height' => $trim['height'] ?? null,
            'fps' => $trim['fps'] ?? null,
            'inspected_at' => time(),
            'format' => 'mp4',
            'codec' => 'hevc',
        ];

        // The output is MP4/H.265 like a conversion's, so "Not Converted" goes
        // the same way it does there.
        $assignments = [];
        foreach ($metadata['categories'] ?? [] as $assignment) {
            if ($assignment['name'] === 'Not Converted') {
                continue;
            }

            $assignment['timestamp_seconds'] = (int) round(
                self::mapSeconds((float) $assignment['timestamp_seconds'], $trim),
            );
            $assignments[] = $assignment;
        }

        // The thumbnail is a picture and survives the cut — unless it was
        // taken from the part that has just been removed. Then it is retaken
        // where that point now falls, by the preview job the caller starts.
        $capture = $metadata['preview_capture_seconds'] ?? null;
        $frameAt = null;

        if ($capture !== null) {
            $mapped = self::mapSeconds((float) $capture, $trim);
            $capture = max(0.0, min($mapped, $keptMs / 1000 - 0.5));

            if (self::removes((float) $metadata['preview_capture_seconds'], $trim)) {
                $frameAt = $capture;
            }
        }

        unset($metadata['trim']);
        $metadata = VideoQuality::apply([
            ...$metadata,
            ...$measured,
            'categories' => $assignments,
            'preview_capture_seconds' => $capture,
        ]);

        if (!$this->datastore->saveVideoMetadata($user, $key, $id, $metadata)) {
            error_log("MyStash trim: kept the trim of {$id} for {$user} but could not save its metadata");

            return ['ok' => false, 'message' => 'The trimmed video is in place, but its details could not be saved.', 'frame_at' => $frameAt];
        }

        $index = $this->datastore->loadIndex($user, $key);

        if ($index !== null) {
            foreach ($index['videos'] as &$video) {
                if ($video['id'] === $id) {
                    $video = VideoQuality::apply([
                        ...$video,
                        ...$measured,
                        'categories' => Datastore::categoryNames($assignments),
                    ]);
                    break;
                }
            }
            unset($video);
        }

        if ($index === null || !$this->datastore->saveIndex($user, $key, $index)) {
            error_log("MyStash trim: kept the trim of {$id} for {$user} but could not save the index");

            return ['ok' => false, 'message' => 'The trimmed video is in place, but the wall could not be updated.', 'frame_at' => $frameAt];
        }

        return ['ok' => true, 'message' => 'Kept the trimmed video (' . self::formatMs($keptMs) . ').', 'frame_at' => $frameAt];
    }

    /**
     * Deletes the trimmed copy and forgets it was made. The video was never
     * touched, so there is nothing else to undo.
     *
     * @return array{ok: bool, message: string}
     */
    public function discard(string $user, string $key, string $id): array
    {
        $trimArchive = self::archivePath($user, $id);

        if (is_file($trimArchive) && !unlink($trimArchive)) {
            return ['ok' => false, 'message' => 'The trimmed copy could not be deleted.'];
        }

        $metadata = $this->datastore->loadVideoMetadata($user, $key, $id);

        if ($metadata !== null && array_key_exists('trim', $metadata)) {
            unset($metadata['trim']);
            $this->datastore->saveVideoMetadata($user, $key, $id, $metadata);
        }

        return ['ok' => true, 'message' => 'Deleted the trimmed copy. The video is unchanged.'];
    }

    private static function seconds(int $ms): string
    {
        return sprintf('%.3F', $ms / 1000);
    }
}
