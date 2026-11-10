<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoPreview.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoPreview;

/**
 * Changes a video's preview image (Docs/PLAN.md 3.9), from the edit screen.
 *
 * Two ways in, one endpoint, because they are the same operation with
 * different sources for the picture: `source=timestamp` grabs a frame from the
 * video, `source=upload` takes an image the user chose. `source=clip` is the
 * odd one out: it rebuilds the hover clip rather than the image, and only
 * starts the job that does it — the same one upload.php starts, retried by
 * hand when that one failed.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');

$back = static function (string $query) use ($id): never {
    header('Location: video.php?id=' . urlencode($id) . '&edit=1&' . $query);
    exit;
};

// The id names a directory, so it is checked against the index rather than
// trusted from the form — the same guard video_convert.php uses. A video the
// session's own index does not list is not this user's to re-preview.
$known = false;

foreach (Session::refreshIndex()['videos'] ?? [] as $video) {
    if ((string) $video['id'] === $id) {
        $known = true;
        break;
    }
}

if (!$known) {
    header('Location: wall.php');
    exit;
}

$preview = new VideoPreview();

$result = match ((string) ($_POST['source'] ?? '')) {
    'upload' => (function () use ($preview, $id): array {
        $file = $_FILES['preview_image'] ?? null;

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            // The commonest of these by far is a file over the upload limit,
            // which otherwise arrives as a silent no-op.
            return [
                'ok' => false,
                'message' => ($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE
                    ? 'That image is too large.'
                    : 'No image was received.',
            ];
        }

        return $preview->fromUpload(Session::user(), Session::key(), $id, $file['tmp_name']);
    })(),

    'timestamp' => $preview->fromTimestamp(
        Session::user(),
        Session::key(),
        $id,
        (float) ($_POST['preview_at'] ?? 0),
    ),

    'clip' => (function () use ($id): array {
        $started = Jobs::start('preview', Session::user(), $id, ['key' => Session::key()]);

        return $started['ok']
            ? ['ok' => true, 'message' => 'Building the hover preview in the background.']
            : ['ok' => false, 'message' => $started['error']];
    })(),

    default => ['ok' => false, 'message' => 'Nothing to do.'],
};

$back(($result['ok'] ? 'preview=' : 'preview_error=') . urlencode($result['message']));
