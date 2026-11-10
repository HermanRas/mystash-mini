<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoStats.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoStats;

/**
 * Starts a reduction — 30fps, 1920×1080, or both — from the Stats screen.
 *
 * It is a convert job with a filter (see bin/job_worker.php), so it shares the
 * convert's job id: pressing Reduce while a Convert of the same video is
 * running finds that job rather than starting a second ffmpeg over the same
 * archive, and the other way round.
 *
 * Only the options the last inspection says apply are honoured. The form only
 * offers those, but this spawns ffmpeg over the user's only copy, so it does
 * not take the form's word for it.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: stats.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::refreshIndex();

$video = null;
foreach ($index['videos'] ?? [] as $candidate) {
    if ($candidate['id'] === $id) {
        $video = $candidate;
        break;
    }
}

if ($video === null) {
    header('Location: stats.php');
    exit;
}

$width = isset($video['width']) ? (int) $video['width'] : null;
$height = isset($video['height']) ? (int) $video['height'] : null;
$fps = isset($video['fps']) ? (float) $video['fps'] : null;

$reduceFps = !empty($_POST['fps']) && VideoStats::exceedsTargetFps($fps);
$reduceScale = !empty($_POST['scale']) && VideoStats::exceedsFullHd($width, $height);

if (!$reduceFps && !$reduceScale) {
    header('Location: stats.php?error=nothing#video-' . urlencode($id));
    exit;
}

$started = Jobs::start(
    'convert',
    Session::user(),
    $id,
    ['key' => Session::key()],
    [
        'message' => 'Starting…',
        'reduce_fps' => $reduceFps,
        'reduce_scale' => $reduceScale,
        'width' => $width,
        'height' => $height,
    ],
);

if (!$started['ok']) {
    // Already running: show its progress rather than an error.
    error_log("MyStash reduce: {$started['error']} ({$id})");
}

header('Location: stats.php#video-' . urlencode($id));
