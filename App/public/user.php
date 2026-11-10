<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\User;

Session::requireLogin();

// The re-key runs in a detached worker (Docs/PLAN.md 6.6), and while it does,
// Session::requireLogin() sends every other page here — so this screen has to
// be able to render itself with the stash mid-flight.
$rekeyJob = Jobs::read(Jobs::id('rekey', Session::user()));
$rekeying = $rekeyJob !== null && $rekeyJob['state'] === Jobs::RUNNING;

$index = Session::refreshIndex();
$videoCount = count($index['videos'] ?? []);
$creatorCount = count($index['creators'] ?? []);

// Set by password_change.php on the way back here.
$status = (string) ($_GET['changed'] ?? '');
$error = (string) ($_GET['error'] ?? '');

$errors = [
    'mismatch' => 'The two new passwords do not match.',
    'short' => 'The new password must be at least ' . User::MIN_PASSWORD_LENGTH . ' characters.',
    'wrong' => 'That is not your current password.',
    'same' => 'The new password is the same as your current one.',
    'running' => 'A re-encryption is already running for this stash.',
    'name' => 'That is not your stash name — nothing was deleted.',
    'busy' => 'Something is still running on this stash. Wait for it to finish, then try again.',
    'deletefailed' => 'The stash could not be deleted. Nothing else changed.',
];

$navActive = '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Profile</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<h1 class="page-title">Profile</h1>

<main class="container profile-layout">
  <div class="card">
    <div class="field">
      <label for="p-username">Username</label>
      <input type="text" id="p-username" value="<?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>" disabled>
      <p class="hint" style="margin:6px 0 0;">
        A stash is its directory and its password — there is no account record to
        rename, so the username is fixed once created.
      </p>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Stash</label>
      <p class="hint" style="margin:0;">
        <?= $videoCount ?> video<?= $videoCount === 1 ? '' : 's' ?>,
        <?= $creatorCount ?> creator<?= $creatorCount === 1 ? '' : 's' ?>.
      </p>
    </div>
  </div>

  <div class="section-title">Change Password</div>

  <?php if (!$rekeying && $rekeyJob !== null && $rekeyJob['state'] === Jobs::FAILED): ?>
    <p class="notice bad">
      <?= htmlspecialchars((string) $rekeyJob['message'], ENT_QUOTES) ?>
    </p>
  <?php elseif ($error !== ''): ?>
    <p class="notice bad">
      <?= htmlspecialchars($errors[$error] ?? 'The password could not be changed — nothing was altered.', ENT_QUOTES) ?>
    </p>
  <?php endif; ?>

  <?php if ($rekeying): ?>
    <div class="card job-card indeterminate" id="job-card"
         data-kind="rekey" data-target="" data-done-url="logout.php">
      <div class="section-title" style="margin-top:0;">Re-encrypting your stash</div>
      <div class="progress"><div class="progress-bar" id="job-bar"></div></div>
      <p class="hint job-message" id="job-message">
        <?= htmlspecialchars((string) $rekeyJob['message'], ENT_QUOTES) ?>
      </p>
      <p class="hint">
        Every archive is being rewritten with the new key. The rest of the site is
        closed until this finishes — a page saving something with the old password
        half way through would leave the stash split across two keys. Closing the
        tab is safe; the run carries on without it.
      </p>
      <p class="hint">
        When it is done you will be signed out, and you sign back in with the
        <em>new</em> password.
      </p>
    </div>
  <?php else: ?>
  <div class="card">
    <form action="password_change.php" method="post" id="password-form">
      <div class="field">
        <label for="current-password">Current password</label>
        <input type="password" id="current-password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="field">
        <label for="new-password">New password</label>
        <input type="password" id="new-password" name="new_password" autocomplete="new-password"
               minlength="<?= User::MIN_PASSWORD_LENGTH ?>" required>
      </div>
      <div class="field">
        <label for="confirm-password">Confirm new password</label>
        <input type="password" id="confirm-password" name="confirm_password" autocomplete="new-password"
               minlength="<?= User::MIN_PASSWORD_LENGTH ?>" required>
        <p class="hint" id="match-hint" style="margin:6px 0 0;" hidden>The two new passwords do not match.</p>
      </div>
      <button type="submit" class="btn block">Re-encrypt Stash with New Password</button>
    </form>

    <p class="hint">
      Your encryption key is derived from your password, so changing it re-encrypts every
      archive in your stash<?php if ($videoCount > 0): ?>, all <?= $videoCount ?>
      video<?= $videoCount === 1 ? '' : 's' ?> included<?php endif ?>. On a large
      stash this takes a while, so it runs in the background and this page shows how
      far it has got — closing the tab will not stop it. Each archive
      keeps its previous bytes as <code>.epc.old</code> until the whole run succeeds,
      and the index is rewritten last, so an interrupted run leaves your current
      password still working.
    </p>
    <p class="hint">
      It cannot be reset. There is no record of it anywhere — forget it and the stash
      is unreadable.
    </p>
  </div>

  <?php /* Last on the page, behind its own heading, and deliberately not
           beside anything routine. */ ?>
  <div class="section-title danger-title">Delete This Stash</div>

  <div class="card danger-card">
    <p class="hint" style="margin-top:0;">
      This deletes <strong><?= htmlspecialchars(Session::user(), ENT_QUOTES) ?></strong> and
      everything in it — <?= $videoCount ?> video<?= $videoCount === 1 ? '' : 's' ?>,
      <?= $creatorCount ?> creator<?= $creatorCount === 1 ? '' : 's' ?>, the index, all of it.
    </p>
    <p class="hint">
      <strong>There is no undo.</strong> A stash is its directory and its password;
      there is no account record to disable instead, no copy kept anywhere, and
      nothing to restore from. If you want any of these videos, download them first.
    </p>

    <form action="stash_delete.php" method="post" id="delete-form">
      <div class="field">
        <label for="confirm-name">Type <code><?= htmlspecialchars(Session::user(), ENT_QUOTES) ?></code> to confirm</label>
        <input type="text" id="confirm-name" name="confirm_name" autocomplete="off"
               spellcheck="false" data-expected="<?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>" required>
      </div>
      <div class="field">
        <label for="delete-password">Your password</label>
        <input type="password" id="delete-password" name="current_password"
               autocomplete="current-password" required>
        <p class="hint" style="margin:6px 0 0;">
          Checked by opening the stash with it, the same way logging in is.
        </p>
      </div>
      <button type="submit" class="btn block danger" id="delete-submit" disabled>
        Delete This Stash Permanently
      </button>
    </form>
  </div>
  <?php endif; ?>
