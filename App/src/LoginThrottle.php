<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Throttling for login attempts (Docs/PLAN.md 7.3).
 *
 * What this can and cannot do is worth being precise about, because the
 * obvious reason to rate-limit a login does not apply here.
 *
 * **It does not protect the data.** The password is the encryption key and is
 * never stored; there is nothing to compare a guess against, and anyone
 * holding a copy of `App/Data` attacks the archives offline at whatever speed
 * their hardware allows, with this code nowhere in the picture. Length is the
 * only defence there, which is what 7.0.1 is for.
 *
 * **What it does protect is the running service**, in two ways:
 *
 *  - **CPU and memory.** Every login attempt runs Argon2id (StashKey) —
 *    ~0.5 s and 256 MB, by design, whether or not the name has a stash behind
 *    it. There are 16 php-fpm workers. An unauthenticated
 *    attacker can spawn that work as fast as they can POST, and the whole app
 *    stops answering: exactly the lockup 4.28 was about, from a different
 *    direction. Past the limit this refuses *before* spawning anything, so a
 *    flood becomes cheaper for the server than for the attacker.
 *  - **Online guessing** against stashes made before the 24-character minimum.
 *    Login deliberately does not enforce that minimum, so older, weaker
 *    passwords still open — and those are guessable at 16 workers' worth of
 *    attempts per second in a way a 24-character one is not.
 *
 * Two rules fall out of MyStash having no password reset and no administrator:
 *
 *  - **Nothing here is ever permanent.** A stash is its password; a lockout
 *    that did not expire on its own would mean the owner had no way back in,
 *    ever. Every counter is windowed and ages out without intervention.
 *  - **It refuses rather than sleeps.** Delaying a response holds a php-fpm
 *    worker, and with 16 of them a sleep long enough to matter *is* the denial
 *    of service it was meant to prevent. The one exception is the constant
 *    time floor below, which is small and bounded.
 */
final class LoginThrottle
{
    /**
     * Failures older than this are forgotten entirely, and this is also how
     * long a locked-out name waits.
     *
     * Ten minutes is a compromise, and the thing being compromised is *this
     * user's* access, not an attacker's. There is no password reset and no
     * administrator, so the only way back into a locked stash is to wait —
     * which makes a long window a weapon anyone who knows the username can
     * point at the owner. Ten minutes is long enough to make sustained
     * guessing pointless and short enough to survive being triggered by
     * someone else.
     *
     * The lock cannot be extended by continuing to attack it: once a name is
     * locked, further attempts are refused *before* anything is recorded, so
     * no new failure timestamps accumulate and the window always closes on
     * schedule.
     */
    public const WINDOW_SECONDS = 600;

    /** Failures for one username within the window before it is refused. */
    public const MAX_PER_USER = 10;

    /**
     * Failures from one address within the window, across all usernames.
     *
     * Deliberately much higher than the per-user limit. Behind a reverse proxy
     * every request can share one apparent address — in the Docker setup they
     * all arrive from the gateway — so this is a backstop against spraying
     * many usernames from one source, never the primary control.
     */
    public const MAX_PER_ADDRESS = 50;

    /**
     * Every failed login takes at least this long, whatever the reason.
     *
     * Not a delay for its own sake: in v1, a wrong password against a real
     * stash took ~130ms (7z ran) and a wrong password against a name with no
     * stash took ~16ms (nothing ran). v2 runs Argon2id on both paths
     * (StashKey::unlock), so they no longer differ by much; this floor stays as
     * the backstop for whatever small difference is left. The replies were byte-identical, but the
     * clock told them apart, and that is a username oracle — the thing 7.1 says
     * login must not be. Bringing every failure up to the same floor closes it.
     *
     * Small enough that it cannot itself exhaust the worker pool: it is below
     * what the Argon2id every attempt pays already costs.
     */
    public const FLOOR_SECONDS = 0.25;

    private const ROOT = '/dev/shm/mystash-login';

    /**
     * How many seconds the caller must wait, or 0 if the attempt may proceed.
     *
     * Counts are keyed by username *and* by address, and a username with no
     * stash behind it is counted exactly like one that has: if unknown names
     * were not counted, the difference in behaviour would re-open the very
     * oracle FLOOR_SECONDS exists to close.
     */
    public static function retryAfter(string $user, string $address): int
    {
        $userWait = self::waitFor(self::key('u', $user), self::MAX_PER_USER);
        $addressWait = self::waitFor(self::key('a', $address), self::MAX_PER_ADDRESS);

        return max($userWait, $addressWait);
    }

