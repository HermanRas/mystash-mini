<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoTrim.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoTrim;

/**
 * Trim, from the Video Stats screen: starts a trim, or keeps or deletes the
 * trimmed copy one made (see VideoTrim).
 *
 *  - `action=start` checks the range and starts a convert job carrying it.
 *    The job writes `{ID}_trim.mp4.epc`; the video itself is not touched.
 *  - `action=keep` swaps the trimmed copy in for the video, then starts a
 *    preview job, because the hover clip is still a timelapse of the old one.
 *  - `action=delete` throws the trimmed copy away.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: stats.php');
    exit;
}

$user = Session::user();
$id = (string) ($_POST['id'] ?? '');
$action = (string) ($_POST['action'] ?? '');

// The id names a directory and this spawns ffmpeg, so it has to be a video
// this stash lists — the same guard every other job endpoint makes.
$video = null;
foreach (Session::refreshIndex()['videos'] ?? [] as $candidate) {
    if ((string) $candidate['id'] === $id) {
        $video = $candidate;
        break;
    }
}

if ($video === null) {
    header('Location: stats.php');
    exit;
}

$toStats = static function (string $query) use ($id): never {
    header('Location: stats.php?' . $query . '#video-' . urlencode($id));
    exit;
};

$toForm = static function (string $error) use ($id): never {
    header('Location: trim.php?id=' . urlencode($id) . '&error=' . urlencode($error));
    exit;
};

$running = static fn(string $kind): bool => (Jobs::read(Jobs::id($kind, $user, $id))['state'] ?? '') === Jobs::RUNNING;

// A trim, a convert and a reduction all replace or read the same archive, and
// a preview job is still reading it. One thing at a time.
if ($running('convert') || ($action === 'keep' && $running('preview'))) {
    $action === 'start'
        ? $toForm('Something is already running on this video. Wait for it to finish.')
        : $toStats('trim_error=' . urlencode('Something is still running on this video. Wait for it to finish.'));
}

if ($action === 'start') {
    if (VideoTrim::exists($user, $id)) {
        $toForm('This video already has a trimmed copy. Keep or delete it first.');
    }

    $mode = (string) ($_POST['mode'] ?? '');
    $ms = static function (string $field): ?int {
        $value = trim((string) ($_POST[$field] ?? ''));

        return ctype_digit($value) ? (int) $value : null;
    };

    [$from, $to] = match ($mode) {
        'start' => [0, $ms('start_to')],
        'end' => [$ms('end_from'), null],
        'cut' => [$ms('cut_from'), $ms('cut_to')],
        default => [null, null],
    };

    if (!in_array($mode, VideoTrim::MODES, true) || $from === null || ($mode !== 'end' && $to === null)) {
        $toForm('Enter the times as whole milliseconds.');
    }

    // Checked here against what the index knows so a plain mistake is told at
    // once, and again by the worker against the decrypted file.
    $spec = VideoTrim::spec($mode, $from, $to);
    $problem = VideoTrim::validate($spec, isset($video['duration_ms']) ? (int) $video['duration_ms'] : null);

    if ($problem !== null) {
        $toForm($problem);
    }

    $started = Jobs::start(
        'convert',
        $user,
        $id,
        ['key' => Session::key()],
        [
            'message' => 'Starting…',
            'trim_mode' => $spec['mode'],
            'trim_from_ms' => $spec['from_ms'],
            'trim_to_ms' => $spec['to_ms'],
        ],
    );

    if (!$started['ok']) {
        $toForm($started['error']);
    }

    $toStats('trimming=1');
}

$trim = new VideoTrim();

if ($action === 'keep') {
    $result = $trim->keep($user, Session::key(), $id);

    // Once the swap has happened the hover clip shows the old video, whatever
    // the bookkeeping after it managed — so rebuild it whenever the copy has
    // gone, and retake the thumbnail if it came from the part that was cut.
    if (!VideoTrim::exists($user, $id)) {
        Jobs::start(
            'preview',
            $user,
            $id,
            ['key' => Session::key()],
            $result['frame_at'] !== null ? ['frame_at' => $result['frame_at']] : [],
        );
    }

    $toStats(($result['ok'] ? 'trim=' : 'trim_error=') . urlencode($result['message']));
}

if ($action === 'delete') {
    $result = $trim->discard($user, Session::key(), $id);
    $toStats(($result['ok'] ? 'trim=' : 'trim_error=') . urlencode($result['message']));
}

header('Location: stats.php');
