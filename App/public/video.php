<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';
require_once __DIR__ . '/../src/CreatorStore.php';
require_once __DIR__ . '/../src/VideoCreators.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/VideoPreview.php';

use MyStash\CreatorStore;
use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Playlists;
use MyStash\Session;
use MyStash\VideoCategories;
use MyStash\VideoCreators;
use MyStash\VideoPreview;

Session::requireLogin();

$index = Session::refreshIndex();
$videos = $index['videos'] ?? [];
$creators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];

$id = (string) ($_GET['id'] ?? '');

$video = null;
foreach ($videos as $v) {
    if ($v['id'] === $id) {
        $video = $v;
        break;
    }
}

if ($video === null) {
    header('Location: wall.php');
    exit;
}

// A conversion runs in a detached worker (Docs/PLAN.md 4.28), so this page has
// to ask how the last one for this video is getting on rather than assume.
$convertJob = Jobs::read(Jobs::id('convert', Session::user(), $id));
$converting = $convertJob !== null && $convertJob['state'] === Jobs::RUNNING;

$editing = isset($_GET['edit']);
$assignments = (new VideoCategories())->load(Session::user(), Session::key(), $id);

// The hover clip is built by a job after upload rather than during it, so a
// video can exist without one for a while — or for good, if that job failed.
$previewJob = Jobs::read(Jobs::id('preview', Session::user(), $id));
$buildingClip = $previewJob !== null && $previewJob['state'] === Jobs::RUNNING;
$hasClip = is_file(Datastore::videoDir(Session::user(), $id) . "/{$id}.mp4.preview.epc");

// Where the current preview came from (3.9). Only read while editing: the
// watch page does not need it, and this opens the per-video metadata archive.
$previewCapture = null;
if ($editing) {
    $metadata = (new Datastore())->loadVideoMetadata(Session::user(), Session::key(), $id);
    $previewCapture = $metadata['preview_capture_seconds'] ?? null;
}

$videoCreators = VideoCreators::of($video);

function creatorAvatarUrl(array $creators, string $name): ?string
{
    $id = (string) ($creators[$name]['id'] ?? '');

    return CreatorStore::hasProfileImage(Session::user(), $id)
        ? 'media.php?type=avatar&creator=' . urlencode($id)
        : null;
}

require_once __DIR__ . '/../src/Playlists.php';
require_once __DIR__ . '/../views/format.php';

// Which playlists exist, and which of them already hold this video. Membership
// is stored on the playlist, never on the video (see Playlists), so this is a
// scan rather than a field.
$playlists = Playlists::all($index);
$onPlaylists = Playlists::containing($index, $id);

