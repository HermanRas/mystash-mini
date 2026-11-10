<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/LoginThrottle.php';
require_once __DIR__ . '/../src/MigrateV2.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/StashKey.php';
require_once __DIR__ . '/../src/User.php';

use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\LoginThrottle;
use MyStash\MigrateV2;
use MyStash\Session;
use MyStash\StashKey;
use MyStash\User;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

// Taken before any work, because the constant-time floor below is measured
// from here (7.3).
$startedAt = microtime(true);

$user = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// Checked *before* the key derivation, which is the whole point: past the limit
// this costs a file read instead of half a second and 256 MB of Argon2id, so a
// flood is cheaper for the server to refuse than for the attacker to send.
$retryAfter = LoginThrottle::retryAfter($user, $address);

if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    header('Location: login.html?error=1&wait=' . (int) ceil($retryAfter / 60));
    exit;
}

$datastore = new Datastore();
$index = null;
$key = null;

// The name is used as a path segment, so reject anything but letters/digits
// before it reaches the filesystem.
$validName = User::isValidName($user);

if ($validName && $password !== '' && $datastore->isV1Stash($user)) {
    // A stash still in the v1 (7z) format. The v2 app cannot work with it, so
    // the right password starts the one-time upgrade instead of a session
    // (Docs/v2_migration.md), and the user signs in again once it is done.
    if ((new MigrateV2())->opensV1($user, $password)) {
        LoginThrottle::clear($user);

        // A second sign-in while it runs finds the first job and just watches.
        Jobs::start('upgrade', $user, '', ['password' => $password], ['message' => 'Starting the upgrade…']);
        Session::beginUpgrade($user);

        header('Location: upgrade.php');
        exit;
    }

    // Burn the same Argon2id a v2 failure costs, so a v1 stash's failures do
    // not answer measurably faster than everyone else's.
    StashKey::unlock('', $password);
} elseif ($password !== '') {
    // unlock() spends the same Argon2id work whether or not there is a stash
    // to take a salt from, so "no such user" and "wrong password" take the
    // same time (7.3).
    $key = StashKey::unlock($validName ? $user : '', $password);
    $index = $key !== null ? $datastore->loadIndex($user, $key) : null;
}

// Wrong password and unknown username look identical: no further detail given.
if ($index === null) {
    LoginThrottle::recordFailure($user, $address);

    // ...and now they take the same amount of *time*, too. Without this the
    // replies matched but the clock did not: in v1, ~130ms when a stash existed
    // and 7z ran, ~16ms when there was nothing to try. That is a username
    // oracle (7.3). v2 pays Argon2id on both paths, and this floor stays as the
    // backstop for whatever else differs.
    LoginThrottle::settle($startedAt);

    header('Location: login.html?error=1');
    exit;
}

LoginThrottle::clear($user);

Session::login($user, (string) $key, $index);

header('Location: wall.php');