    /**
     * The address limit alone, with no username in it.
     *
     * Registration has no existing username to count against, and passing an
     * empty one to retryAfter() would share a bucket with every failed login
     * that arrived without a username — so a few of those would block
     * registration for everybody. Separate method, no shared bucket.
     */
    public static function addressRetryAfter(string $address): int
    {
        return self::waitFor(self::key('a', $address), self::MAX_PER_ADDRESS);
    }

    public static function recordFailure(string $user, string $address): void
    {
        self::bump(self::key('u', $user));
        self::bump(self::key('a', $address));
    }

    /**
     * Counts one failed attempt against the address only.
     *
     * For registration, which has no existing username to count against. Only
     * failures are counted: behind a proxy every request can share one
     * apparent address, which makes this counter nearly global, and a counter
     * that ordinary successful use can fill is a lockout waiting to happen.
     */
    public static function recordAttempt(string $address): void
    {
        self::bump(self::key('a', $address));
    }

    /**
     * Forgets this user's failures after a successful login.
     *
     * The address counter is deliberately *not* cleared: one success does not
     * vouch for everything else coming from the same place, and on a shared
     * address it would hand an attacker a reset button.
     */
    public static function clear(string $user): void
    {
        @unlink(self::path(self::key('u', $user)));
    }

    /**
     * Holds a failed response until FLOOR_SECONDS have passed since $startedAt.
     *
     * Returns immediately if the work already took longer, so this only ever
     * levels the fast paths up to the slow one and never adds to the slowest.
     */
    public static function settle(float $startedAt): void
    {
        $remaining = self::FLOOR_SECONDS - (microtime(true) - $startedAt);

        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    /** @return int seconds to wait, 0 if under the limit */
    private static function waitFor(string $key, int $limit): int
    {
        $record = self::read($key);

        if ($record === null || count($record) < $limit) {
            return 0;
        }

        // The window runs from the oldest failure still being counted, so it
        // slides shut on its own rather than needing anything to reset it.
        $oldest = min($record);
        $waited = time() - $oldest;

        return $waited >= self::WINDOW_SECONDS ? 0 : self::WINDOW_SECONDS - $waited;
    }

    /**
     * Appends a failure timestamp, dropping any that have aged out.
     *
     * Read-modify-write under an exclusive lock: two failed logins arriving
     * together must count as two, and with 16 workers that is not hypothetical.
     */
    private static function bump(string $key): void
    {
        $path = self::path($key);
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $raw = stream_get_contents($handle);
            $times = json_decode($raw !== false ? $raw : '', true);
            $times = is_array($times) ? array_filter($times, 'is_int') : [];

            $cutoff = time() - self::WINDOW_SECONDS;
            $times = array_values(array_filter($times, static fn(int $t) => $t > $cutoff));
            $times[] = time();

            // Capped so a sustained flood cannot grow the file without bound;
            // anything past the limit is refused on the count alone anyway.
            $times = array_slice($times, -(self::MAX_PER_ADDRESS + 1));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($times));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);
        @chmod($path, 0600);
    }

    /** @return list<int>|null the failure timestamps still inside the window */
    private static function read(string $key): ?array
    {
        $path = self::path($key);

        if (!is_file($path)) {
            return null;
        }

        $times = json_decode((string) file_get_contents($path), true);

        if (!is_array($times)) {
            return null;
        }

        $cutoff = time() - self::WINDOW_SECONDS;

        return array_values(array_filter(
            array_filter($times, 'is_int'),
            static fn(int $t) => $t > $cutoff,
        ));
    }

    /**
     * Hashed, so the file names in /dev/shm are not a list of the usernames
     * and addresses that have been tried.
     */
    private static function key(string $kind, string $value): string
    {
        return $kind . '-' . substr(hash('sha256', $kind . "\0" . $value), 0, 32);
    }

    private static function path(string $key): string
    {
        if (!is_dir(self::ROOT)) {
            mkdir(self::ROOT, 0700, true);
        }

        if (!preg_match('/^[ua]-[0-9a-f]{32}$/', $key)) {
            throw new \InvalidArgumentException('Bad throttle key');
        }

        return self::ROOT . '/' . $key . '.json';
    }
}
