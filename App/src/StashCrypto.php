<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashKey.php';

/**
 * Reads and writes `.epc` archives — the v2 at-rest format
 * (Docs/SPECIFICATIONS.md §3.1, Docs/v2_migration.md).
 *
 * Replaces the 7z archives of v1 (`.enc`). The cipher is libsodium's
 * secretstream: XChaCha20-Poly1305 over 1 MiB chunks. What that buys over 7z:
 *
 *  - **Authenticated.** Every chunk carries a Poly1305 tag. A changed byte, a
 *    swapped or reordered chunk, a truncated file or anything appended after
 *    the end are all refused rather than decrypted into something subtly
 *    wrong. 7z's AES had no such check.
 *  - **Argon2id, salted.** The key comes from StashKey, not from 7z's fixed,
 *    unsalted SHA-256 rounds.
 *  - **In-process.** No 7z child process, so no password — or key — on any
 *    process's argv, where `ps` could read it.
 *
 * Layout (all integers big-endian):
 *
 *     offset  size  field
 *     0       5     magic "MSEPC"
 *     5       1     format version, 2
 *     6       1     KDF algorithm (libsodium's ARGON2ID13, 2)
 *     7       4     Argon2id opslimit
 *     11      8     Argon2id memlimit, bytes
 *     19      16    Argon2id salt
 *     35      24    secretstream header
 *     59      ...   frames: u32 length + ciphertext
 *
 *  - frame 1 is the original file name (what 7z kept, and hid, with -mhe=on),
 *    encrypted with bytes 0-34 as additional data so the costs and salt
 *    cannot be edited without the archive refusing to open;
 *  - every later frame is up to 1 MiB of the file, and the last one carries
 *    the FINAL tag — an archive that ends without it was cut short.
 *
 * tools/decode.php reads the same format with no dependency on this code.
 * Change one and the other has to change with it.
 */
final class StashCrypto
{
    public const MAGIC = 'MSEPC';
    public const VERSION = 2;
    public const EXTENSION = '.epc';

    private const PREAMBLE_BYTES = 35;
    private const HEADER_BYTES = self::PREAMBLE_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
    private const CHUNK_BYTES = 1024 * 1024;
    private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    private const MAX_NAME_BYTES = 255;

    /**
     * Encrypt the file $sourcePath into a new archive at $archivePath under
     * $key (a StashKey token).
     *
     * Like v1's, this deletes its destination before writing it, and a failure
     * leaves nothing there at all. Never aim it at an archive that is the only
     * copy of something — that is what replace() is for.
     */
    public function encrypt(string $sourcePath, string $archivePath, string $key): bool
    {
        if (!is_file($sourcePath)) {
            throw new \InvalidArgumentException("Source is not a file: {$sourcePath}");
        }

        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        $parsed = StashKey::parse($key);
        $name = basename($sourcePath);

        if ($parsed === null || !self::isSafeName($name)) {
            return false;
        }

        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            return false;
        }

        $out = fopen($archivePath, 'xb');

        if ($out === false) {
            fclose($in);

            return false;
        }

        chmod($archivePath, 0600);

        $ok = false;

        try {
            $preamble = self::MAGIC . chr(self::VERSION) . chr(StashKey::ALG)
                . pack('NJ', $parsed['ops'], $parsed['mem']) . $parsed['salt'];

            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($parsed['key']);

            if (!self::write($out, $preamble . $streamHeader)) {
                return false;
            }

            $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, $name, $preamble);

            if (!self::write($out, pack('N', strlen($frame)) . $frame)) {
                return false;
            }

            // One chunk of look-ahead, so the last chunk can be tagged FINAL as
            // it is written — including the single empty FINAL chunk of an
            // empty file.
            $current = self::readChunk($in);

            while (true) {
                if ($current === null) {
                    return false;
                }

                $next = self::readChunk($in);
                $final = $next === '';

                $frame = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $current,
                    '',
                    $final
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );

                if (!self::write($out, pack('N', strlen($frame)) . $frame)) {
                    return false;
                }

                if ($final) {
                    break;
                }

