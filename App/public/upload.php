<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoIngest.php';
require_once __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoIngest;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$file = $_FILES['video'] ?? null;
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    header('Location: wall.php?upload_error=' . urlencode(
        ($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE
            ? 'That file is larger than the upload limit.'
            : 'No video was received.',
    ));
    exit;
}

$previewAt = isset($_POST['preview_at']) && $_POST['preview_at'] !== ''
    ? (float) $_POST['preview_at']
    : null;

// An optional picture to use as the preview instead of a frame from the video
// (3.9). Unlike the video itself a missing or failed one is not fatal —
// ingestion falls back to the capture time.
$previewImage = $_FILES['preview_image'] ?? null;
$previewImagePath = ($previewImage !== null && $previewImage['error'] === UPLOAD_ERR_OK)
    ? $previewImage['tmp_name']
    : null;

$index = Session::refreshIndex();

// Ingestion throws when the file is not something it can make a wall tile
// out of. Uncaught, that was a blank 500 from a button press — and a stack
// trace on the page in any build with display_errors on. The message is the
// one ingestion wrote, because "No frame could be read from this video" tells
// the user what to do and "upload failed" does not.
try {
    $entry = (new VideoIngest())->ingest(
        Session::user(),
        Session::key(),
        $file['tmp_name'],
        $file['name'],
        $index,
        $previewAt,
        $previewImagePath,
    );
} catch (\RuntimeException $e) {
    header('Location: wall.php?upload_error=' . urlencode($e->getMessage()));
    exit;
}

$index['videos'][] = $entry;

(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

// The hover clip decodes the whole video, which is what used to keep this
// request open long enough for the reverse proxy to give up and answer 504
// over an upload that had in fact worked. It is a detached job now, started
// only once the entry is saved so the worker always finds the video it is
// building for. If it fails the tile simply does not play on hover, and the
// edit screen offers to try again.
$started = Jobs::start(
    'preview',
    Session::user(),
    (string) $entry['id'],
    ['key' => Session::key()],
    ['message' => 'Starting…'],
);

if (!$started['ok']) {
    error_log("MyStash upload: preview job not started: {$started['error']} ({$entry['id']})");
}

header('Location: wall.php');
