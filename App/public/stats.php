<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoStats.php';
require_once __DIR__ . '/../src/VideoTrim.php';
require_once __DIR__ . '/../views/format.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\VideoStats;
use MyStash\VideoTrim;

/**
 * Stats: what each video costs on disk, largest first, with an Inspect button
 * that measures its frame rate and pixel size, and — once measured — an offer
 * to bring it down to 30fps and/or 1920×1080.
 *
 * Sizes are read off the filesystem on every load rather than stored: they are
 * a stat() per file, and a stored number would go stale the first time a
 * conversion or a new preview changed it.
 *
 * Trim opens trim.php. Once a trim has made its copy, the row offers that copy
 * instead — play, keep or delete — and no second Trim until it is settled. Frame rate and pixel size cost a full
 * decrypt to learn, so those *are* stored, by video_inspect.php and by every
 * conversion, and shown from the index.
 */

Session::requireLogin();

$user = Session::user();
$index = Session::refreshIndex();

$rows = [];
$totalBytes = 0;
$runningJob = null;

foreach ($index['videos'] ?? [] as $video) {
    // Pictures have nothing to inspect, reduce or trim.
    if (($video['kind'] ?? 'video') === 'picture') {
        continue;
    }

    $id = (string) $video['id'];
    $bytes = VideoStats::diskBytes($user, $id);
    $totalBytes += $bytes;

    $job = Jobs::read(Jobs::id('convert', $user, $id));
    $running = $job !== null && $job['state'] === Jobs::RUNNING;

    // One progress card at the top, for the first running job: job.js drives
    // a single card, and the rows say "Working…" for any others.
    if ($running && $runningJob === null) {
        $runningJob = $job + ['title' => (string) $video['title']];
    }

    $width = isset($video['width']) ? (int) $video['width'] : null;
    $height = isset($video['height']) ? (int) $video['height'] : null;
    $fps = isset($video['fps']) ? (float) $video['fps'] : null;

    $rows[] = [
        'id' => $id,
        'title' => (string) $video['title'],
        'length' => (int) ($video['length_seconds'] ?? 0),
        'bytes' => $bytes,
        'inspected' => isset($video['inspected_at']),
        'width' => $width,
        'height' => $height,
        'fps' => $fps,
        'over_fps' => VideoStats::exceedsTargetFps($fps),
        'over_size' => VideoStats::exceedsFullHd($width, $height),
        'running' => $running,
        'trimmed' => VideoTrim::exists($user, $id),
        'failed' => $job !== null && $job['state'] === Jobs::FAILED ? (string) $job['message'] : '',
    ];
}

// Largest first, then by title so equal sizes do not shuffle between loads.
usort($rows, static fn(array $a, array $b) => [$b['bytes'], $a['title']] <=> [$a['bytes'], $b['title']]);

$inspected = (string) ($_GET['inspected'] ?? '');
$trimNotice = (string) ($_GET['trim'] ?? '');
$trimError = (string) ($_GET['trim_error'] ?? '');
$error = (string) ($_GET['error'] ?? '');

$errors = [
    'busy' => 'That video is being converted right now. Inspect it once that finishes.',
    'inspect' => 'The video could not be decrypted or read, so it was not inspected.',
    'nothing' => 'Nothing to reduce: pick at least one option that applies to the video.',
];

