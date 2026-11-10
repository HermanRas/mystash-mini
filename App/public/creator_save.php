<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/CreatorStore.php';

use MyStash\CreatorStore;
use MyStash\Datastore;
use MyStash\Session;

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: creator.php');
    exit;
}

$originalName = (string) ($_POST['original_name'] ?? '');
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    header('Location: creator.php');
    exit;
}

$index = Session::refreshIndex();
$creators = new CreatorStore();

$record = $creators->save(Session::user(), Session::key(), $index, [
    'name' => $name,
    'age' => ($_POST['age'] ?? '') !== '' ? (int) $_POST['age'] : null,
    'gender' => trim((string) ($_POST['gender'] ?? '')) ?: null,
    'bio' => trim((string) ($_POST['bio'] ?? '')),
], $originalName);

// An empty file input means "keep the current picture", not "remove it".
if (($_FILES['profile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $creators->saveProfileImage(Session::user(), Session::key(), $record['id'], $_FILES['profile']['tmp_name']);
}

Session::setIndex($index);
(new Datastore())->saveIndex(Session::user(), Session::key(), $index);

header('Location: creator.php');