$navActive = 'videos';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — <?= htmlspecialchars($video['title'], ENT_QUOTES) ?></title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="watch-layout">
  <div>
    <?php /* The conversion progress and any failure from the last attempt lead
             the page. They used to sit below the player and the action row,
             which put the one thing the user pressed a button to watch below
             the fold on a short window — easy to miss entirely, and the page
             then looked as though nothing had happened. */ ?>
    <?php if ($converting): ?>
      <div class="card job-card indeterminate" id="job-card"
           data-kind="convert" data-target="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
        <div class="section-title" style="margin-top:0;">
          <?= match (true) {
              !empty($convertJob['trim_mode']) => 'Trimming video',
              !empty($convertJob['reduce_fps']) || !empty($convertJob['reduce_scale']) => 'Reducing video',
              default => 'Converting to MP4/H.265',
          } ?>
        </div>
        <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
        <p class="hint job-message" id="job-message">
          <?= htmlspecialchars((string) $convertJob['message'], ENT_QUOTES) ?>
        </p>
        <p class="hint">
          This runs in the background. You can leave this page, keep browsing,
          or close the tab — it carries on, and the video stays exactly as it
          is until the converted copy has been written and verified.
        </p>
      </div>
    <?php elseif ($convertJob !== null && $convertJob['state'] === Jobs::FAILED): ?>
      <p class="hint" style="color:#ff6b6b;">
        <?= htmlspecialchars((string) $convertJob['message'], ENT_QUOTES) ?>
      </p>
    <?php endif; ?>

    <h1 class="watch-title"><?= htmlspecialchars($video['title'], ENT_QUOTES) ?></h1>

    <div class="player" id="player" data-video-src="media.php?id=<?= urlencode($id) ?>&amp;type=video">
      <img src="media.php?id=<?= urlencode($id) ?>&amp;type=thumb" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">
      <button type="button" id="player-play" style="position:relative; z-index:1; background:rgba(0,0,0,0.6); border:1px solid var(--border); color:#fff; border-radius:50%; width:64px; height:64px; font-size:20px; cursor:pointer;">▶</button>
    </div>

    <div class="watch-meta">
      <span id="view-count"><?= (int) $video['views'] ?></span>
      <span id="view-label">view<?= (int) $video['views'] === 1 ? '' : 's' ?></span>
      • <?= formatLength((int) $video['length_seconds']) ?>
      <?php if (!empty($video['quality'])): ?> • <?= htmlspecialchars($video['quality'], ENT_QUOTES) ?><?php endif; ?>
      <?php if (!empty($video['not_converted'])): ?>
        • <span style="color:#cc4444;">Not Converted</span>
      <?php endif; ?>
    </div>

    <div>
      <?php foreach ($assignments as $assignment): ?>
        <button type="button" class="tag tag-jump" data-seconds="<?= (int) $assignment['timestamp_seconds'] ?>">
          <span class="dot" style="background:<?= htmlspecialchars($categories[$assignment['name']] ?? '#888', ENT_QUOTES) ?>"></span>
          <?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>
          <span class="ts"><?= VideoCategories::formatTimestamp((int) $assignment['timestamp_seconds']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php /* Creators sit below the categories rather than in a side rail. A
             video may credit more than one, so this is a list. */ ?>
    <div class="section-title">Creator<?= count($videoCreators) === 1 ? '' : 's' ?></div>
    <div class="creator-list">
      <?php foreach ($videoCreators as $creatorName): ?>
        <?php $avatar = creatorAvatarUrl($creators, $creatorName); ?>
        <a class="card creator-strip" href="creator.php?edit=<?= urlencode($creatorName) ?>">
          <div class="creator-avatar">
            <?php if ($avatar !== null): ?>
              <img src="<?= htmlspecialchars($avatar, ENT_QUOTES) ?>" alt="">
            <?php endif; ?>
          </div>
          <div class="creator-strip-text">
            <div class="creator-name"><?= htmlspecialchars($creatorName, ENT_QUOTES) ?></div>
            <div class="creator-meta"><?= htmlspecialchars($creators[$creatorName]['bio'] ?? '', ENT_QUOTES) ?: 'no bio set' ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$editing): ?>
      <div class="form-actions">
        <a class="btn secondary" href="video.php?id=<?= urlencode($id) ?>&edit=1"><img class="btn-icon" src="assets/img/icons/video-edit.png" alt="">Edit Video</a>

        <?php /* Built like the sort menu and the user menu — a trigger with a
                 panel that opens on hover or focus — so the three dropdowns on
                 the site behave the same way. Each tick posts on its own
                 rather than waiting for an Apply: there is nothing to cancel,
                 and a dropdown that has to be confirmed is a dialog. */ ?>
        <div class="sort-menu playlist-menu" tabindex="0">
          <button type="button" class="btn secondary playlist-trigger" aria-label="Add to playlist">
            <img class="btn-icon" src="assets/img/icons/playlist.png" alt="">Playlist +
          </button>
          <div class="sort-menu-dropdown playlist-dropdown" data-video="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
            <?php if ($playlists === []): ?>
              <p class="hint" style="margin:6px 12px; white-space:nowrap;">No playlists yet.</p>
            <?php else: ?>
              <?php foreach ($playlists as $playlist): ?>
                <label class="playlist-option">
                  <input type="checkbox" value="<?= htmlspecialchars($playlist['id'], ENT_QUOTES) ?>"
                         <?= in_array($playlist['id'], $onPlaylists, true) ? 'checked' : '' ?>>
                  <span><?= htmlspecialchars($playlist['name'], ENT_QUOTES) ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
            <a class="playlist-manage" href="playlist.php">Manage playlists…</a>
          </div>
        </div>

        <?php /* The way back out. Everything in the stash is encrypted, so
                 without this the only way to get a video off the wall again
                 was the command line (tools/decode.php). */ ?>
        <a class="btn secondary" href="media.php?id=<?= urlencode($id) ?>&amp;type=video&amp;download=1">
          <img class="btn-icon" src="assets/img/icons/download.png" alt="">Download
        </a>

        <?php /* While a conversion is running the button is gone, not merely
                 disabled — a second run over the same archive is refused by the
                 job id anyway, but offering it would be a lie. */ ?>
        <?php if (!empty($video['not_converted']) && !$converting): ?>
          <form action="video_convert.php" method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
            <button type="submit" class="btn">Convert to MP4/H.265</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="section-title">Description</div>
      <p class="tile-stats" style="font-size:13px; color:#ccc;">
        <?= nl2br(htmlspecialchars($video['description'] ?? '', ENT_QUOTES)) ?: '<em>No description set.</em>' ?>
      </p>
    <?php else: ?>
      <div class="section-title" style="margin-top:20px;">Edit Video</div>
      <div class="card">
        <form action="video_save.php" method="post">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="field">
            <label for="v-title">Title</label>
            <input type="text" id="v-title" name="title" value="<?= htmlspecialchars($video['title'], ENT_QUOTES) ?>" required>
          </div>
          <div class="field">
            <label for="v-description">Description</label>
            <input type="text" id="v-description" name="description" value="<?= htmlspecialchars($video['description'] ?? '', ENT_QUOTES) ?>">
          </div>
          <div class="field">
            <label>Creators</label>
            <?php foreach (array_keys($creators) as $name): ?>
              <div class="field inline-check" style="margin-bottom:6px;">
                <label>
                  <input type="checkbox" name="creators[]" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                         <?= in_array($name, $videoCreators, true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($name, ENT_QUOTES) ?>
                </label>
              </div>
            <?php endforeach; ?>
            <p class="hint" style="margin:6px 0 0;">A video can credit more than one creator. Tick none and it falls back to <code>default</code>.</p>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn"><img class="btn-icon" src="assets/img/icons/video-save.png" alt="">Save Changes</button>
            <a class="btn secondary" href="video.php?id=<?= urlencode($id) ?>">Cancel</a>
          </div>
        </form>
      </div>

      <?php /* 3.9 — the preview was chosen once at upload and then fixed for
               good. If the frame landed on a fade or a blurred pan, the tile
               wore it forever. Two ways out, because they answer different
               problems: a better moment in the video, or a picture that is not
               in the video at all. */ ?>
      <div class="section-title">Preview Image</div>
      <div class="card">
        <?php if (isset($_GET['preview'])): ?>
          <p class="hint" style="color:var(--accent); margin-top:0;">
            <?= htmlspecialchars((string) $_GET['preview'], ENT_QUOTES) ?>
          </p>
        <?php elseif (isset($_GET['preview_error'])): ?>
          <p class="hint" style="color:#ff6b6b; margin-top:0;">
            <?= htmlspecialchars((string) $_GET['preview_error'], ENT_QUOTES) ?>
          </p>
        <?php endif; ?>

        <div class="preview-edit">
          <?php /* Cache-busted: the archive is replaced in place, so the URL
                   does not change and the browser would keep showing the old
                   one after a successful change. */ ?>
          <img class="preview-current"
               src="media.php?id=<?= urlencode($id) ?>&amp;type=thumb&amp;v=<?= urlencode((string) ($previewCapture ?? 'custom')) ?>"
               alt="Current preview" onerror="this.style.display='none'">

          <div class="preview-forms">
            <p class="hint" style="margin-top:0;">
              <?php if ($previewCapture === null): ?>
                Currently a picture you supplied.
              <?php else: ?>
                Currently the frame at <?= htmlspecialchars(VideoPreview::formatTimestamp((float) $previewCapture), ENT_QUOTES) ?>.
              <?php endif; ?>
            </p>

            <form action="video_preview.php" method="post" class="preview-row">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
              <input type="hidden" name="source" value="timestamp">
              <div class="field" style="margin-bottom:0; flex:1;">
                <label for="preview-at">Take a frame from the video, at (seconds)</label>
                <input type="number" id="preview-at" name="preview_at" min="0" step="1"
                       max="<?= (int) $video['length_seconds'] ?>"
                       value="<?= (int) ($previewCapture ?? 0) ?>" required>
              </div>
              <button type="submit" class="btn secondary"><img class="btn-icon" src="assets/img/icons/capture.png" alt="">Capture</button>
            </form>

            <form action="video_preview.php" method="post" enctype="multipart/form-data" class="preview-row">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
              <input type="hidden" name="source" value="upload">
              <div class="field" style="margin-bottom:0; flex:1;">
                <label for="preview-file">…or use your own picture</label>
                <input type="file" id="preview-file" name="preview_image" accept="image/*" required>
              </div>
              <button type="submit" class="btn secondary"><img class="btn-icon" src="assets/img/icons/image-upload.png" alt="">Upload</button>
            </form>

            <p class="hint" style="margin-bottom:0;">
              Whatever you upload is re-encoded to JPEG and scaled to fit 1280px,
              then encrypted like everything else. Taking a frame has to decrypt
              the video first, so it takes a moment on a long one.
            </p>
          </div>
        </div>
      </div>

      <div class="section-title">Hover Preview</div>
      <?php if ($buildingClip): ?>
        <div class="card job-card indeterminate" id="job-card"
             data-kind="preview" data-target="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
          <p class="hint job-message" id="job-message">
            <?= htmlspecialchars((string) $previewJob['message'], ENT_QUOTES) ?>
          </p>
          <p class="hint" style="margin-bottom:0;">
            The timelapse that plays when you hover over the tile is built in the
            background after upload. The video is already safely stored.
          </p>
        </div>
      <?php else: ?>
        <div class="card">
          <?php if ($previewJob !== null && $previewJob['state'] === Jobs::FAILED): ?>
            <p class="hint" style="color:#ff6b6b; margin-top:0;">
              <?= htmlspecialchars((string) $previewJob['message'], ENT_QUOTES) ?>
            </p>
          <?php endif; ?>
          <form action="video_preview.php" method="post" class="preview-row">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
            <input type="hidden" name="source" value="clip">
            <p class="hint" style="margin:0; flex:1;">
              <?= $hasClip
                  ? 'The tile plays a timelapse of the whole video on hover.'
                  : 'This video has no hover preview yet.' ?>
            </p>
            <button type="submit" class="btn secondary"><?= $hasClip ? 'Rebuild' : 'Build preview' ?></button>
          </form>
        </div>
      <?php endif; ?>

      <div class="section-title">Categories</div>
      <div class="card">
        <?php if ($assignments === []): ?>
          <p class="hint" style="margin:0 0 12px;">No categories assigned yet.</p>
        <?php endif; ?>

        <?php foreach ($assignments as $assignment): ?>
          <div class="tag" style="margin-bottom:8px;">
            <span class="dot" style="background:<?= htmlspecialchars($categories[$assignment['name']] ?? '#888', ENT_QUOTES) ?>"></span>
            <?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>
            <span class="ts"><?= VideoCategories::formatTimestamp((int) $assignment['timestamp_seconds']) ?></span>
            <form action="video_category_delete.php" method="post" style="display:inline;">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
              <input type="hidden" name="name" value="<?= htmlspecialchars($assignment['name'], ENT_QUOTES) ?>">
              <input type="hidden" name="timestamp_seconds" value="<?= (int) $assignment['timestamp_seconds'] ?>">
              <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0 0 0 4px;">✕</button>
            </form>
          </div>
        <?php endforeach; ?>

        <form action="video_category_add.php" method="post" style="display:flex; gap:12px; align-items:flex-end; margin-top:12px;">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
          <div class="field" style="margin-bottom:0; flex:1;">
            <label for="cat-name">Category</label>
            <select id="cat-name" name="name" required>
              <?php foreach (array_keys($categories) as $name): ?>
                <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:0; width:130px;">
            <label for="cat-ts">Time (hh:mm:ss)</label>
            <input type="text" id="cat-ts" name="timestamp" value="00:00:00" pattern="[0-9]{1,2}:[0-9]{2}:[0-9]{2}" required>
          </div>
          <button type="submit" class="btn">Add</button>
        </form>
        <p class="hint" style="margin-top:12px;">
          Categories come from the global list (user menu → Manage Categories). The same
          category can be added more than once at different times.
        </p>
      </div>

      <form class="form-actions" action="video_delete.php" method="post" onsubmit="return confirm('Delete this video permanently?');">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
        <button type="submit" class="btn secondary"><img class="btn-icon" src="assets/img/icons/video-delete.png" alt="">Delete Video</button>
      </form>
    <?php endif; ?>
  </div>
</main>

<script>
  // The video file is only fetched/decrypted on click, never eagerly.
  const player = document.getElementById('player');

  // A view is recorded the first time playback starts on this page — not on
  // page load, so opening a video without watching it doesn't count.
  let viewRecorded = false;

  function recordView() {
    if (viewRecorded) return;
    viewRecorded = true;

    fetch('video_view.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: <?= json_encode($id) ?> }),
    })
      .then((response) => response.ok ? response.json() : null)
      .then((data) => {
        if (!data) return;
        document.getElementById('view-count').textContent = data.views;
        document.getElementById('view-label').textContent = data.views === 1 ? 'view' : 'views';
      })
      .catch(() => {});
  }

  function startPlayback(atSeconds) {
    let video = player.querySelector('video');

    if (!video) {
      video = document.createElement('video');
      video.src = player.dataset.videoSrc;
      video.controls = true;
      video.autoplay = true;
      player.replaceChildren(video);
    }

    if (atSeconds !== undefined) {
      const seek = () => { video.currentTime = atSeconds; };
      // Seeking needs metadata; if it isn't loaded yet, wait for it.
      video.readyState >= 1 ? seek() : video.addEventListener('loadedmetadata', seek, { once: true });
    }

    // Count the view when the browser actually starts playing, so a file that
    // fails to decode isn't counted as watched.
    video.addEventListener('playing', recordView, { once: true });
    video.play().catch(() => {});
  }

  document.getElementById('player-play').addEventListener('click', () => startPlayback());

  // Category chips jump the player to their timestamp.
  document.querySelectorAll('.tag-jump').forEach((chip) => {
    chip.addEventListener('click', () => {
      startPlayback(Number(chip.dataset.seconds));
      player.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });

  // Playlist checkboxes post one at a time. The box is moved back if the write
  // fails, so what is ticked is always what the stash actually holds rather
  // than what the click hoped for.
  const playlistPanel = document.querySelector('.playlist-dropdown');

  if (playlistPanel) {
    playlistPanel.addEventListener('change', (e) => {
      const box = e.target;
      if (box.type !== 'checkbox') return;

      box.disabled = true;

      const body = new URLSearchParams();
      body.append('playlist', box.value);
      body.append('video', playlistPanel.dataset.video);

      fetch('playlist_toggle.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch' },
        body,
      })
        .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
        .then((data) => { box.checked = data.in_playlist; })
        .catch(() => { box.checked = !box.checked; })
        .finally(() => { box.disabled = false; });
    });
  }
</script>
<script src="assets/job.js"></script>

</body>
</html>
