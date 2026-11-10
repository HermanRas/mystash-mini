<?php

declare(strict_types=1);

namespace MyStash;

/**
 * The v1 archive format (`.enc`, 7z with AES-256), kept only to READ it.
 *
 * Since v2.0.0 every archive is written by StashCrypto as `.epc`
 * (Docs/v2_migration.md). The one thing that still needs 7z is MigrateV2,
 * which opens a v1 stash once, with the password, and rewrites it as v2.
 * encrypt() survives for the tests and dev scripts that have to build a v1
 * stash to prove that upgrade works; nothing in the app writes `.enc` any more.
 *
 * Once no v1 stash is left anywhere, this class and p7zip-full in the
 * Dockerfile can go.
 */
final class Crypto7z
{
    public function __construct(private string $binary = '7z')
    {
    }

    /**
     * Write a v1 archive. Test and dev use only — see the class comment.
     */
    public function encrypt(string $sourcePath, string $archivePath, string $password): bool
    {
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source path does not exist: {$sourcePath}");
        }

        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        $command = [
            $this->binary,
            'a',                    // add to archive
            '-t7z',                 // 7z format
            '-mx=0',                // store only, no compression
            '-mhe=on',              // encrypt headers (hides filenames too)
            '-p' . $password,       // password (no space, single argv token)
            '-y',                   // assume yes on prompts
            $archivePath,
            $sourcePath,
        ];

        return $this->run($command) === 0;
    }

    /**
     * Extract $archivePath (password-protected) into $destinationDir.
     * Returns false if the password is wrong or the archive is invalid —
     * this is how a v1 stash's password is checked before it is upgraded.
     */
    public function extract(string $archivePath, string $destinationDir, string $password): bool
    {
        if (!file_exists($archivePath)) {
            return false;
        }

        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0700, true);
        }

        $command = [
            $this->binary,
            'x',                     // extract with full paths
            '-p' . $password,
            '-y',
            '-o' . $destinationDir,
            $archivePath,
        ];

        return $this->run($command) === 0;
    }

    /**
     * Runs a command via proc_open with an argv array (never a shell string),
     * so the password can never be interpreted by a shell.
     */
    private function run(array $command): int
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start 7z process');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
