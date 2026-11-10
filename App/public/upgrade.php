<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\Jobs;
use MyStash\Session;

/**
 * The one-time v1 → v2 upgrade's progress page (Docs/v2_migration.md).
 *
 * login.php lands here when the right password opens a stash that is still in
 * the v1 (7z) format: it has started the upgrade as a background job and
 * marked this browser as watching it (Session::beginUpgrade). There is no
 * login session yet — the stash has no v2 key until the job finishes — so this
 * page answers its own status polls instead of job_status.php, and only ever
 * for the user the session names, never one taken from the request.
 *
 * When the job finishes the user signs in again, with the same password: that
 * derives the new key, which proves the upgrade worked.
 */

$user = Session::upgradingUser();

if ($user === null) {
    header('Location: login.html');
    exit;
}

// Nothing below writes the session, and polls should not queue behind it.
session_write_close();

$job = Jobs::read(Jobs::id('upgrade', $user));

if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    echo json_encode($job === null ? ['state' => 'none'] : [
        'state' => $job['state'],
        'percent' => (int) ($job['percent'] ?? 0),
        'done' => (int) ($job['done'] ?? 0),
        'total' => (int) ($job['total'] ?? 0),
        'message' => (string) ($job['message'] ?? ''),
        'started_at' => (int) ($job['started_at'] ?? 0),
    ]);
    exit;
}

$running = $job !== null && $job['state'] === Jobs::RUNNING;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Upgrading Stash</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="form-page">
  <div class="form-card">
    <div class="brand">
      <img class="brand-logo" src="assets/img/logo.png" alt="MyStash">
    </div>

    <?php if ($running): ?>
      <div class="card job-card indeterminate" id="job-card"
           data-kind="upgrade" data-status-url="upgrade.php?status=1"
           data-done-url="login.html?upgraded=1">
        <div class="section-title" style="margin-top:0;">Upgrading your stash to v2</div>
        <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
        <p class="hint job-message" id="job-message">
          <?= htmlspecialchars((string) $job['message'], ENT_QUOTES) ?>
        </p>
      </div>
      <p class="hint">
        MyStash v2 stores everything in a new, stronger encrypted format. Every
        archive in your stash is being rewritten once; on a large stash this takes
        a while. Nothing old is removed until every new archive has been written and
        checked, so an interruption is safe — just sign in again to resume. Closing
        the tab will not stop it.
      </p>
    <?php elseif ($job !== null && $job['state'] === Jobs::DONE): ?>
      <p class="hint" style="color:#7bd88f;"><?= htmlspecialchars((string) $job['message'], ENT_QUOTES) ?></p>
      <p class="hint"><a href="login.html?upgraded=1" style="color:var(--accent);">Sign in to your upgraded stash</a></p>
    <?php else: ?>
      <p class="hint" style="color:#ff6b6b;">
        <?= htmlspecialchars((string) ($job['message'] ?? 'The upgrade is not running.'), ENT_QUOTES) ?>
      </p>
      <p class="hint">Your stash is still in the v1 format and still opens with your password. Sign in again to retry the upgrade.</p>
      <p class="hint"><a href="login.html" style="color:var(--accent);">Back to sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<script src="assets/job.js"></script>
</body>
</html>