</main>

<script>
  // Catch the mismatch here rather than after re-encrypting anything. The
  // server checks it too; this just saves a pointless round trip.
  const form = document.getElementById('password-form');

  // While a re-key is running the form is not on the page at all — the progress
  // card is in its place — so there is nothing here to wire up.
  if (form) {
    const next = document.getElementById('new-password');
    const again = document.getElementById('confirm-password');
    const hint = document.getElementById('match-hint');

    const compare = () => {
      const mismatched = again.value !== '' && next.value !== again.value;
      hint.hidden = !mismatched;
      again.setCustomValidity(mismatched ? 'The two new passwords do not match.' : '');
    };

    next.addEventListener('input', compare);
    again.addEventListener('input', compare);

    form.addEventListener('submit', (event) => {
      compare();
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        return;
      }
      // Starting the job is instant, but the redirect still has to land; make it
      // impossible to fire a second press at it in the meantime.
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      button.textContent = 'Starting…';
    });
  }

  // The delete button stays disabled until the name matches exactly. The
  // server checks it too — this just means the button cannot be hit by
  // accident on the way past.
  const deleteForm = document.getElementById('delete-form');

  if (deleteForm) {
    const typed = document.getElementById('confirm-name');
    const deleteButton = document.getElementById('delete-submit');

    typed.addEventListener('input', () => {
      deleteButton.disabled = typed.value !== typed.dataset.expected;
    });

    deleteForm.addEventListener('submit', (event) => {
      if (!confirm(`Delete the ${typed.dataset.expected} stash and every video in it? This cannot be undone.`)) {
        event.preventDefault();
      }
    });
  }
</script>

<script src="assets/job.js"></script>
</body>
</html>
