<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/Jobs.php';

/**
 * Starts a PHP session backed by tmpfs (/dev/shm) instead of the default
 * on-disk session save path. The session carries the stash KEY for the
 * duration of the login (needed to decrypt/re-encrypt on demand) — it must
 * never touch a persistent disk, only RAM-backed storage that is gone on
 * container restart.
 *
 * Since v2 it holds the key derived from the password (StashKey), never the
 * password itself. Someone who reads a session file can open that stash while
 * the key is unchanged — that is unavoidable — but does not learn the
 * password, which may well be in use somewhere else too.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $tmpfsPath = '/dev/shm/mystash-sessions';
        if (!is_dir($tmpfsPath)) {
            mkdir($tmpfsPath, 0700, true);
        }

        session_save_path($tmpfsPath);
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::start();

        return isset($_SESSION['user'], $_SESSION['key']);
    }

    /**
     * @param string $key the StashKey token the index was just opened with
     */
    public static function login(string $user, string $key, array $index): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION = [];
        $_SESSION['user'] = $user;
        $_SESSION['key'] = $key;
        $_SESSION['index'] = $index;
    }

    /**
     * Marks this browser as the one watching $user's v1 → v2 upgrade
     * (login.php → upgrade.php). Not a login: the session holds no key, and
     * every page but upgrade.php still sends it to the login form. It only
     * lets upgrade.php know whose job to show without taking a name from the
     * request.
     */
    public static function beginUpgrade(string $user): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION = ['upgrading' => $user];
    }

    public static function upgradingUser(): ?string
    {
        self::start();

        $user = $_SESSION['upgrading'] ?? null;

        return is_string($user) ? $user : null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Pages that are still reachable while the stash is being re-keyed.
     *
     * Everything else is not, and that is not a UI nicety — see below.
     */
    private const REKEY_SAFE_PAGES = ['user.php', 'job_status.php', 'logout.php', 'login.php'];

    public static function requireLogin(): void
    {
        self::start();

        if (!self::isLoggedIn()) {
            header('Location: login.html');
            exit;
        }

        self::blockDuringRekey();
    }

    /**
     * While a re-key job is running, the stash is locked read-only — in
     * practice, locked to the Profile screen showing its progress.
     *
     * Making the re-key a background job (Docs/PLAN.md 6.6) reopened the
     * failure 6.5 was written about. The session holds the *old* key; the
     * worker is moving every archive onto the new one, index last. A page load
     * in the middle still decrypts fine (the index is still on the old key) and
     * would happily write something back under the old key — landing that
     * archive on a key the rest of the stash has already left. That is exactly
     * the split-key state that once had to be repaired by hand.
     *
     * When it was synchronous the request itself was the lock. Now this is.
     */
    private static function blockDuringRekey(): void
    {
        if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), self::REKEY_SAFE_PAGES, true)) {
            return;
        }

        if (Jobs::isAlive(Jobs::id('rekey', self::user()))) {
            header('Location: user.php?rekeying=1');
            exit;
        }
    }

    public static function user(): string
    {
        return $_SESSION['user'];
    }

    /** The StashKey token every archive in this stash is encrypted under. */
    public static function key(): string
    {
        return $_SESSION['key'];
    }

    public static function index(): array
    {
        return $_SESSION['index'];
    }

    public static function setIndex(array $index): void
    {
        $_SESSION['index'] = $index;
    }

    /**
     * Re-reads the index from disk and refreshes the session copy.
     *
     * Every page and endpoint must start from this rather than the snapshot
     * taken at login: the whole index is written back on each change, so a
     * session working from a stale copy would silently revert changes made in
     * another session (which is exactly how a deleted video reappeared on the
     * wall once).
     *
     * If the index no longer decrypts, the session's key is stale — the
     * stash was re-keyed elsewhere while this session was open. The session is
     * ended rather than allowed to continue: every write re-encrypts with the
     * key the session holds, so carrying on would rewrite the index and
     * creator records under the *old* key and leave the stash split across two
     * passwords, with some archives opening under neither in practice. That is
     * not hypothetical — it happened once, after `bin/rekey_user.php` ran while
     * a browser session was still open.
     */
    public static function refreshIndex(): array
    {
        $index = (new Datastore())->loadIndex(self::user(), self::key());

        if ($index === null) {
            self::logout();
            header('Location: login.html?error=stale');
            exit;
        }

        $_SESSION['index'] = $index;

        return $index;
    }
}
