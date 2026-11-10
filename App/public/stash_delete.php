<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/StashKey.php';

use MyStash\Jobs;
use MyStash\Session;
use MyStash\StashKey;
use MyStash\User;

/**
 * Deletes the signed-in user's entire stash (Docs/PLAN.md 7.0.2).
 *
 * Registration existed with no way back out: the only way to remove a stash
 * was to delete App/Data/{user}/ by hand. This is that, done properly — and
 * guarded, because it is irreversible and there is nothing anywhere to restore
 * from. A stash is its directory and its password; delete the directory and
 * the videos are gone, not archived, not recoverable.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user.php');
    exit;
}

$back = static function (string $query): never {
    header('Location: user.php?' . $query);
    exit;
};

$user = Session::user();
$password = (string) ($_POST['current_password'] ?? '');
$typedName = (string) ($_POST['confirm_name'] ?? '');

// Typing the name is what stands between a mis-click and losing everything.
// A confirm() dialog is one keystroke away and this is not an action that
// should be one keystroke away.
if (!hash_equals($user, $typedName)) {
    $back('error=name');
}

// The session holds only the key, so the typed password is checked by deriving
// it again and comparing (StashKey::matches). User::delete() then re-proves it
// the only authoritative way — by decrypting the index — before anything is
// removed.
if (!StashKey::matches(Session::key(), $password)) {
    $back('error=wrong');
}

// A convert or re-key worker running right now is holding this directory open
// and writing into it. Deleting underneath it would let it recreate part of
// what was just removed.
if (Jobs::aliveFor($user) !== null) {
    $back('error=busy');
}

$result = (new User())->delete($user, $password);

if (!$result['ok']) {
    error_log("MyStash delete stash failed for {$user}: " . $result['message']);
    $back('error=deletefailed');
}

// The stash is gone, so there is nothing for this session to be a session of.
Session::logout();

header('Location: login.html?deleted=1');
