<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Playlists.php';

use MyStash\Datastore;
use MyStash\Playlists;
use MyStash\Session;

/**
 * Deletes a playlist (Docs/PLAN.md 5.4).
 *
 * This removes the list, never the videos on it. A playlist is a view over the
 * stash, so deleting one is closer to clearing a filter than to deleting
 * anything — which is why it needs no password and no typed confirmation, only
 * the browser confirm on the button.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: playlist.php');
    exit;
}

$index = Session::refreshIndex();

if (Playlists::delete($index, (string) ($_POST['id'] ?? ''))) {
    (new Datastore())->saveIndex(Session::user(), Session::key(), $index);
    Session::setIndex($index);
}

header('Location: playlist.php');
