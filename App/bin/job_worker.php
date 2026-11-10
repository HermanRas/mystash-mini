<?php

declare(strict_types=1);

/**
 * The background worker behind every long job (Docs/PLAN.md 4.28, 6.6).
 *
 * Spawned detached by Jobs::start() with nothing but a job id on its argv. It
 * takes the keys out of the job's tmpfs key file (deleting it as it
 * reads), does the work, and reports progress into the job record for the
 * browser to poll. It has no session, no output and no request behind it — the
 * user may well have closed the tab, and the job carries on regardless, which
 * is the entire point.
 *
 * Usage: php bin/job_worker.php {job-id}
 */

require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/MigrateV2.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Rekey.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/VideoPreview.php';
require_once __DIR__ . '/../src/VideoQuality.php';
require_once __DIR__ . '/../src/VideoStats.php';
require_once __DIR__ . '/../src/VideoTrim.php';

use MyStash\StashCrypto;
use MyStash\MigrateV2;
use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Rekey;
use MyStash\VideoCategories;
use MyStash\VideoEncoder;
use MyStash\VideoPreview;
use MyStash\VideoQuality;
use MyStash\VideoStats;
use MyStash\VideoTrim;

$id = (string) ($argv[1] ?? '');

if ($id === '') {
    fwrite(STDERR, "Usage: php bin/job_worker.php {job-id}\n");
    exit(1);
}

// Taken first, before anything is read: holding the lock is what makes
// Jobs::read() report this job as alive, and failing to take it means another
// worker already has it — in which case this one must do nothing at all.
$lock = Jobs::hold($id);

if ($lock === false) {
    exit(0);
}

$record = Jobs::read($id);
$secrets = Jobs::claimSecrets($id);

if ($record === null || $secrets === null) {
    Jobs::finish($id, false, 'The job was not staged correctly.');
    exit(1);
}

// No time limit and no abort-on-disconnect: there is no client to disconnect.
set_time_limit(0);

Jobs::update($id, ['pid' => getmypid()]);

try {
    match ((string) $record['kind']) {
        // A trim shares the convert job's id for the same reason a reduction
        // does: one ffmpeg per video at a time. See runTrim().
        'convert' => !empty($record['trim_mode'])
            ? runTrim($id, $record, $secrets)
            : runConvert($id, $record, $secrets),
        'rekey' => runRekey($id, $record, $secrets),
        'upgrade' => runUpgrade($id, $record, $secrets),
        'preview' => runPreview($id, $record, $secrets),
        default => Jobs::finish($id, false, 'Unknown job type.'),
    };
} catch (\Throwable $error) {
    error_log("MyStash job {$id} crashed: " . $error->getMessage());
    Jobs::finish($id, false, 'The job stopped with an unexpected error. Nothing was lost — try again.');
    exit(1);
}

exit(0);

