<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoIngest.php';
require_once __DIR__ . '/../src/PictureIngest.php';
require_once __DIR__ . '/../src/Session.php';

use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\PictureIngest;
use MyStash\Session;
use MyStash\VideoIngest;

/**
 * Upload one or more videos and/or pictures (MyStashMini bulk upload).
 *
 * The form field is `media[]` and may hold any number of files. upload.js sends
 * them one per request, in sequence, with `X-Requested-With: fetch`, and gets
 * JSON back; without JS the browser posts them all at once and is redirected
 * to the wall. Either way the files are ingested one after another inside this
 * request and the index is saved after each, so a failure part-way keeps
 * everything before it.
 *
 * One at a time is not a convenience: every upload rewrites the whole index, so
 * two requests running side by side would each save a copy missing the other's
 * entry.
 */

Session::requireLogin();

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$respond = static function (array $added, array $errors) use ($wantsJson): never {
    if ($wantsJson) {
        http_response_code($added === [] && $errors !== [] ? 422 : 200);
        header('Content-Type: application/json');
        echo json_encode(['added' => $added, 'errors' => $errors]);
        exit;
    }

    header('Location: wall.php' . ($errors === [] ? '' : '?upload_error=' . urlencode(implode(' ', $errors))));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

// Normalise `media[]` (and the original single `video` field) into a list.
$files = [];
foreach (['media', 'video'] as $field) {
    $f = $_FILES[$field] ?? null;
    if ($f === null) {
        continue;
    }
    if (is_array($f['name'])) {
        foreach (array_keys($f['name']) as $i) {
            $files[] = [
                'name' => (string) $f['name'][$i],
                'tmp_name' => (string) $f['tmp_name'][$i],
                'error' => (int) $f['error'][$i],
            ];
        }
    } else {
        $files[] = ['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'error' => (int) $f['error']];
    }
}

$files = array_values(array_filter($files, static fn(array $f) => $f['error'] !== UPLOAD_ERR_NO_FILE));

if ($files === []) {
    // An empty $_FILES on a POST that clearly carried a body means PHP threw it
    // away for exceeding post_max_size.
    $respond([], [(int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        ? 'That upload is larger than the upload limit.'
        : 'No file was received.']);
}

$previewAt = isset($_POST['preview_at']) && $_POST['preview_at'] !== ''
    ? (float) $_POST['preview_at']
    : null;

$added = [];
$errors = [];

foreach ($files as $file) {
    $name = $file['name'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "{$name}: " . ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'larger than the upload limit.'
            : 'the upload did not complete.');
        continue;
    }

    // Re-read before every file: another tab may have written in between.
    $index = Session::refreshIndex();
    $isPicture = PictureIngest::detect($file['tmp_name']) !== null;
    // Read now: ingestion moves the temp file away, success or not.
    $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    // Ingestion throws when it cannot make a wall tile out of the file; the
    // message it wrote says why, which "upload failed" would not.
    try {
        $entry = $isPicture
            ? (new PictureIngest())->ingest(Session::user(), Session::password(), $file['tmp_name'], $name, $index)
            : (new VideoIngest())->ingest(Session::user(), Session::password(), $file['tmp_name'], $name, $index, $previewAt);
    } catch (\RuntimeException $e) {
        // A file that is neither a picture we know nor a video ffmpeg can read
        // is usually not media at all — a failed download saved as .jpg is
        // really an HTML or JSON error page. Say what it actually is rather
        // than blaming a "video" the user never uploaded.
        $errors[] = !$isPicture && !str_starts_with($mime, 'video/')
            ? "{$name}: not a picture or video (the file contains {$mime}) — probably a failed download."
            : "{$name}: {$e->getMessage()}";
        continue;
    }

    $index['videos'][] = $entry;
    (new Datastore())->saveIndex(Session::user(), Session::password(), $index);
    Session::setIndex($index);
    $added[] = ['id' => $entry['id'], 'title' => $entry['title'], 'kind' => $isPicture ? 'picture' : 'video'];

    // Videos only: the hover clip decodes the whole file, so it is a detached
    // job started once the entry is saved (see VideoPreview::buildClip).
    if (!$isPicture) {
        $started = Jobs::start(
            'preview',
            Session::user(),
            (string) $entry['id'],
            ['password' => Session::password()],
            ['message' => 'Starting…'],
        );

        if (!$started['ok']) {
            error_log("MyStash upload: preview job not started: {$started['error']} ({$entry['id']})");
        }
    }
}

$respond($added, $errors);
