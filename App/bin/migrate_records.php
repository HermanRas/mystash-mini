<?php

declare(strict_types=1);

/**
 * One-off migration for stashes created before creators had their own records
 * and before quality was a derived tag.
 *
 *  - gives every creator an ID and writes their Creator{ID}/{ID}.json.epc
 *  - backfills each video's `height` (measured from the real file where one
 *    exists, otherwise inferred from the legacy quality badge) and recomputes
 *    `quality` / `not_converted` from it
 *  - backfills `uploaded_at` on index entries from the per-video metadata
 *  - converts each video's single `creator` into a `creators` list
 *  - drops the "Most Recent" category, which was never a category — it is a
 *    sort order, and now lives in the wall's sort menu
 *
 * Safe to re-run. Usage (inside the container):
 *   php bin/migrate_records.php {user} {password}
 */

require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/StashKey.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/CreatorStore.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/VideoQuality.php';
require_once __DIR__ . '/../src/VideoCreators.php';

use MyStash\StashCrypto;
use MyStash\StashKey;
use MyStash\Datastore;
use MyStash\VideoEncoder;
use MyStash\VideoCreators;
use MyStash\VideoQuality;

[$user, $password] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($user === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/migrate_records.php {user} {password}\n");
    exit(1);
}

$datastore = new Datastore();
$key = StashKey::unlock($user, $password);
$crypto = new StashCrypto();
$encoder = new VideoEncoder();

$index = $key !== null ? $datastore->loadIndex($user, $key) : null;
if ($index === null) {
    fwrite(STDERR, "Could not decrypt {$user}'s index with that password.\n");
    exit(1);
}

// --- creators ------------------------------------------------------------
$nextId = (int) Datastore::nextCreatorId($index);

foreach ($index['creators'] as $name => $creator) {
    $id = (string) ($creator['id'] ?? '');
    if ($id === '') {
        $id = (string) $nextId++;
    }

    $record = [
        'id' => $id,
        'name' => $creator['name'] ?? $name,
        'age' => $creator['age'] ?? null,
        'gender' => $creator['gender'] ?? null,
        'bio' => $creator['bio'] ?? '',
        'created_at' => $creator['created_at'] ?? date('c'),
    ];

    $datastore->saveCreatorMetadata($user, $key, $id, $record);
    $index['creators'][$name] = $record;

    echo "creator {$name} -> Creator{$id}/{$id}.json.epc\n";
}

// --- videos --------------------------------------------------------------
$legacyHeights = ['4K' => 2160, 'HD' => 720];

foreach ($index['videos'] as &$video) {
    $id = (string) $video['id'];
    $metadata = $datastore->loadVideoMetadata($user, $key, $id);

    $height = $video['height'] ?? $metadata['height'] ?? null;

    // A real encrypted file is the only authoritative source for the height,
    // so measure it where one exists rather than trusting the old badge.
    $archive = Datastore::videoDir($user, $id) . "/{$id}.mp4.epc";
    if ($height === null && file_exists($archive)) {
        $workDir = Datastore::tmpfsWorkDir('migrate');
        try {
            if ($crypto->extract($archive, $workDir, $key)) {
                $plain = glob("{$workDir}/*")[0] ?? null;
                $height = $plain !== null ? $encoder->videoHeight($plain) : null;
            }
        } finally {
            Datastore::wipe($workDir);
        }
    }

    $height ??= $legacyHeights[$video['quality'] ?? ''] ?? null;

    $video['height'] = $height;
    $video['uploaded_at'] = $video['uploaded_at'] ?? $metadata['uploaded_at'] ?? null;
    $video = VideoCreators::set($video, VideoCreators::of($video));
    $video = VideoQuality::apply($video);

    if ($metadata !== null) {
        $metadata = VideoCreators::set($metadata, VideoCreators::of($metadata));
        $datastore->saveVideoMetadata($user, $key, $id, VideoQuality::apply([
            ...$metadata,
            'height' => $height,
        ]));
    }

    printf("video %s: height=%s quality=%s not_converted=%s creators=%s\n",
        $id, $height ?? 'unknown', $video['quality'] ?? 'none',
        $video['not_converted'] ? 'yes' : 'no', implode('+', $video['creators']));
}
unset($video);

// --- categories ----------------------------------------------------------
if (isset($index['categories']['Most Recent'])) {
    unset($index['categories']['Most Recent']);
    echo "dropped the \"Most Recent\" category — it is a sort order, not a category\n";
}

if (!$datastore->saveIndex($user, $key, $index)) {
    fwrite(STDERR, "Failed to write the index.\n");
    exit(1);
}

echo "\nMigration complete for {$user}.\n";
exit(0);
