<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/StashKey.php';
require_once __DIR__ . '/User.php';

/**
 * Upgrades a v1 stash (7z `.enc` archives) to v2 (libsodium `.epc` archives)
 * — Docs/v2_migration.md.
 *
 * Runs once per stash, with the password, either as a background job started
 * by login.php the first time a v1 stash signs in, or from the command line
 * (bin/migrate_v2.php).
 *
 * Every `X.enc` is decrypted with 7z into tmpfs and written as `X.epc` beside
 * it under a fresh Argon2id key. The ordering is what makes an interruption
 * harmless, the same way Rekey's is:
 *
 *  - nothing v1 is touched until every archive has a verified v2 twin, so a
 *    failure — or a killed container — leaves the v1 stash exactly as it was;
 *  - the v2 index is written LAST, and its existence is the "this stash is v2"
 *    marker (Datastore::isV1Stash). Until it exists, login still treats the
 *    stash as v1 and the upgrade simply runs again from the top;
 *  - only then are the `.enc` files deleted. A crash in that loop leaves a
 *    working v2 stash with some v1 litter, which a re-run clears.
 */
final class MigrateV2
{
    public function __construct(
        private Datastore $datastore = new Datastore(),
        private Crypto7z $legacy = new Crypto7z(),
        private StashCrypto $crypto = new StashCrypto(),
    ) {
    }

    /**
     * Never throws and never exits — callers get a result they can render.
     *
     * @param callable(int, int, string):void|null $onProgress (done, total, name)
     * @return array{ok: bool, message: string, converted: int}
     */
    public function run(string $user, string $password, ?callable $onProgress = null): array
    {
        if (!User::isValidName($user) || !$this->datastore->userExists($user)) {
            return $this->fail("There is no stash called {$user}.");
        }

        // A finished upgrade that died while deleting the v1 files: the stash
        // already works, so only the litter is left to clear — and only once
        // the password is proven against the v2 index.
        if (!$this->datastore->isV1Stash($user)) {
            return $this->clearLeftovers($user, $password);
        }

        if (!$this->opensV1($user, $password)) {
            return $this->fail("That password does not open {$user}'s stash — nothing changed.");
        }

        $archives = $this->v1ArchivesFor($user);
        $key = StashKey::create($password);
        $written = [];

        foreach ($archives as $archive) {
            $workDir = Datastore::tmpfsWorkDir('upgrade');
            $target = substr($archive, 0, -strlen('.enc')) . StashCrypto::EXTENSION;

            try {
                if (!$this->legacy->extract($archive, $workDir, $password)) {
                    return $this->abort($written, 'Could not decrypt ' . basename($archive));
                }

                $plain = glob("{$workDir}/*") ?: [];

                if (count($plain) !== 1 || !is_file($plain[0])) {
                    return $this->abort($written, basename($archive) . ' did not hold exactly one file');
                }

                // replace() writes beside the target and proves the new archive
                // decrypts to the same bytes before it is swapped in. A stale
                // .epc from an earlier, interrupted run is simply replaced.
                if (!$this->crypto->replace($plain[0], $target, $key)) {
                    return $this->abort($written, 'Could not write ' . basename($target));
                }

                $written[] = $target;

                if ($onProgress !== null) {
                    $onProgress(count($written), count($archives), basename($archive));
                }
            } finally {
                Datastore::wipe($workDir);
            }
        }

        // The v2 index is in $written as the last entry, so the stash is now
        // v2. Only from here is deleting the v1 copies safe.
        foreach ($archives as $archive) {
            @unlink($archive);
        }

        return [
            'ok' => true,
            'message' => 'Upgraded ' . count($written) . ' archive(s) to the v2 format.',
            'converted' => count($written),
        ];
    }

    /**
     * Does $password open this v1 stash's index? 7z has no other way to ask.
     */
    public function opensV1(string $user, string $password): bool
    {
        $workDir = Datastore::tmpfsWorkDir('upgrade');

        try {
            return $this->legacy->extract(Datastore::legacyIndexArchivePath($user), $workDir, $password)
                && is_file("{$workDir}/{$user}.json");
        } finally {
            Datastore::wipe($workDir);
        }
    }

    /**
     * Every v1 archive the user owns, index LAST — see the class comment.
     *
     * Only names ending in exactly `.enc`: v1's own crash leftovers
     * (`.enc.old`, `.enc.new.*`) were never live archives and are left alone.
     *
     * @return list<string>
     */
    private function v1ArchivesFor(string $user): array
    {
        $archives = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Datastore::userDir($user), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.enc')) {
                $archives[] = $file->getPathname();
            }
        }

        sort($archives);

        $index = Datastore::legacyIndexArchivePath($user);
        usort($archives, static fn($a, $b) => ($a === $index ? 1 : 0) <=> ($b === $index ? 1 : 0));

        return $archives;
    }

    /**
     * @return array{ok: bool, message: string, converted: int}
     */
    private function clearLeftovers(string $user, string $password): array
    {
        $key = StashKey::unlock($user, $password);

        if ($key === null || $this->datastore->loadIndex($user, $key) === null) {
            return $this->fail("That password does not open {$user}'s stash — nothing changed.");
        }

        $leftovers = $this->v1ArchivesFor($user);

        foreach ($leftovers as $archive) {
            @unlink($archive);
        }

        return [
            'ok' => true,
            'message' => $leftovers === []
                ? "{$user}'s stash is already in the v2 format — nothing to do."
                : "{$user}'s stash was already v2; removed " . count($leftovers) . ' leftover v1 archive(s).',
            'converted' => 0,
        ];
    }

    /**
     * Removes every v2 archive this run wrote, so a failed upgrade leaves the
     * v1 stash exactly as it found it. The `.enc` files were never touched.
     *
     * @param list<string> $written
     * @return array{ok: bool, message: string, converted: int}
     */
    private function abort(array $written, string $reason): array
    {
        foreach ($written as $archive) {
            @unlink($archive);
        }

        return $this->fail($reason . ' — the upgrade was rolled back and the stash is still v1. Try again.');
    }

    /**
     * @return array{ok: bool, message: string, converted: int}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'converted' => 0];
    }
}
