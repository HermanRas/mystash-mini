<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Datastore.php';

use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: category.php');
    exit;
}

$name = (string) ($_POST['name'] ?? '');

if ($name === '') {
    header('Location: category.php');
    exit;
}

$index = Session::refreshIndex();

// Removing a category only retires the global definition: it can no longer be
// assigned to videos and disappears from the wall's filter panel, but videos
// already tagged with it keep their tags.
unset($index['categories'][$name]);

(new Datastore())->saveIndex(Session::user(), Session::key(), $index);
Session::setIndex($index);

header('Location: category.php');
