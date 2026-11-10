<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/StashKey.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/User.php';

/**
 * Re-encrypts an entire stash with a new password
 * (Docs/SPECIFICATIONS.md §2.6, Docs/PLAN.md 6.1).
 *
 * The key is derived from the password (StashKey), so changing the password
 * changes the key, and that means rewriting every archive the user owns —
 * there is no password record to update, and nothing cheaper that would work.
 * The new key gets a new salt, at the current Argon2id cost, so a re-key is
 * also how an older stash picks up a higher cost.
 *
 * Two ordering rules make an interrupted run survivable:
 *
 *  - every archive keeps its previous bytes as `.epc.old` until the whole run
 *    succeeds, so a failure part-way can put back what it replaced;
 *  - the index is rewritten LAST. While it still opens with the old password,
 *    the old password is still the stash's password, and the run can simply be
 *    retried. If the index went first, a failure would leave a stash whose
 *    index says one thing and whose media says another.
 *
 * The work itself is a decrypt and an encrypt of every video, so this can take
 * a while on a large stash. It is deliberately synchronous: the key the caller
 * holds is only valid until this returns.
 */
final class Rekey
{
    public function __construct(
        private Datastore $datastore = new Datastore(),
        private StashCrypto $crypto = new StashCrypto(),
    ) {
    }

    /**
     * Rewrites every archive under the user's directory from $oldKey (the
     * StashKey token the stash is on now — the session's) to a fresh key
     * derived from $newPassword.
     *
     * Taking the old *key* rather than the old password is deliberate: the
     * web flow no longer has the old password once login is over, and the job
     * that runs this should not be handed one it does not need.
     *
     * Never throws and never exits — callers get a result they can render,
     * whether that is a CLI script or a web request.
     *
     * @param callable(int, int, string):void|null $onProgress called after each
     *        archive is rewritten with (done, total, basename). The total is
     *        known up front, which is what lets a caller render "14 of 37"
     *        rather than a spinner (Docs/PLAN.md 6.6).
     * @return array{ok: bool, message: string, rewritten: int}
     */
    public function run(
        string $user,
        string $oldKey,
        string $newPassword,
        ?callable $onProgress = null,
    ): array {
        if (!User::isValidPassword($newPassword)) {
            return $this->fail('The new password must be at least ' . User::MIN_PASSWORD_LENGTH . ' characters.');
        }

        // The authoritative check that the key is the stash's: a wrong one
        // simply fails to decrypt. There is nothing else to compare against,
        // because no password or key is ever stored.
        if ($this->datastore->loadIndex($user, $oldKey) === null) {
            return $this->fail("Cannot decrypt {$user}'s stash with the current password — nothing changed.");
        }

        if (StashKey::matches($oldKey, $newPassword)) {
            return $this->fail('The new password is the same as the current one — nothing to do.');
        }

        $newKey = StashKey::create($newPassword);

        $archives = $this->archivesFor($user);

        if ($archives === []) {
            return $this->fail("Found no encrypted archives for {$user} — nothing changed.");
        }

        $rewritten = [];

        foreach ($archives as $archive) {
            $workDir = Datastore::tmpfsWorkDir('rekey');

            try {
                if (!$this->crypto->extract($archive, $workDir, $oldKey)) {
                    return $this->abort($rewritten, 'Failed to decrypt ' . basename($archive));
                }

                $plain = glob("{$workDir}/*")[0] ?? null;
                if ($plain === null) {
                    return $this->abort($rewritten, basename($archive) . ' decrypted to nothing');
                }

                rename($archive, "{$archive}.old");

                if (!$this->crypto->encrypt($plain, $archive, $newKey)) {
                    rename("{$archive}.old", $archive);

                    return $this->abort($rewritten, 'Failed to re-encrypt ' . basename($archive));
                }

                $rewritten[] = $archive;

                if ($onProgress !== null) {
                    $onProgress(count($rewritten), count($archives), basename($archive));
                }
            } finally {
                // The plaintext only ever existed in tmpfs, and not past here.
                Datastore::wipe($workDir);
            }
        }

        // Everything is on the new key: the safety copies are now the only
        // thing still readable with the old one, so they go.
        foreach ($rewritten as $archive) {
            unlink("{$archive}.old");
        }

        return [
            'ok' => true,
            'message' => 'Re-encrypted ' . count($rewritten) . ' archive(s).',
            'rewritten' => count($rewritten),
        ];
    }

    /**
     * Every `.epc` archive the user owns, index last.
     *
     * Leftover v1 `.enc` files are not touched: a stash only reaches this once
     * MigrateV2 has finished with it.
     *
     * @return list<string>
     */
    private function archivesFor(string $user): array
    {
        $root = Datastore::userDir($user);

        if (!is_dir($root)) {
            return [];
        }

        $archives = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), StashCrypto::EXTENSION)) {
                $archives[] = $file->getPathname();
            }
        }

        $index = Datastore::indexArchivePath($user);
        usort($archives, static fn($a, $b) => ($a === $index ? 1 : 0) <=> ($b === $index ? 1 : 0));

        return $archives;
    }

    /**
     * Puts back every archive rewritten so far, so a failed run leaves the
     * stash exactly as it was on the old password rather than half-converted.
     *
     * @param list<string> $rewritten
     * @return array{ok: bool, message: string, rewritten: int}
     */
    private function abort(array $rewritten, string $reason): array
    {
        $restored = 0;

        foreach ($rewritten as $archive) {
            if (file_exists("{$archive}.old") && rename("{$archive}.old", $archive)) {
                $restored++;
            }
        }

        return $this->fail(
            $reason . ' — rolled back ' . $restored . ' of ' . count($rewritten)
            . ' archive(s) already rewritten. Your current password still works.',
        );
    }

    /**
     * @return array{ok: bool, message: string, rewritten: int}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rewritten' => 0];
    }
}
