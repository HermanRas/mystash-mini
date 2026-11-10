<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Session;

/**
 * Records a view (Docs/PLAN.md 5.5). Called by the watch page the first time
 * playback starts, not on page load — opening a video without watching it
 * shouldn't count.
 *
 * The count lives on the index entry (so the wall can render it without
 * decrypting every video's metadata) and on the per-video metadata, which is
 * authoritative.
 */

Session::requireLogin();

$id = (string) ($_POST['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ctype_digit($id)) {
    http_response_code(400);
    exit;
}

$index = Session::refreshIndex();
$views = null;

foreach ($index['videos'] as &$video) {
    if ($video['id'] === $id) {
        $views = ((int) ($video['views'] ?? 0)) + 1;
        $video['views'] = $views;
        break;
    }
}
unset($video);

if ($views === null) {
    http_response_code(404);
    exit;
}

$datastore = new Datastore();

$metadata = $datastore->loadVideoMetadata(Session::user(), Session::key(), $id);
if ($metadata !== null) {
    $metadata['views'] = $views;
    $datastore->saveVideoMetadata(Session::user(), Session::key(), $id, $metadata);
}

$datastore->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

header('Content-Type: application/json');
echo json_encode(['views' => $views]);
