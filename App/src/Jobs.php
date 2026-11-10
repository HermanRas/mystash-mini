<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Detached background jobs, and the record of how they are getting on
 * (Docs/PLAN.md 4.28, 6.6).
 *
 * Two pieces of work in this app take minutes rather than milliseconds —
 * transcoding a video and re-keying a whole stash — and both used to run
 * *inside* the request that asked for them. That is wrong for the obvious
 * reason (the browser sits on a pending request until it finishes) and for a
 * worse one: it was the single-threaded PHP server, so the entire site queued
 * behind the transcode and stopped answering at all.
 *
 * So: the request writes a job record, spawns a worker detached from itself,
 * and returns immediately. The worker does the work and writes its progress
 * into the record. The page polls a small endpoint and draws a bar.
 *
 * Everything lives in tmpfs, never on disk:
 *
 *  - `{id}.json`  the public record — state, counts, message. Never secrets.
 *  - `{id}.key`   the secrets the worker needs, mode 0600, deleted by the
 *                 worker the moment it has read them. Usually the stash key
 *                 (StashKey); a password only for a re-key (the new one) and
 *                 the v1 → v2 upgrade, which have to derive a key from it.
 *  - `{id}.lock`  held under an exclusive flock for the worker's whole life,
 *                 which is how anyone else can tell a worker is still alive
 *                 without trusting the record to be honest about it.
 *
 * Putting the key in a tmpfs file is not a new exposure: the session that
 * started the job is *already* a file in /dev/shm holding the same key
 * (see Session). What matters is that it is RAM — gone on container restart,
 * never written to the persistent volume — and that it is not on the worker's
 * argv, where `ps` would show it to anything else in the container.
 */
final class Jobs
{
    public const ROOT = '/dev/shm/mystash-jobs';

    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** Finished records are kept this long so a poll can still read the outcome. */
    private const KEEP_FINISHED_SECONDS = 900;

    /**
     * How long a just-started job is allowed to have no worker holding its lock.
     *
     * start() returns as soon as the shell that will launch the worker has
     * exited, which is before the worker itself has got as far as taking the
     * lock. Without this window, the redirect straight after pressing Convert
     * would find no live worker and report the job as having died — every time.
     */
    private const SPAWN_GRACE_SECONDS = 15;

    /**
     * The id is derived from what the job is *about*, not randomly, so that
     * pressing Convert twice finds the first job instead of starting a second
     * one over the top of it. That guard is the point: two ffmpeg runs writing
     * the same archive, or two re-keys over the same stash, would destroy it.
     */
    public static function id(string $kind, string $user, string $target = ''): string
    {
        return $kind . '-' . substr(hash('sha256', $user . "\0" . $target), 0, 16);
    }

    /**
     * The job record, or null if there has never been one (or it has aged out).
     *
     * The returned record carries an `alive` flag that comes from the lock file
     * rather than from the record's own `state`. A container restart or a
     * killed worker leaves a record that still says "running" forever; the lock
     * is the only thing that actually knows.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $id): ?array
    {
        $path = self::path($id, 'json');

        if (!is_file($path)) {
            return null;
        }

        $record = json_decode((string) file_get_contents($path), true);

        if (!is_array($record)) {
            return null;
        }

        $record['alive'] = self::isAlive($id);

        // A record that claims to be running with no worker behind it is a
        // worker that died without saying so — report that rather than a
        // progress bar that will never move again. The lock is definitive
        // (the kernel releases it however the process exits), so the only
        // false alarm to guard against is a worker that has not started yet.
        $starting = time() - (int) ($record['started_at'] ?? 0) < self::SPAWN_GRACE_SECONDS;

        if ($record['state'] === self::RUNNING && !$record['alive'] && !$starting) {
            $record['state'] = self::FAILED;
            $record['message'] = 'The job stopped unexpectedly. Nothing was changed that cannot be retried.';
        }

        return $record;
    }

    public static function isAlive(string $id): bool
    {
        $path = self::path($id, 'lock');

        if (!is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            return false;
        }

        // If the lock can be taken, nobody is holding it, so no worker is
        // running. Take it and give it straight back.
        $free = flock($handle, LOCK_EX | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return !$free;
    }

    /**
     * Starts a worker, unless one is already running for this exact job.
     *
     * @param array<string, string> $secrets  passwords the worker needs; never
     *        written to the record, never passed on argv
     * @param array<string, mixed> $seed  initial public fields (total, label…)
     * @return array{ok: bool, id: string, error: string}
     */
    public static function start(
        string $kind,
        string $user,
        string $target,
        array $secrets,
        array $seed = [],
    ): array {
        self::prune();

        $id = self::id($kind, $user, $target);

        if (self::isAlive($id)) {
            return ['ok' => false, 'id' => $id, 'error' => 'That job is already running.'];
        }

        self::write($id, [
            'id' => $id,
            'kind' => $kind,
            'user' => $user,
            'target' => $target,
            'state' => self::RUNNING,
            'done' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Starting…',
            'started_at' => time(),
            'updated_at' => time(),
            'pid' => 0,
            ...$seed,
        ]);

        // 0600 and short-lived: the worker deletes it as soon as it has read it.
        $keyPath = self::path($id, 'key');
        $handle = fopen($keyPath, 'w');
        if ($handle === false) {
            return ['ok' => false, 'id' => $id, 'error' => 'Could not stage the job.'];
        }
        chmod($keyPath, 0600);
        fwrite($handle, (string) json_encode($secrets));
        fclose($handle);

        // The lock file has to exist before the worker is spawned, so that a
        // second start racing this one cannot decide "no lock file, no job".
        touch(self::path($id, 'lock'));

        self::spawn($id);

        return ['ok' => true, 'id' => $id, 'error' => ''];
    }