/**
 * Transcode one video to MP4/H.265 in place (4.28).
 *
 * This is the body that used to run inside the Convert request. It is
 * unchanged in what it does to the stash — including the 4.29 rules: the
 * archive is replaced, never overwritten, and the bookkeeping goes media →
 * per-video metadata → index, so an interruption leaves an index that still
 * describes what is on disk.
 *
 * The same job also carries the Stats screen's reductions (30fps, 1920×1080).
 * They are a convert with a -vf chain, not a job kind of their own, on
 * purpose: the job id is the guard against two ffmpeg runs replacing the same
 * archive, and a separate kind would get a separate id and could run beside a
 * plain convert of the same video. The choice rides in the public record as
 * `reduce_fps`/`reduce_scale` with the inspected size it was made against.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runConvert(string $id, array $record, array $secrets): void
{
    $user = (string) $record['user'];
    $videoId = (string) $record['target'];
    $key = (string) $secrets['key'];

    $archivePath = Datastore::videoDir($user, $videoId) . "/{$videoId}.mp4.epc";

    if (!file_exists($archivePath)) {
        Jobs::finish($id, false, 'There is no encrypted video file behind this entry to convert.');

        return;
    }

    $crypto = new StashCrypto();
    $encoder = new VideoEncoder();
    $workDir = Datastore::tmpfsWorkDir('convert');

    try {
        Jobs::update($id, ['message' => 'Decrypting…', 'percent' => 0]);

        $extractDir = "{$workDir}/extract";

        if (!$crypto->extract($archivePath, $extractDir, $key)) {
            Jobs::finish($id, false, 'Could not decrypt the video.');

            return;
        }

        $originalPath = (glob("{$extractDir}/*") ?: [])[0] ?? null;

        if ($originalPath === null) {
            Jobs::finish($id, false, 'The archive decrypted to nothing.');

            return;
        }

        $reduceFps = !empty($record['reduce_fps']);
        $reduceScale = !empty($record['reduce_scale']);
        $filter = VideoStats::filterFor(
            $reduceFps,
            $reduceScale,
            isset($record['width']) ? (int) $record['width'] : null,
            isset($record['height']) ? (int) $record['height'] : null,
        );
        $label = $filter !== null ? VideoStats::describe($reduceFps, $reduceScale) : 'Converting to MP4/H.265';

        Jobs::update($id, ['message' => "{$label}…"]);

        $convertedPath = "{$workDir}/{$videoId}.mp4";

        // The percentage is ffmpeg's own position in the video, reported
        // through -progress. Written straight into the job record, which is
        // what the watch page is polling.
        $onProgress = static function (float $done, float $total) use ($id): void {
            Jobs::update($id, [
                'done' => (int) round($done),
                'total' => (int) round($total),
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
            ]);
        };

        if (!$encoder->convertToMp4Hevc($originalPath, $convertedPath, $onProgress, videoFilter: $filter)) {
            // Either ffmpeg failed or it stopped making progress and was
            // killed. Either way nothing has touched the stash yet.
            Jobs::finish($id, false, 'The conversion failed or stalled. The original video is untouched.');

            return;
        }

        Jobs::update($id, ['message' => 'Re-encrypting…', 'percent' => 99]);

        // Never encrypt straight over the live archive — replace() writes
        // beside it, proves the new archive opens and holds the same bytes,
        // and only then swaps it in (Docs/PLAN.md 4.29).
        if (!$crypto->replace($convertedPath, $archivePath, $key)) {
            error_log("MyStash convert: failed to replace {$archivePath} for {$user}");
            Jobs::finish($id, false, 'Could not save the converted video. The original is untouched — try again.');

            return;
        }

        // The converted file is still decrypted in tmpfs here, so this is the
        // one moment its real size and frame rate are cheap to read — and
        // after a reduction the Stats screen should show what it now is, not
        // what it was.
        $probe = $encoder->probe($convertedPath);
        $height = $probe['height'] ?? $encoder->videoHeight($convertedPath);
        $seconds = $encoder->durationSeconds($convertedPath);
        $measured = [
            'height' => $height,
            'width' => $probe['width'] ?? null,
            'fps' => $probe['fps'] ?? null,
            'inspected_at' => time(),
            ...($seconds !== null ? ['duration_ms' => (int) floor($seconds * 1000)] : []),
        ];

        $datastore = new Datastore();
        $index = $datastore->loadIndex($user, $key);

        if ($index === null) {
            // The media is converted and safe; only the labels will be wrong.
            error_log("MyStash convert: converted {$videoId} for {$user} but could not open the index");
            Jobs::finish($id, false, 'The video converted, but its index entry could not be updated.');

            return;
        }

        foreach ($index['videos'] as &$video) {
            if ($video['id'] === $videoId) {
                $video = VideoQuality::apply([
                    ...$video,
                    ...$measured,
                    'format' => 'mp4',
                    'codec' => 'hevc',
                ]);
                break;
            }
        }
        unset($video);

        // "Not Converted" is a real category assignment in the video's own
        // metadata, which is authoritative — drop it there and let the index
        // copy be derived from what remains.
        $categories = new VideoCategories();
        $assignments = array_values(array_filter(
            $categories->load($user, $key, $videoId),
            static fn($assignment) => $assignment['name'] !== 'Not Converted',
        ));
        $categories->save($user, $key, $videoId, $assignments, $index);

        $metadata = $datastore->loadVideoMetadata($user, $key, $videoId);

        if ($metadata !== null) {
            $datastore->saveVideoMetadata($user, $key, $videoId, VideoQuality::apply([
                ...$metadata,
                ...$measured,
                'format' => 'mp4',
                'codec' => 'hevc',
            ]));
        }

        if (!$datastore->saveIndex($user, $key, $index)) {
            error_log("MyStash convert: converted {$videoId} for {$user} but could not save the index");
            Jobs::finish($id, false, 'The video converted, but its index entry could not be saved.');

            return;
        }

        Jobs::finish($id, true, $filter !== null
            ? sprintf('Reduced to %s, %sfps.', ($measured['width'] ?? '?') . '×' . ($height ?? '?'), $measured['fps'] ?? '?')
            : 'Converted to MP4/H.265.');
    } finally {
        Datastore::wipe($workDir);
    }
}

/**
 * Build one video's hover clip after it has been uploaded, or after a trim
 * was kept — which also passes `frame_at` when the thumbnail was taken from
 * the part the trim removed, so it is retaken in the same pass.
 *
 * Split out of the upload request because it decodes the whole video, which on
 * a long one kept that request open past the reverse proxy's timeout. The
 * video, its thumbnail and its index entry already exist by the time this
 * runs; all of the work is in VideoPreview::buildClip().
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runPreview(string $id, array $record, array $secrets): void
{
    $result = (new VideoPreview())->buildClip(
        (string) $record['user'],
        (string) $secrets['key'],
        (string) $record['target'],
        static fn(string $stage) => Jobs::update($id, ['message' => $stage]),
        isset($record['frame_at']) ? (float) $record['frame_at'] : null,
    );

    if (!$result['ok']) {
        error_log("MyStash preview {$record['target']} for {$record['user']}: {$result['message']}");
    }

    Jobs::finish($id, $result['ok'], $result['message']);
}

/**
 * Make a trimmed copy of one video beside it, `{ID}_trim.mp4.epc`.
 *
 * A convert job carrying `trim_mode`, `trim_from_ms` and `trim_to_ms` in its
 * public record. The video itself is only read: VideoTrim::make() writes the
 * copy, and the user keeps or deletes it from Video Stats afterwards.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runTrim(string $id, array $record, array $secrets): void
{
    $spec = VideoTrim::spec(
        (string) $record['trim_mode'],
        (int) ($record['trim_from_ms'] ?? 0),
        isset($record['trim_to_ms']) ? (int) $record['trim_to_ms'] : null,
    );

    $result = (new VideoTrim())->make(
        (string) $record['user'],
        (string) $secrets['key'],
        (string) $record['target'],
        $spec,
        static function (float $done, float $total) use ($id): void {
            Jobs::update($id, [
                'done' => (int) round($done),
                'total' => (int) round($total),
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
            ]);
        },
        static fn(string $stage) => Jobs::update($id, ['message' => $stage]),
    );

    if (!$result['ok']) {
        error_log("MyStash trim {$record['target']} for {$record['user']}: {$result['message']}");
    }

    Jobs::finish($id, $result['ok'], $result['message']);
}

/**
 * Re-encrypt the whole stash under a new password (6.6).
 *
 * All of the re-keying itself stays in Rekey — the ordering rules, the `.old`
 * safety copies, the rollback. This only reports how far along it is.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runRekey(string $id, array $record, array $secrets): void
{
    $user = (string) $record['user'];

    Jobs::update($id, ['message' => 'Checking your current password…']);

    $result = (new Rekey())->run(
        $user,
        (string) $secrets['old_key'],
        (string) $secrets['new_password'],
        static function (int $done, int $total, string $name) use ($id): void {
            Jobs::update($id, [
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
                'message' => "Re-encrypted {$done} of {$total} archives",
            ]);
        },
    );

    if (!$result['ok']) {
        error_log("MyStash re-key failed for {$user}: " . $result['message']);
        Jobs::finish($id, false, $result['message']);

        return;
    }

    Jobs::finish($id, true, $result['message'] . ' Sign in again with your new password.');
}

/**
 * Upgrade a v1 (7z) stash to the v2 format, once (Docs/v2_migration.md).
 *
 * Started by login.php the first time the right password opens a v1 stash.
 * All of the work — and the rules that make an interruption harmless — is in
 * MigrateV2; this only reports how far along it is.
 *
 * @param array<string, mixed> $record
 * @param array<string, string> $secrets
 */
function runUpgrade(string $id, array $record, array $secrets): void
{
    $user = (string) $record['user'];

    Jobs::update($id, ['message' => 'Checking your password…']);

    $result = (new MigrateV2())->run(
        $user,
        (string) $secrets['password'],
        static function (int $done, int $total, string $name) use ($id): void {
            Jobs::update($id, [
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? min(99, (int) round($done / $total * 100)) : 0,
                'message' => "Upgraded {$done} of {$total} archives",
            ]);
        },
    );

    if (!$result['ok']) {
        error_log("MyStash v2 upgrade failed for {$user}: " . $result['message']);
    }

    Jobs::finish($id, $result['ok'], $result['ok']
        ? $result['message'] . ' Sign in again with the same password.'
        : $result['message']);
}
