<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/Datastore.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/Playlists.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/VideoStats.php';
require_once __DIR__ . '/../src/VideoTrim.php';

use MyStash\Datastore;
use MyStash\Jobs;
use MyStash\Playlists;
use MyStash\Session;
use MyStash\VideoStats;
use MyStash\VideoTrim;

/**
 * Stats' "Delete duplicates": removes every video whose title repeats an
 * earlier upload's, keeping the earliest of each group (VideoStats::duplicateIds).
 *
 * The ids are worked out here from the index, never taken from the form, so
 * the post can only ever delete what this stash lists as a duplicate. A copy
 * being converted, or with a trimmed copy waiting on Keep/Delete, is left
 * alone: a worker is writing into its directory, or a decision is pending.
 */

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: stats.php');
    exit;
}

$user = Session::user();
$index = Session::refreshIndex();

$deleted = [];
$skipped = 0;

foreach (VideoStats::duplicateIds($index['videos'] ?? []) as $id) {
    if (!Datastore::isValidId($id)) {
        continue;
    }

    $job = Jobs::read(Jobs::id('convert', $user, $id));

    if (($job !== null && $job['state'] === Jobs::RUNNING) || VideoTrim::exists($user, $id)) {
        $skipped++;
        continue;
    }

    $deleted[$id] = true;
}

if ($deleted !== []) {
    $index['videos'] = array_values(array_filter(
        $index['videos'],
        static fn($v) => !isset($deleted[(string) $v['id']]),
    ));

    foreach (array_keys($deleted) as $id) {
        Playlists::forgetVideo($index, (string) $id);
    }

    // The index is written before the directories go: a crash in between
    // leaves orphaned files, never an index entry pointing at nothing.
    Session::setIndex($index);
    (new Datastore())->saveIndex($user, Session::key(), $index);

    foreach (array_keys($deleted) as $id) {
        $dir = Datastore::videoDir($user, (string) $id);
        if (is_dir($dir)) {
            Datastore::wipe($dir);
        }
    }
}

header('Location: stats.php?deduped=' . count($deleted) . '&dedupe_skipped=' . $skipped);