    /**
     * Launches the worker genuinely detached.
     *
     * `setsid` puts it in its own session so it does not die with the request,
     * and the trailing `&` lets the intermediate shell exit at once — which is
     * what makes this return immediately instead of waiting for the transcode
     * it just started. Output goes nowhere: progress is reported through the
     * job record, and anything worth keeping goes through error_log().
     */
    private static function spawn(string $id): void
    {
        $command = sprintf(
            'setsid %s %s %s >/dev/null 2>&1 &',
            escapeshellarg(self::phpBinary()),
            escapeshellarg(dirname(__DIR__) . '/bin/job_worker.php'),
            escapeshellarg($id),
        );

        exec('/bin/sh -c ' . escapeshellarg($command));
    }

    /**
     * The PHP *command line* binary.
     *
     * Not PHP_BINARY: under the FPM SAPI that is `php-fpm` itself, which would
     * launch a FastCGI server rather than run the worker script. PHP_BINARY is
     * right only when this is already being called from the CLI, which is how
     * the smoke tests reach it.
     */
    private static function phpBinary(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        return is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
    }

    /**
     * Worker side: take the passwords and destroy the copy on the way out, so
     * they exist only in the worker's memory for the rest of the run.
     *
     * @return array<string, string>|null
     */
    public static function claimSecrets(string $id): ?array
    {
        $path = self::path($id, 'key');

        if (!is_file($path)) {
            return null;
        }

        $secrets = json_decode((string) file_get_contents($path), true);
        unlink($path);

        return is_array($secrets) ? $secrets : null;
    }

    /**
     * Worker side: hold the liveness lock for the rest of this process.
     *
     * The handle is deliberately never closed — the lock is released by the
     * kernel when the worker exits, however it exits, which is exactly the
     * behaviour wanted. A worker killed with -9 still releases it.
     *
     * @return resource|false
     */
    public static function hold(string $id)
    {
        $handle = fopen(self::path($id, 'lock'), 'c');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }

        return $handle;
    }

    /**
     * The kind of job this user currently has running, or null if none is.
     *
     * Needed because job ids are hashes of what the job is about, so there is
     * no way to ask "does this user have anything running" other than to look
     * at the records. Deleting a stash out from under a running worker would
     * let it write files back into the directory just removed — v1's 7zip
     * created missing parents — leaving a half-resurrected stash behind.
     */
    public static function aliveFor(string $user): ?string
    {
        foreach (glob(self::ROOT . '/*.json') ?: [] as $path) {
            $record = json_decode((string) file_get_contents($path), true);

            if (!is_array($record) || ($record['user'] ?? '') !== $user) {
                continue;
            }

            if (self::isAlive(basename($path, '.json'))) {
                return (string) ($record['kind'] ?? 'unknown');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function update(string $id, array $fields): void
    {
        $record = json_decode((string) @file_get_contents(self::path($id, 'json')), true);

        if (!is_array($record)) {
            return;
        }

        self::write($id, [...$record, ...$fields, 'updated_at' => time()]);
    }

    public static function finish(string $id, bool $ok, string $message): void
    {
        // A failure leaves `percent` wherever it got to, which is the honest
        // thing to show: "it stopped at 40%" is information, and moving the bar
        // to 100 to tidy it up would not be.
        self::update($id, [
            'state' => $ok ? self::DONE : self::FAILED,
            'message' => $message,
            ...($ok ? ['percent' => 100] : []),
        ]);
    }

    public static function forget(string $id): void
    {
        foreach (['json', 'key', 'lock'] as $extension) {
            @unlink(self::path($id, $extension));
        }
    }

    /**
     * Drops finished records once nobody could still be watching them, and any
     * record left behind by a worker that is no longer running.
     */
    public static function prune(): void
    {
        foreach (glob(self::ROOT . '/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            $record = json_decode((string) file_get_contents($path), true);

            if (!is_array($record)) {
                self::forget($id);
                continue;
            }

            $finished = $record['state'] !== self::RUNNING || !self::isAlive($id);

            if ($finished && time() - (int) ($record['updated_at'] ?? 0) > self::KEEP_FINISHED_SECONDS) {
                self::forget($id);
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function write(string $id, array $record): void
    {
        // Written beside and renamed in, so a poll never reads half a record.
        $path = self::path($id, 'json');
        $pending = $path . '.' . bin2hex(random_bytes(4));

        file_put_contents($pending, (string) json_encode($record));
        rename($pending, $path);
    }

    private static function path(string $id, string $extension): string
    {
        if (!is_dir(self::ROOT)) {
            mkdir(self::ROOT, 0700, true);
        }

        // Ids are built by id() from a hash, so this can only ever be a plain
        // name — but this is a path assembled for a filesystem, and saying so
        // costs nothing.
        if (!preg_match('/^[a-z]+-[0-9a-f]{16}$/', $id)) {
            throw new \InvalidArgumentException('Bad job id');
        }

        return self::ROOT . '/' . $id . '.' . $extension;
    }
}
