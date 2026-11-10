<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Playlists.php';

use MyStash\Datastore;
use MyStash\Playlists;
use MyStash\Session;

/**
 * Creates a playlist, or renames an existing one (Docs/PLAN.md 5.4).
 *
 * One endpoint for both because they are the same write: a posted `id` means
 * rename, no `id` means create.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: playlist.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$name = (string) ($_POST['name'] ?? '');

$index = Session::refreshIndex();

if ($id !== '') {
    // The id reaches a record, not a path, so it does not need Datastore's
    // filename guard — but an unknown one must still change nothing rather
    // than create a stray playlist under a caller-chosen id.
    if (!Playlists::rename($index, $id, $name)) {
        header('Location: playlist.php?id=' . urlencode($id) . '&error=name');
        exit;
    }

    $redirect = 'playlist.php?id=' . urlencode($id);
} else {
    $new = Playlists::create($index, $name);

    if ($new === null) {
        header('Location: playlist.php?error=name');
        exit;
    }

    // Straight into the new playlist: it is empty, and adding videos is the
    // only reason anyone creates one.
    $redirect = 'playlist.php?id=' . urlencode($new);
}

(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

header('Location: ' . $redirect);
