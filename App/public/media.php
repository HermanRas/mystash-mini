<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Session.php';

use MyStash\StashCrypto;
use MyStash\Datastore;
use MyStash\Session;

require_once __DIR__ . '/../src/CreatorStore.php';

use MyStash\CreatorStore;

/**
 * Decrypt-on-the-fly media endpoint (Docs/PLAN.md Phase 4.0): serves the
 * preview thumbnail, preview clip, or full video for a given video ID, or a
 * creator's profile picture for a given creator ID.
 * Nothing is ever written back out decrypted to persistent disk — the
 * archive is extracted to tmpfs, streamed (with HTTP Range support for
 * video/mp4 seeking), then wiped.
 *
 * With `&download=1` the same bytes are sent as an attachment instead of
 * inline, which is how a video gets back out of the stash (Docs/PLAN.md 4.30).
 * Uploading with no way to retrieve was an oversight: the archives are only
 * openable with the password, so without this the only way out was the command
 * line (tools/decode.php, since v2).
 */

Session::requireLogin();

$id = (string) ($_GET['id'] ?? '');
$type = (string) ($_GET['type'] ?? '');
$download = isset($_GET['download']);

// Appended to the id to name the archive. `trim` is the copy a trim makes for
// the user to play before deciding whether to keep it (VideoTrim).
$suffixes = [
    'thumb' => ['.jpg.preview.epc', 'image/jpeg'],
    'preview' => ['.mp4.preview.epc', 'video/mp4'],
    'video' => ['.mp4.epc', 'video/mp4'],
    'trim' => ['_trim.mp4.epc', 'video/mp4'],
];

if ($type === 'avatar') {
    // Creator profile pictures are addressed by creator ID, not video ID.
    $creatorId = (string) ($_GET['creator'] ?? '');

    if ($creatorId === '' || !ctype_digit($creatorId)) {
        http_response_code(400);
        exit;
    }

    $archivePath = CreatorStore::profileImagePath(Session::user(), $creatorId);
    $contentType = 'image/png';
} else {
    // IDs are digits and go straight into a filesystem path, so anything else
    // is rejected rather than allowed to walk out of the data directory.
    if (!ctype_digit($id) || !isset($suffixes[$type])) {
        http_response_code(400);
        exit;
    }

    [$suffix, $contentType] = $suffixes[$type];
    $archivePath = Datastore::videoDir(Session::user(), $id) . "/{$id}{$suffix}";
}

if (!file_exists($archivePath)) {
    http_response_code(404);
    exit;
}

$workDir = Datastore::tmpfsWorkDir('media');
register_shutdown_function(static fn() => Datastore::wipe($workDir));

$crypto = new StashCrypto();
if (!$crypto->extract($archivePath, $workDir, Session::key())) {
    http_response_code(403);
    exit;
}

$files = glob("{$workDir}/*");
$plainPath = $files[0] ?? null;

if ($plainPath === null || !is_file($plainPath)) {
    http_response_code(500);
    exit;
}

$size = filesize($plainPath);
$start = 0;
$end = $size - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = $m[1] === '' ? $size - (int) $m[2] : (int) $m[1];
    $end = $m[2] !== '' && $m[1] !== '' ? (int) $m[2] : $size - 1;
    $start = max(0, $start);
    $end = min($size - 1, $end);
    $status = 206;
}

http_response_code($status);
header("Content-Type: {$contentType}");
header('Accept-Ranges: bytes');

if ($download) {
    // The archive stores the file under its internal id ("1.mp4"), which is
    // meaningless outside the stash — so the download is named after the
    // video's title. The index is only opened on this branch: media.php is
    // called for every thumbnail on the wall, and decrypting the index each
    // time to name a file nobody is saving would be wasteful.
    header('Content-Disposition: attachment; filename="' . downloadFilename($id, $plainPath) . '"');
}
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$fh = fopen($plainPath, 'rb');
fseek($fh, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1024 * 1024, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($fh);

/**
 * A human-meaningful filename for a downloaded video, keeping the real
 * extension the archive holds.
 *
 * Everything that is not a letter, digit, space, dash or underscore is
 * dropped: the title is the user's own text and this value goes into a
 * response header and then onto their filesystem, so neither quotes, newlines
 * nor path separators may survive it.
 */
function downloadFilename(string $id, string $plainPath): string
{
    $extension = strtolower((string) pathinfo($plainPath, PATHINFO_EXTENSION));
    $title = '';

    foreach (Session::index()['videos'] ?? [] as $video) {
        if ((string) $video['id'] === $id) {
            $title = (string) ($video['title'] ?? '');
            break;
        }
    }

    $safe = trim((string) preg_replace('/[^A-Za-z0-9 _-]+/', '', $title));
    $safe = (string) preg_replace('/\s+/', ' ', $safe);

    if ($safe === '') {
        $safe = 'video-' . $id;
    }

    return $extension === '' ? $safe : "{$safe}.{$extension}";
}
