<?php

declare(strict_types=1);

/**
 * Sets a video's view count, writing both places the count lives: the index
 * entry (what the wall renders) and the per-video metadata (authoritative).
 *
 * This exists because the browser tests drive the real view endpoint, which
 * inflates the count on a real stash. Rather than hand-editing an encrypted
 * archive afterwards, put the number back with this.
 *
 * Usage (inside the container):
 *   php bin/set_views.php {user} {password} {video-id} {count}
 */

require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/StashKey.php';

use MyStash\Datastore;
use MyStash\StashKey;

[$self, $user, $password, $id, $count] = array_pad($argv, 5, null);

if ($user === null || $password === null || $id === null || $count === null) {
    fwrite(STDERR, "Usage: php bin/set_views.php {user} {password} {video-id} {count}\n");
    exit(1);
}

if (!ctype_digit((string) $id) || !ctype_digit((string) $count)) {
    fwrite(STDERR, "Video id and count must both be whole numbers.\n");
    exit(1);
}

$count = (int) $count;
$datastore = new Datastore();

// The archives are encrypted with a key derived from the password (StashKey).
$key = StashKey::unlock((string) $user, (string) $password);

$index = $key !== null ? $datastore->loadIndex($user, $key) : null;
if ($index === null) {
    fwrite(STDERR, "Could not decrypt the index for {$user} — wrong password?\n");
    exit(1);
}

$found = false;
foreach ($index['videos'] as &$video) {
    if ((string) $video['id'] === (string) $id) {
        printf("index: %s — %d views -> %d\n", $video['title'], (int) ($video['views'] ?? 0), $count);
        $video['views'] = $count;
        $found = true;
        break;
    }
}
unset($video);

if (!$found) {
    fwrite(STDERR, "No video with id {$id} in {$user}'s index.\n");
    exit(1);
}

$metadata = $datastore->loadVideoMetadata($user, $key, (string) $id);
if ($metadata !== null) {
    printf("metadata: %d views -> %d\n", (int) ($metadata['views'] ?? 0), $count);
    $metadata['views'] = $count;
    $datastore->saveVideoMetadata($user, $key, (string) $id, $metadata);
}

$datastore->saveIndex($user, $key, $index);

echo "Done.\n";
