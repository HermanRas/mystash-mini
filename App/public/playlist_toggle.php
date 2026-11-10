<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Playlists.php';

use MyStash\Datastore;
use MyStash\Playlists;
use MyStash\Session;

/**
 * Adds or removes one video from one playlist (Docs/PLAN.md 5.4).
 *
 * What the watch page's "Playlist" dropdown posts when a checkbox is ticked.
 * Deliberately a toggle rather than a set-to-value: the checkbox and the
 * datastore can only disagree if two tabs are open, and in that case acting on
 * what the server currently holds is the answer that leaves the two tabs in
 * the same place.
 */

Session::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$playlistId = (string) ($_POST['playlist'] ?? '');
$videoId = (string) ($_POST['video'] ?? '');

$index = Session::refreshIndex();

// The video has to be one this stash holds. Nothing here touches a path, so
// this is not the traversal guard 7.1 added elsewhere — it is what stops a
// playlist filling up with ids that refer to nothing.
$known = false;

foreach ($index['videos'] ?? [] as $video) {
    if ((string) $video['id'] === $videoId) {
        $known = true;
        break;
    }
}

if (!$known || Playlists::find($index, $playlistId) === null) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$inPlaylist = Playlists::toggle($index, $playlistId, $videoId);

(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

echo json_encode(['ok' => true, 'in_playlist' => $inPlaylist]);
