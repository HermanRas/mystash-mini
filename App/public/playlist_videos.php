<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Playlists.php';

use MyStash\Datastore;
use MyStash\Playlists;
use MyStash\Session;

/**
 * Sets a playlist's contents and their order (Docs/PLAN.md 5.4).
 *
 * Both writers post here: the "Add Videos" modal sends the checked ids, and a
 * drag on the edit screen sends the rows in their new order. They are the same
 * write — "this playlist now holds exactly these, in this order" — so they are
 * one endpoint rather than an add, a remove and a reorder that could each
 * disagree about what the list currently is.
 *
 * That also makes the drag safe against a stale page: the browser sends the
 * whole order it is showing, so the result is what the user can see, not the
 * outcome of replaying a move against a list that changed underneath it.
 */

Session::requireLogin();

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$fail = static function (string $message) use ($wantsJson): never {
    if ($wantsJson) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }

    header('Location: playlist.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Not a POST.');
}

$id = (string) ($_POST['id'] ?? '');
$index = Session::refreshIndex();

if (Playlists::find($index, $id) === null) {
    $fail('No such playlist.');
}

// The ids the stash actually holds. Anything else the form posted is dropped
// rather than stored — a playlist entry whose video does not exist renders as
// a hole and quietly changes what the video count means.
$known = array_map(static fn(array $v) => (string) $v['id'], $index['videos'] ?? []);

$posted = $_POST['videos'] ?? [];
$posted = is_array($posted) ? $posted : [];

Playlists::setVideos($index, $id, $posted, $known);

(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'count' => count(Playlists::find($index, $id)['videos'] ?? []),
    ]);
    exit;
}

header('Location: playlist.php?id=' . urlencode($id));
