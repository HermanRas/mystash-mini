<?php

declare(strict_types=1);

/**
 * One-off migration to the category-assignment model.
 *
 * Before: a video's index entry carried `categories` (plain names) and `tags`
 * (label + color + timestamp) as two separate concepts.
 * After:  categories are global definitions (name + color) in {user}.json, and
 *         a video references them with a timestamp in its own {ID}.json — the
 *         same category may appear several times at different timestamps.
 *
 * It also prunes index entries that have no media behind them (no
 * Video{ID}/{ID}.mp4.epc and no existing metadata archive) — that clears
 * orphans left when a video's files were deleted but its index entry was
 * restored by a session writing back a stale copy of the index.
 *
 * Usage (inside the container): php bin/migrate_categories.php <user> <password>
 */

require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/VideoCreators.php';
require_once __DIR__ . '/../src/VideoCategories.php';

require_once __DIR__ . '/../src/StashKey.php';

use MyStash\Datastore;
use MyStash\StashKey;

$user = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($user === null || $password === null) {
    fwrite(STDERR, "Usage: php bin/migrate_categories.php <user> <password>\n");
    exit(1);
}

$datastore = new Datastore();
$key = StashKey::unlock($user, $password);
$index = $key !== null ? $datastore->loadIndex($user, $key) : null;

if ($index === null) {
    fwrite(STDERR, "Could not decrypt the index for {$user} — wrong password or missing datastore.\n");
    exit(1);
}

$keptVideos = [];

foreach ($index['videos'] as $video) {
    $id = $video['id'];
    $dir = Datastore::videoDir($user, $id);
    $hasMedia = file_exists("{$dir}/{$id}.mp4.epc");
    $metadata = $datastore->loadVideoMetadata($user, $key, $id);

    if (!$hasMedia && $metadata === null) {
        echo "pruned  id={$id} ({$video['title']}) — no media, no metadata\n";
        continue;
    }

    // Old plain-name categories become assignments at 00:00:00.
    $assignments = [];
    foreach ($video['categories'] ?? [] as $name) {
        $assignments[] = ['name' => $name, 'timestamp_seconds' => 0];
    }

    // Old timestamp tags become assignments too; their label becomes a global
    // category (keeping the tag's colour) if it isn't one already.
    foreach (array_merge($video['tags'] ?? [], $metadata['tags'] ?? []) as $tag) {
        $name = $tag['label'];

        if (!isset($index['categories'][$name])) {
            $index['categories'][$name] = $tag['color'] ?? '#5599ff';
            echo "category id={$id} registered '{$name}' as a global category\n";
        }

        $assignments[] = ['name' => $name, 'timestamp_seconds' => (int) $tag['timestamp_seconds']];
    }

    usort($assignments, static fn($a, $b) => $a['timestamp_seconds'] <=> $b['timestamp_seconds']);

    $metadata ??= [
        'id' => $id,
        'title' => $video['title'],
        'description' => $video['description'] ?? '',
        'creators' => MyStash\VideoCreators::of($video),
        'length_seconds' => $video['length_seconds'],
        'views' => $video['views'],
        'format' => $video['format'],
        'codec' => $video['codec'],
        'not_converted' => $video['not_converted'],
        'uploaded_at' => null,
        'preview_capture_seconds' => null,
    ];

    unset($metadata['tags']);
    $metadata['categories'] = $assignments;
    $datastore->saveVideoMetadata($user, $key, $id, $metadata);

    unset($video['tags']);
    $video['categories'] = Datastore::categoryNames($assignments);
    $keptVideos[] = $video;

    echo "migrated id={$id} ({$video['title']}) — " . count($assignments) . " category assignment(s)\n";
}

$index['videos'] = $keptVideos;

if (!$datastore->saveIndex($user, $key, $index)) {
    fwrite(STDERR, "Failed to write the migrated index.\n");
    exit(1);
}

echo "\nDone: " . count($keptVideos) . " video(s) in the index.\n";
