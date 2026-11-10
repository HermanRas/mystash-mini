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
$timestamp = VideoCategories::parseTimestamp((string) ($_POST['timestamp'] ?? '00:00:00'));

$index = Session::refreshIndex();
$redirect = 'video.php?id=' . urlencode($id) . '&edit=1';

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

// Only global categories can be assigned.
if ($name === '' || !isset($index['categories'][$name])) {
    header('Location: ' . $redirect);
    exit;
}

$categories = new VideoCategories();
$assignments = $categories->load(Session::user(), Session::key(), $id);

// The same category may be assigned repeatedly, but not twice at the same time.
foreach ($assignments as $assignment) {
    if ($assignment['name'] === $name && (int) $assignment['timestamp_seconds'] === $timestamp) {
        header('Location: ' . $redirect);
        exit;
    }
}

$assignments[] = ['name' => $name, 'timestamp_seconds' => $timestamp];

$categories->save(Session::user(), Session::key(), $id, $assignments, $index);
(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

header('Location: ' . $redirect);
