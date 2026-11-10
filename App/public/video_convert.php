<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\Jobs;
use MyStash\Session;

/**
 * Starts a conversion. Does not perform one (Docs/PLAN.md 4.28).
 *
 * This used to run ffmpeg inside the request. On the single-threaded built-in
 * server that did not merely make this one request slow — every other page,
 * thumbnail and range request queued behind the transcode, and the whole site
 * stopped answering until the container was restarted. Now the work goes to a
 * detached worker and this returns straight away; the watch page polls
 * job_status.php and draws a bar.
 *
 * Pressing Convert twice cannot start a second run: the job id is derived from
 * the user and the video, so the second press finds the first job.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::refreshIndex();

// Only convert something the stash actually holds — this spawns a process, so
// it does not get to be driven by an arbitrary id from a form post.
$known = false;
foreach ($index['videos'] ?? [] as $video) {
    if ($video['id'] === $id) {
        $known = true;
        break;
    }
}

if (!$known) {
    header('Location: wall.php');
    exit;
}

$started = Jobs::start(
    'convert',
    Session::user(),
    $id,
    ['key' => Session::key()],
    ['message' => 'Starting…'],
);

// A refusal here means a conversion of this video is already running, which is
// what the user wanted anyway — send them to the page showing its progress.
if (!$started['ok']) {
    error_log("MyStash convert: {$started['error']} ({$id})");
}

header('Location: video.php?id=' . urlencode($id) . '&converting=1');
