<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\Datastore;
use MyStash\Session;
use MyStash\VideoCategories;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: wall.php');
    exit;
}

$id = (string) ($_POST['id'] ?? '');
$name = (string) ($_POST['name'] ?? '');
$timestamp = (int) ($_POST['timestamp_seconds'] ?? -1);

$index = Session::refreshIndex();

// The id goes on to name a metadata archive, so it has to be one this stash
// holds rather than whatever the form posted (7.1).
$known = false;

foreach ($index['videos'] ?? [] as $video) {
    if ((string) $video['id'] === $id) {
        $known = true;
        break;
    }
}

if (!Datastore::isValidId($id) || !$known) {
    header('Location: wall.php');
    exit;
}

$categories = new VideoCategories();
$assignments = $categories->load(Session::user(), Session::key(), $id);

$removed = false;
$assignments = array_values(array_filter(
    $assignments,
    static function ($assignment) use ($name, $timestamp, &$removed) {
        // Drop only the first match, so repeated assignments of the same
        // category at the same timestamp aren't all removed at once.
        if (!$removed && $assignment['name'] === $name && (int) $assignment['timestamp_seconds'] === $timestamp) {
            $removed = true;

            return false;
        }

        return true;
    },
));

if ($removed) {
    $categories->save(Session::user(), Session::key(), $id, $assignments, $index);
    (new Datastore())->saveIndex(Session::user(), Session::key(), $index);
    Session::setIndex($index);
}

header('Location: video.php?id=' . urlencode($id) . '&edit=1');
