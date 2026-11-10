<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/StashKey.php';

/**
 * Creating and validating users.
 *
 * A user is just a datastore directory plus an index archive encrypted with a
 * key derived from their password — there is no account record anywhere, and
 * no password (or key) is ever stored (see Docs/SPECIFICATIONS.md §2.1).
 */
final class User
{
    /**
     * Letters and digits only. Beyond being the chosen naming rule, this is
     * what keeps a username safe to use as a path segment — no separators,
     * no dots, so it cannot traverse out of the data directory.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9]+$/';

    private const MAX_NAME_LENGTH = 32;

    /**
     * The password is what the encryption key for the entire stash is derived
     * from, and is never stored, so it cannot be reset, rotated on breach, or
     * rate-limited at the archive itself — anyone holding a copy of the .epc
     * files can attack it offline. Argon2id (StashKey) makes each guess cost
     * ~0.5 s and 256 MB, but a short password is still a small search, so
     * length remains the first defence, and 24 characters is the floor.
     */
    public const MIN_PASSWORD_LENGTH = 24;

    public function __construct(private Datastore $datastore = new Datastore())
    {
    }

    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function isValidPassword(string $password): bool
    {
        return strlen($password) >= self::MIN_PASSWORD_LENGTH;
    }

    public function exists(string $name): bool
    {
        return self::isValidName($name) && $this->datastore->userExists($name);
    }

    /**
     * Creates the user's datastore and their initial encrypted index, under a
     * fresh key derived from $password.
     *
     * Returns that key (a StashKey token) so the caller can sign straight in
     * without paying for Argon2id twice, or null if the name is invalid or
     * already taken, or the stash could not be written.
     */
    public function create(string $name, string $password): ?string
    {
        if (!self::isValidName($name) || $this->exists($name) || !self::isValidPassword($password)) {
            return null;
        }

        $key = StashKey::create($password);

        // Uploads default to the "default" creator, so it must exist — with its
        // own record file, like every other creator (see CreatorStore).
        $default = [
            'id' => '1',
            'name' => 'default',
            'age' => null,
            'gender' => null,
            'bio' => '',
            'created_at' => date('c'),
        ];

        $this->datastore->saveCreatorMetadata($name, $key, '1', $default);

        $saved = $this->datastore->saveIndex($name, $key, [
            // "Not Converted" is assigned automatically during ingestion, so a
            // new stash needs it defined up front for the tag to resolve.
            'categories' => ['Not Converted' => '#cc4444'],
            'creators' => ['default' => $default],
            'videos' => [],
        ]);

        return $saved ? $key : null;
    }

    /**
     * Deletes a stash and everything in it — every video, every creator, the
     * index, the directory (Docs/PLAN.md 7.0.2).
     *
     * This is the most destructive thing the application can do and there is
     * no undo: a user *is* their directory, so there is nothing left over to
     * restore from and no account record to disable instead. Two things guard
     * it, and they guard different mistakes:
     *
     *  - the caller checks that the user typed their own name, which is what
     *    stops a mis-click;
     *  - this checks the password the only authoritative way there is, by
     *    decrypting the index with it, which is what stops someone who has
     *    walked up to an unlocked session.
     *
     * The second is deliberately not "compare against the session password".
     * No password is stored anywhere to compare against, and a stash that
     * cannot be opened is not one this code should be deleting.
     *
     * @return array{ok: bool, message: string}
     */
    public function delete(string $name, string $password): array
    {
        // The name comes from the session, but it is about to be the last path
        // segment of a recursive delete, so it is re-checked here rather than
        // trusted. Letters and digits cannot traverse anywhere.
        if (!self::isValidName($name)) {
            return ['ok' => false, 'message' => 'That is not a valid stash name — nothing was deleted.'];
        }

        if (!$this->exists($name)) {
            return ['ok' => false, 'message' => "There is no stash called {$name}."];
        }

        $key = StashKey::unlock($name, $password);

        if ($key === null || $this->datastore->loadIndex($name, $key) === null) {
            return [
                'ok' => false,
                'message' => "That password does not open {$name}'s stash — nothing was deleted.",
            ];
        }

        $directory = Datastore::userDir($name);
        Datastore::wipe($directory);

        // wipe() skips what it cannot remove rather than throwing, so a stash
        // reported as deleted is one that is actually gone.
        if (is_dir($directory)) {
            return [
                'ok' => false,
                'message' => "{$name}'s stash could not be fully removed. Some of it may still be on disk.",
            ];
        }

        return ['ok' => true, 'message' => "Deleted {$name}'s stash."];
    }
}
