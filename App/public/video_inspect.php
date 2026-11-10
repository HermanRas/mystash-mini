<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/VideoQuality.php';

use MyStash\StashCrypto;
use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoEncoder;
use MyStash\VideoQuality;

/**
 * The Stats screen's Inspect button: measure one video's frame rate and pixel
 * size, and remember them in the index so the screen can show them — and offer
 * a reduction — without decrypting it again.
 *
 * It has to decrypt the whole video to tmpfs to do it. An MP4 whose index is
 * at the end needs the end anyway, and the archive is one authenticated
 * stream that is only trusted once its FINAL chunk has checked out. That
 * is the same cost as pressing play, so it runs in the request — but with the
 * session lock released first: a big video takes a few seconds to decrypt,
 * and without this every other page in that browser would queue behind it.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: stats.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$user = Session::user();
$key = Session::key();
$index = Session::refreshIndex();

$known = false;
foreach ($index['videos'] ?? [] as $video) {
    if ($video['id'] === $id) {
        $known = true;
        break;
    }
}

if (!$known || !Datastore::isValidId($id)) {
    header('Location: stats.php');
    exit;
}

// The archive is about to be replaced by a running convert or reduction;
// measuring the old one would only describe a file that is on its way out.
$job = Jobs::read(Jobs::id('convert', $user, $id));
if ($job !== null && $job['state'] === Jobs::RUNNING) {
    header('Location: stats.php?error=busy#video-' . urlencode($id));
    exit;
}

session_write_close();

$archivePath = Datastore::videoDir($user, $id) . "/{$id}.mp4.epc";
$workDir = Datastore::tmpfsWorkDir('inspect');
$probe = null;
$seconds = null;

try {
    if (is_file($archivePath) && (new StashCrypto())->extract($archivePath, $workDir, $key)) {
        $plainPath = (glob("{$workDir}/*") ?: [])[0] ?? null;

        if ($plainPath !== null && is_file($plainPath)) {
            $encoder = new VideoEncoder();
            $probe = $encoder->probe($plainPath);
            $seconds = $encoder->durationSeconds($plainPath);
        }
    }
} finally {
    Datastore::wipe($workDir);
}

if ($probe === null || $probe['width'] === null || $probe['height'] === null) {
    header('Location: stats.php?error=inspect#video-' . urlencode($id));
    exit;
}

$measured = [
    'width' => $probe['width'],
    'height' => $probe['height'],
    'fps' => $probe['fps'],
    'inspected_at' => time(),
    // Exact, for Trim. Videos uploaded before it was recorded only know their
    // length to the second until they are inspected.
    ...($seconds !== null ? ['duration_ms' => (int) floor($seconds * 1000)] : []),
];

// Re-read rather than reuse the copy from before the decrypt: that took
// seconds, and another tab may have saved the index in the meantime.
$datastore = new Datastore();
$index = $datastore->loadIndex($user, $key);

if ($index === null) {
    header('Location: stats.php?error=inspect#video-' . urlencode($id));
    exit;
}

foreach ($index['videos'] as &$video) {
    if ($video['id'] === $id) {
        // The height feeds the quality badge, so a measurement that corrects
        // it corrects the badge too.
        $video = VideoQuality::apply([...$video, ...$measured]);
        break;
    }
}
unset($video);

$metadata = $datastore->loadVideoMetadata($user, $key, $id);
if ($metadata !== null) {
    $datastore->saveVideoMetadata($user, $key, $id, VideoQuality::apply([...$metadata, ...$measured]));
}

$datastore->saveIndex($user, $key, $index);

header('Location: stats.php?inspected=' . urlencode($id) . '#video-' . urlencode($id));