$navActive = '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Video Stats</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="manage-layout">
  <h1 class="page-title">Video Stats</h1>

  <?php if (isset($errors[$error])): ?>
    <p class="notice bad"><?= htmlspecialchars($errors[$error], ENT_QUOTES) ?></p>
  <?php endif; ?>

  <?php if ($trimError !== ''): ?>
    <p class="notice bad"><?= htmlspecialchars($trimError, ENT_QUOTES) ?></p>
  <?php elseif ($trimNotice !== ''): ?>
    <p class="notice ok"><?= htmlspecialchars($trimNotice, ENT_QUOTES) ?></p>
  <?php endif; ?>

  <?php if ($runningJob !== null): ?>
    <div class="card job-card indeterminate" id="job-card"
         data-kind="convert" data-target="<?= htmlspecialchars((string) $runningJob['target'], ENT_QUOTES) ?>">
      <div class="section-title" style="margin-top:0;">
        <?= htmlspecialchars($runningJob['title'], ENT_QUOTES) ?>
      </div>
      <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
      <p class="hint job-message" id="job-message">
        <?= htmlspecialchars((string) $runningJob['message'], ENT_QUOTES) ?>
      </p>
      <p class="hint">
        This runs in the background — you can leave the page. The original stays
        exactly as it is until the new copy has been written and verified.
      </p>
    </div>
  <?php endif; ?>

  <div class="card stats-summary">
    <div>
      <div class="stats-figure"><?= count($rows) ?></div>
      <div class="hint">video<?= count($rows) === 1 ? '' : 's' ?></div>
    </div>
    <div>
      <div class="stats-figure"><?= htmlspecialchars(VideoStats::formatBytes($totalBytes), ENT_QUOTES) ?></div>
      <div class="hint">on disk, previews and metadata included</div>
    </div>
  </div>

  <?php if ($rows === []): ?>
    <p class="hint">No videos in the stash yet.</p>
  <?php else: ?>
    <div class="stats-scroll">
      <table class="stats-table">
        <thead>
          <tr>
            <th>Video</th>
            <th class="num">Length</th>
            <th class="num">Size on disk</th>
            <th>Frame rate &amp; size</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $rowId = htmlspecialchars($row['id'], ENT_QUOTES); ?>
            <tr id="video-<?= $rowId ?>"<?= $inspected === $row['id'] ? ' class="just-inspected"' : '' ?>>
              <td>
                <a href="video.php?id=<?= urlencode($row['id']) ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></a>
                <?php if ($row['failed'] !== '' && !$row['running']): ?>
                  <div class="hint stats-failed"><?= htmlspecialchars($row['failed'], ENT_QUOTES) ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= $row['length'] > 0 ? formatLength($row['length']) : '—' ?></td>
              <td class="num"><?= htmlspecialchars(VideoStats::formatBytes($row['bytes']), ENT_QUOTES) ?></td>
              <td>
                <?php if ($row['inspected']): ?>
                  <span class="stats-measure<?= $row['over_fps'] ? ' over' : '' ?>">
                    <?= $row['fps'] !== null ? htmlspecialchars(rtrim(rtrim(number_format($row['fps'], 2, '.', ''), '0'), '.'), ENT_QUOTES) . ' fps' : '? fps' ?>
                  </span>
                  <span class="stats-measure<?= $row['over_size'] ? ' over' : '' ?>">
                    <?= (int) $row['width'] ?>×<?= (int) $row['height'] ?>
                  </span>
                <?php else: ?>
                  <span class="hint">Not inspected</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="stats-actions">
                <?php if ($row['running']): ?>
                  <span class="hint">Working…</span>
                <?php elseif ($row['trimmed']): ?>
                  <?php /* A trimmed copy is waiting on a decision. Nothing else
                           is offered until it gets one: a Reduce or a second
                           trim now would be working on a video that is about
                           to be replaced, or not. */ ?>
                  <span class="hint">Trimmed copy ready</span>
                  <a class="btn small" href="trim.php?id=<?= urlencode($row['id']) ?>">Play</a>
                  <form action="video_trim.php" method="post" class="stats-inspect"
                        onsubmit="return confirm('Replace the video with the trimmed copy?\n\nThe trimmed-off part is gone for good once you do.');">
                    <input type="hidden" name="id" value="<?= $rowId ?>">
                    <input type="hidden" name="action" value="keep">
                    <button type="submit" class="btn secondary small">Keep</button>
                  </form>
                  <form action="video_trim.php" method="post" class="stats-inspect">
                    <input type="hidden" name="id" value="<?= $rowId ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn secondary small">Delete</button>
                  </form>
                <?php else: ?>
                  <?php if ($row['inspected'] && ($row['over_fps'] || $row['over_size'])): ?>
                    <form action="video_reduce.php" method="post" class="stats-reduce"
                          onsubmit="return confirm('Re-encode this video with ffmpeg?\n\nThe stored copy is replaced by the smaller one once it has been verified. This cannot be undone.');">
                      <input type="hidden" name="id" value="<?= $rowId ?>">
                      <?php if ($row['over_fps']): ?>
                        <label><input type="checkbox" name="fps" value="1" checked> <?= VideoStats::TARGET_FPS ?>fps</label>
                      <?php endif; ?>
                      <?php if ($row['over_size']): ?>
                        <label><input type="checkbox" name="scale" value="1" checked> <?= VideoStats::MAX_LONG_EDGE ?>×<?= VideoStats::MAX_SHORT_EDGE ?></label>
                      <?php endif; ?>
                      <button type="submit" class="btn small">Reduce</button>
                    </form>
                  <?php elseif ($row['inspected']): ?>
                    <span class="hint">Within <?= VideoStats::TARGET_FPS ?>fps and <?= VideoStats::MAX_LONG_EDGE ?>×<?= VideoStats::MAX_SHORT_EDGE ?></span>
                  <?php endif; ?>
                  <form action="video_inspect.php" method="post" class="stats-inspect"
                        onsubmit="const b = this.querySelector('button'); b.disabled = true; b.textContent = 'Inspecting…';">
                    <input type="hidden" name="id" value="<?= $rowId ?>">
                    <button type="submit" class="btn secondary small"><?= $row['inspected'] ? 'Inspect again' : 'Inspect' ?></button>
                  </form>
                  <a class="btn secondary small" href="trim.php?id=<?= urlencode($row['id']) ?>">Trim</a>
                <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<script src="assets/job.js"></script>
</body>
</html>
