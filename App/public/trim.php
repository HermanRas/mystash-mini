<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoTrim.php';
require_once __DIR__ . '/../views/format.php';

use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoTrim;

/**
 * Trim one video, reached from its row on Video Stats.
 *
 * With no trimmed copy yet this asks what to remove — Start, End or a Cut out
 * of the middle, in milliseconds — and video_trim.php starts the job. Once the
 * job has made a copy, the page plays it instead and offers Keep or Delete;
 * there is no second trim until the first copy is one or the other.
 */

Session::requireLogin();

$user = Session::user();
$id = (string) ($_GET['id'] ?? '');

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

$job = Jobs::read(Jobs::id('convert', $user, $id));
$running = $job !== null && $job['state'] === Jobs::RUNNING;
$hasTrim = VideoTrim::exists($user, $id);

// Exact when the upload, an inspection or a conversion measured it; otherwise
// the index only knows the second, and the worker checks the real length.
$durationMs = isset($video['duration_ms']) ? (int) $video['duration_ms'] : null;
$lengthLabel = $durationMs !== null
    ? VideoTrim::formatMs($durationMs)
    : 'about ' . formatLength((int) ($video['length_seconds'] ?? 0));

// What the copy is, from the video's metadata. Only opened when there is one.
$trim = $hasTrim
    ? ((new Datastore())->loadVideoMetadata($user, Session::key(), $id)['trim'] ?? null)
    : null;

$error = (string) ($_GET['error'] ?? '');
$h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES);

$navActive = '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Trim</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="manage-layout">
  <h1 class="page-title">Trim</h1>
  <p class="hint trim-subject">
    <a href="video.php?id=<?= urlencode($id) ?>"><?= $h((string) $video['title']) ?></a>
    · <?= $h($lengthLabel) ?>
  </p>

  <?php if ($error !== ''): ?>
    <p class="notice bad"><?= $h($error) ?></p>
  <?php endif; ?>

  <?php if ($running): ?>
    <div class="card">
      <p class="hint" style="margin:0;">
        <?= $h((string) $job['message']) ?> Something is running on this video —
        <a href="stats.php#video-<?= urlencode($id) ?>">follow it on Video Stats</a>.
      </p>
    </div>

  <?php elseif ($hasTrim): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;">Trimmed copy</div>
      <video class="trim-player" controls preload="metadata"
             src="media.php?id=<?= urlencode($id) ?>&amp;type=trim"></video>

      <?php if (is_array($trim)): ?>
        <p class="hint">
          <?= $h(VideoTrim::describe($trim)) ?>.
          Now <?= $h(VideoTrim::formatMs((int) $trim['duration_ms'])) ?> long, was <?= $h($lengthLabel) ?>.
        </p>
      <?php endif; ?>

      <div class="trim-actions">
        <?php if (is_array($trim)): ?>
          <form action="video_trim.php" method="post"
                onsubmit="return confirm('Replace the video with the trimmed copy?\n\nThe trimmed-off part is gone for good once you do.');">
            <input type="hidden" name="id" value="<?= $h($id) ?>">
            <input type="hidden" name="action" value="keep">
            <button type="submit" class="btn">Keep</button>
          </form>
        <?php endif; ?>
        <form action="video_trim.php" method="post">
          <input type="hidden" name="id" value="<?= $h($id) ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn secondary">Delete</button>
        </form>
      </div>
      <p class="hint" style="margin-bottom:0;">
        Keep replaces the video with this copy, under the same title, and moves
        its categories and preview to match. Delete throws the copy away and
        leaves the video exactly as it was.
      </p>
    </div>

  <?php else: ?>
    <form action="video_trim.php" method="post" class="card trim-form">
      <input type="hidden" name="id" value="<?= $h($id) ?>">
      <input type="hidden" name="action" value="start">

      <div class="field">
        <label for="trim-mode">Remove</label>
        <select id="trim-mode" name="mode">
          <option value="start">Start</option>
          <option value="end">End</option>
          <option value="cut">Cut</option>
        </select>
      </div>

      <?php /* One row per mode. Without JS all three show and the server
               reads only the selected mode's fields. */ ?>
      <div class="field trim-range" data-mode="start">
        <span>from 0ms to</span>
        <input type="number" name="start_to" min="1" step="1" inputmode="numeric" required>
        <span>ms</span>
        <output class="hint"></output>
      </div>

      <div class="field trim-range" data-mode="end">
        <span>from</span>
        <input type="number" name="end_from" min="1" step="1" inputmode="numeric" required>
        <span>ms to <?= $h($lengthLabel) ?></span>
        <output class="hint"></output>
      </div>

      <div class="field trim-range" data-mode="cut">
        <span>from</span>
        <input type="number" name="cut_from" min="1" step="1" inputmode="numeric" required>
        <span>ms to</span>
        <input type="number" name="cut_to" min="1" step="1" inputmode="numeric" required>
        <span>ms</span>
        <output class="hint"></output>
      </div>

      <button type="submit" class="btn">Trim</button>

      <p class="hint" style="margin-bottom:0;">
        This makes a trimmed copy in the background and leaves the video as it
        is. When the copy is ready, play it here or from Video Stats, then keep
        it or delete it. The copy is re-encoded so the cut lands on the exact
        millisecond, which takes about as long as a conversion.
      </p>
    </form>
  <?php endif; ?>

  <p class="hint"><a href="stats.php#video-<?= urlencode($id) ?>">Back to Video Stats</a></p>
</main>

<script>
(function () {
  const form = document.querySelector('.trim-form');
  if (!form) return;

  const mode = form.querySelector('#trim-mode');
  const rows = form.querySelectorAll('.trim-range');

  // Milliseconds are exact but hard to read past a few seconds, so each row
  // says what it was typed as: 723029 → 12m3s29ms. Same shape as
  // VideoTrim::formatMs().
  const format = (ms) => {
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    const s = Math.floor((ms % 60000) / 1000);
    let out = '';
    if (h) out += h + 'h';
    if (out || m) out += m + 'm';
    if (out || s) out += s + 's';
    return out + (ms % 1000) + 'ms';
  };

  // The hidden rows' inputs are disabled, not just hidden: a required field
  // the user cannot see would otherwise block the submit.
  const show = () => {
    rows.forEach((row) => {
      const on = row.dataset.mode === mode.value;
      row.hidden = !on;
      row.querySelectorAll('input').forEach((input) => { input.disabled = !on; });
    });
  };

  rows.forEach((row) => {
    const output = row.querySelector('output');
    const inputs = row.querySelectorAll('input');
    const describe = () => {
      output.textContent = Array.from(inputs)
        .map((input) => (input.value === '' ? '' : format(Number(input.value))))
        .filter(Boolean)
        .join(' to ');
    };
    inputs.forEach((input) => input.addEventListener('input', describe));
  });

  mode.addEventListener('change', show);
  show();
})();
</script>
</body>
</html>
