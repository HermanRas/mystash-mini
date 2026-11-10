<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Datastore.php';

/**
 * The Stats screen's arithmetic: what each video costs on disk, and whether an
 * inspected video is worth shrinking (Docs/PLAN.md 5.x, the stats page).
 *
 * Kept out of the page so the worker and the endpoints agree on the rules. The
 * reduce offer is only ever made for something an inspection has *measured* —
 * fps and pixel size are read off the decrypted file by ffprobe, never typed
 * in — and the endpoint re-checks against the same rules rather than trusting
 * which boxes the form came back with.
 */
final class VideoStats
{
    /** The frame rate a reduction brings a video down to. */
    public const TARGET_FPS = 30;

    /**
     * Above this, a video is offered the 30fps reduction. Not 30 itself: NTSC
     * material is 29.97, and "reducing" 30 to 30 would be a full re-encode for
     * nothing.
     */
    private const FPS_THRESHOLD = 30.5;

    /** The Full HD box, long edge × short edge. */
    public const MAX_LONG_EDGE = 1920;
    public const MAX_SHORT_EDGE = 1080;

    /**
     * Bytes on disk for one video: every file in its directory — the video
     * archive, the preview clip, the thumbnail, the metadata. That is what
     * deleting it would give back, which is the question a stats page answers.
     */
    public static function diskBytes(string $user, string $id): int
    {
        $dir = Datastore::videoDir($user, $id);

        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    /**
     * True when either edge is over the Full HD box, oriented to the video: a
     * portrait 1080×1920 phone clip is *at* Full HD, not over it.
     */
    public static function exceedsFullHd(?int $width, ?int $height): bool
    {
        if (!$width || !$height) {
            return false;
        }

        return max($width, $height) > self::MAX_LONG_EDGE
            || min($width, $height) > self::MAX_SHORT_EDGE;
    }

    public static function exceedsTargetFps(?float $fps): bool
    {
        return $fps !== null && $fps > self::FPS_THRESHOLD;
    }

    /**
     * The ffmpeg -vf chain for a reduction, or null when nothing applies.
     *
     * The scale keeps the aspect ratio and fits inside the box turned the same
     * way as the video, rounding to even dimensions (libx265 with yuv420p will
     * not take an odd one).
     */
    public static function filterFor(bool $fps, bool $scale, ?int $width, ?int $height): ?string
    {
        $filters = [];

        if ($fps) {
            $filters[] = 'fps=' . self::TARGET_FPS;
        }

        if ($scale && $width && $height) {
            [$boxW, $boxH] = $width >= $height
                ? [self::MAX_LONG_EDGE, self::MAX_SHORT_EDGE]
                : [self::MAX_SHORT_EDGE, self::MAX_LONG_EDGE];

            $filters[] = "scale={$boxW}:{$boxH}:force_original_aspect_ratio=decrease:force_divisible_by=2";
        }

        return $filters === [] ? null : implode(',', $filters);
    }

    /**
     * The words for what a reduction will do, for the job card and messages.
     */
    public static function describe(bool $fps, bool $scale): string
    {
        $parts = [];

        if ($fps) {
            $parts[] = self::TARGET_FPS . 'fps';
        }

        if ($scale) {
            $parts[] = self::MAX_LONG_EDGE . '×' . self::MAX_SHORT_EDGE;
        }

        return 'Reducing to ' . implode(' and ', $parts);
    }

    /**
     * The videos whose title repeats an earlier one's: the ids a "delete
     * duplicates" would remove.
     *
     * Titles match case-insensitively with surrounding and repeated whitespace
     * ignored, so "My Clip" and "my  clip " are the same. In each group the
     * earliest upload is kept (index order breaks ties and covers entries with
     * no uploaded_at). Pictures are included — on Mini most duplicates are
     * pictures — but grouped apart from videos, so a picture is never taken
     * for a copy of a video that shares its title.
     *
     * @param list<array<string,mixed>> $videos the index's videos
     * @return list<string> ids of the copies to delete
     */
    public static function duplicateIds(array $videos): array
    {
        $groups = [];

        foreach (array_values($videos) as $position => $video) {
            $key = ($video['kind'] ?? 'video') . "\0" . mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($video['title'] ?? ''))));

            if (trim((string) ($video['title'] ?? '')) === '') {
                continue;
            }

            $groups[$key][] = [
                'id' => (string) $video['id'],
                'at' => (string) ($video['uploaded_at'] ?? ''),
                'pos' => $position,
            ];
        }

        $ids = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            // Missing uploaded_at sorts last, so a dated original is kept
            // over an undated copy.
            usort($group, static fn(array $a, array $b) =>
                [$a['at'] === '' ? 1 : 0, $a['at'], $a['pos']] <=> [$b['at'] === '' ? 1 : 0, $b['at'], $b['pos']]);

            foreach (array_slice($group, 1) as $copy) {
                $ids[] = $copy['id'];
            }
        }

        return $ids;
    }

    /**
     * A byte count for people: 1.4 GB, 812 MB, 96 KB.
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? "{$bytes} B"
            : number_format($value, $value >= 100 ? 0 : 1) . ' ' . $units[$unit];
    }
}
