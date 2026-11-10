<?php

declare(strict_types=1);

/**
 * Upgrades a v1 stash (7z `.enc`) to v2 (libsodium `.epc`) from the command
 * line — Docs/v2_migration.md.
 *
 * Signing in through the web does the same thing as a background job; this is
 * for an operator who would rather do it ahead of time, or for a stash too big
 * to want to watch in a browser. The work itself is MigrateV2.
 *
 * Usage (inside the container):
 *   php bin/migrate_v2.php {user}              (asks for the password)
 *   php bin/migrate_v2.php {user} {password}
 *
 * Prefer the first form: a password on the command line ends up in shell
 * history and, while this runs, in the process list.
 */

require_once __DIR__ . '/../src/MigrateV2.php';
require_once __DIR__ . '/../src/Jobs.php';

use MyStash\Jobs;
use MyStash\MigrateV2;

$user = (string) ($argv[1] ?? '');
$password = $argv[2] ?? null;

if ($user === '') {
    fwrite(STDERR, "Usage: php bin/migrate_v2.php {user} [password]\n");
    exit(1);
}

if ($password === null) {
    fwrite(STDERR, "Password for {$user}: ");
    $tty = posix_isatty(STDIN);
    $tty && shell_exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    $tty && shell_exec('stty echo');
    fwrite(STDERR, "\n");
}

// The web upgrade and this must not both rewrite the same stash at once.
if (Jobs::isAlive(Jobs::id('upgrade', $user))) {
    fwrite(STDERR, "An upgrade of {$user} is already running (started by a sign-in). Let it finish.\n");
    exit(1);
}

$result = (new MigrateV2())->run(
    $user,
    $password,
    static fn(int $done, int $total, string $name) => print("upgraded {$done}/{$total}  {$name}\n"),
);

if (!$result['ok']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo "\n" . $result['message'] . "\n";
exit(0);