                $current = $next;
            }

            $ok = fflush($out);

            return $ok;
        } finally {
            fclose($in);
            fclose($out);

            if (!$ok) {
                @unlink($archivePath);
            }
        }
    }

    /**
     * Replaces a live archive with a freshly encrypted $sourceFile, without
     * ever leaving the old one missing or half-written (Docs/PLAN.md 4.29).
     *
     * encrypt() deletes its destination before writing it, so aiming it
     * straight at an archive that is the only copy of a video opens a window
     * where that video does not exist at all — and if the write then fails,
     * returns false unnoticed, or is interrupted, it never comes back. That is
     * the bug this method exists to make unavailable.
     *
     * So: write beside the archive, prove the new file really opens and holds
     * the same bytes, and only then swap it in — keeping the previous bytes as
     * `.epc.old` until the swap has succeeded, and putting them back if
     * anything goes wrong. The same write-verify-rotate shape Rekey uses.
     *
     * $sourceFile must be a single file (every caller re-encrypts one), so the
     * verification can compare it byte for byte.
     */
    public function replace(string $sourceFile, string $archivePath, string $key): bool
    {
        if (!is_file($sourceFile)) {
            return false;
        }

        // The pending name is unique per call: two requests saving the same
        // archive at once would otherwise write over each other's half-built
        // file and swap in a mixture. (They can still race on the swap itself
        // — that is what the lock in 6.6 is for.)
        $pending = $archivePath . '.new.' . bin2hex(random_bytes(4));
        $backup = $archivePath . '.old';

        if (!$this->encrypt($sourceFile, $pending, $key)) {
            @unlink($pending);

            return false;
        }

        // An encrypt that reported success can still have produced something
        // that will not open — a full disk truncates happily. Checking now
        // costs one decryption; not checking costs the video.
        if (!$this->holdsSameBytes($pending, $sourceFile, $key)) {
            @unlink($pending);

            return false;
        }

        if (file_exists($archivePath) && !rename($archivePath, $backup)) {
            @unlink($pending);

            return false;
        }

        if (!rename($pending, $archivePath)) {
            // Put the original back before giving up.
            if (file_exists($backup)) {
                rename($backup, $archivePath);
            }
            @unlink($pending);

            return false;
        }

        if (file_exists($backup)) {
            unlink($backup);
        }

        return true;
    }

    /**
     * Decrypt $archivePath into $destinationDir, under the file name it was
     * encrypted with.
     *
     * Returns false — and leaves no partial plaintext behind — if the key is
     * wrong, the archive belongs to a different key, or anything about it has
     * been changed, cut short or added to. A wrong key failing here is also
     * how login verification works.
     */
    public function extract(string $archivePath, string $destinationDir, string $key): bool
    {
        $parsed = StashKey::parse($key);
        $header = self::readHeader($archivePath);

        // A key from a different salt or cost cannot open this archive, so
        // there is no point running the cipher to find that out.
        if (
            $parsed === null || $header === null
            || !hash_equals($header['salt'], $parsed['salt'])
            || $header['ops'] !== $parsed['ops'] || $header['mem'] !== $parsed['mem']
        ) {
            return false;
        }

        $in = fopen($archivePath, 'rb');

        if ($in === false) {
            return false;
        }

        $out = null;
        $outPath = null;
        $ok = false;

        try {
            fseek($in, self::HEADER_BYTES);

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header['stream'], $parsed['key']);

            $first = self::readFrame($in, self::MAX_NAME_BYTES + self::ABYTES);
            $opened = $first === null
                ? false
                : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $first, $header['preamble']);

            if ($opened === false) {
                return false;
            }

            [$name, $tag] = $opened;

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || !self::isSafeName($name)) {
                return false;
            }

            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0700, true);
            }

            $outPath = $destinationDir . '/' . $name;
            $out = fopen($outPath, 'wb');

            if ($out === false) {
                $outPath = null;

                return false;
            }

            while (true) {
                $frame = self::readFrame($in, self::CHUNK_BYTES + self::ABYTES);

                // Running out of frames before the FINAL tag is truncation.
                if ($frame === null) {
                    return false;
                }

                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);

                if ($opened === false) {
                    return false;
                }

                [$plain, $tag] = $opened;

                if ($plain !== '' && !self::write($out, $plain)) {
                    return false;
                }

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }

                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    return false;
                }
            }

            // Nothing may follow the FINAL chunk.
            if (fread($in, 1) !== '') {
                return false;
            }

            $ok = fflush($out);

            return $ok;
        } finally {
            fclose($in);

            if ($out !== null) {
                fclose($out);
            }

            if (!$ok && $outPath !== null) {
                @unlink($outPath);
            }
        }
    }

    /**
     * The unauthenticated part of an archive's header: which salt and cost its
     * key was derived with. Null if this is not a v2 archive at all.
     *
     * Nothing here can be trusted until a frame has decrypted — the preamble
     * is authenticated as frame 1's additional data — so it is only ever used
     * to decide which key to *try*, never as an answer by itself.
     *
     * @return array{ops: int, mem: int, salt: string, preamble: string, stream: string}|null
     */
    public static function readHeader(string $archivePath): ?array
    {
        if (!is_file($archivePath)) {
            return null;
        }

        $handle = fopen($archivePath, 'rb');

        if ($handle === false) {
            return null;
        }

        $bytes = fread($handle, self::HEADER_BYTES);
        fclose($handle);

        if (
            !is_string($bytes) || strlen($bytes) !== self::HEADER_BYTES
            || substr($bytes, 0, 5) !== self::MAGIC
            || ord($bytes[5]) !== self::VERSION
            || ord($bytes[6]) !== StashKey::ALG
        ) {
            return null;
        }

        $costs = unpack('Nops/Jmem', substr($bytes, 7, 12));
        $ops = (int) $costs['ops'];
        $mem = (int) $costs['mem'];

        if (!StashKey::isAcceptableCost($ops, $mem)) {
            return null;
        }

        return [
            'ops' => $ops,
            'mem' => $mem,
            'salt' => substr($bytes, 19, StashKey::SALT_BYTES),
            'preamble' => substr($bytes, 0, self::PREAMBLE_BYTES),
            'stream' => substr($bytes, self::PREAMBLE_BYTES),
        ];
    }

    /**
     * True when $archivePath decrypts to exactly one file identical to
     * $expectedFile. Used to prove a replacement archive before it is trusted
     * with the only copy of something.
     */
    private function holdsSameBytes(string $archivePath, string $expectedFile, string $key): bool
    {
        $scratch = $this->scratchDir();

        try {
            if (!$this->extract($archivePath, $scratch, $key)) {
                return false;
            }

            $extracted = glob("{$scratch}/*") ?: [];

            if (count($extracted) !== 1 || !is_file($extracted[0])) {
                return false;
            }

            return filesize($extracted[0]) === filesize($expectedFile)
                && hash_file('sha256', $extracted[0]) === hash_file('sha256', $expectedFile);
        } finally {
            array_map('unlink', glob("{$scratch}/*") ?: []);
            rmdir($scratch);
        }
    }

    /**
     * A private tmpfs directory for verification plaintext.
     *
     * This deliberately does not call Datastore::tmpfsWorkDir(): Datastore
     * depends on this class, and the dependency should not run both ways.
     */
    private function scratchDir(): string
    {
        $root = '/dev/shm/mystash-verify';

        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }

        $dir = $root . '/' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * The stored name becomes a path segment on extract, so it must be a bare
     * file name — this is the check that keeps a crafted archive from writing
     * outside the directory it is extracted into.
     */
    private static function isSafeName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..'
            && strlen($name) <= self::MAX_NAME_BYTES
            && strpbrk($name, "/\\\0") === false;
    }

    /**
     * Up to one chunk of $handle: '' at the end of the file, null on a read
     * error.
     *
     * @param resource $handle
     */
    private static function readChunk($handle): ?string
    {
        $chunk = '';

        while (strlen($chunk) < self::CHUNK_BYTES && !feof($handle)) {
            $read = fread($handle, self::CHUNK_BYTES - strlen($chunk));

            if ($read === false) {
                return null;
            }

            $chunk .= $read;
        }

        return $chunk;
    }

    /**
     * One length-prefixed frame, or null if the file ends early or the length
     * is not one a writer could have produced.
     *
     * @param resource $handle
     */
    private static function readFrame($handle, int $maxBytes): ?string
    {
        $length = self::readExactly($handle, 4);

        if ($length === null) {
            return null;
        }

        $bytes = unpack('N', $length)[1];

        if ($bytes < self::ABYTES || $bytes > $maxBytes) {
            return null;
        }

        return self::readExactly($handle, $bytes);
    }

    /** @param resource $handle */
    private static function readExactly($handle, int $bytes): ?string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            $read = fread($handle, $bytes - strlen($data));

            if ($read === false || $read === '') {
                return null;
            }

            $data .= $read;
        }

        return $data;
    }

    /** @param resource $handle */
    private static function write($handle, string $bytes): bool
    {
        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $count = fwrite($handle, substr($bytes, $written));

            if ($count === false || $count === 0) {
                return false;
            }

            $written += $count;
        }

        return true;
    }
}
