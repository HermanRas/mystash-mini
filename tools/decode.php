<?php

declare(strict_types=1);

/**
 * decode.php — decrypt MyStash v2 archives (.epc) without MyStash.
 *
 * A standalone, single-file way to get your data out of a stash: no app, no
 * container, no database — just PHP 8.0+ (tested on 8.3) with the sodium
 * extension (bundled with PHP since 7.2) and your password. It deliberately
 * shares no code with the app, so it keeps working if the app does not.
 *
 * It is kept in the repository but NOT in the Docker image (the image is built
 * from App/ only).
 *
 * Usage:
 *   php decode.php -f file.epc        -p 'password' [-o out-dir]
 *   php decode.php -F ~/folder-root/  -p 'password' [-o out-dir]
 *
 *   -f FILE      decrypt one archive
 *   -F FOLDER    decrypt every *.epc under FOLDER (recursively), keeping
 *                the folder structure
 *   -p PASSWORD  the stash password. Leave it out to be asked for it without
 *                it echoing — a password on the command line ends up in your
 *                shell history and, while this runs, in the process list
 *   -o DIR       where to write the decrypted files. Default: the current
 *                directory for -f, ./<folder name>-decoded for -F
 *   --force      overwrite files that already exist in the output
 *   -h           this help
 *
 * Each archive is written under its own name without `.epc` — `1.mp4.epc`
 * becomes `1.mp4`, the index `{user}.json.epc` becomes `{user}.json` — except
 * previews, which keep their real extension last so they open:
 * `1.jpg.preview.epc` becomes `1.preview.jpg`, `1.mp4.preview.epc`
 * `1.preview.mp4`. (The name stored inside the archive is checked, but is an
 * internal one like `original.mp4`, and not used.)
 * Archives are authenticated: one that was changed, cut short or added to is
 * refused, and nothing is written for it.
 *
 * Format (see App/src/StashCrypto.php — this must stay in step with it):
 *   "MSEPC" | version 0x02 | kdf 0x02 (Argon2id) | opslimit u32be |
 *   memlimit u64be | salt[16] | secretstream header[24] |
 *   frames of (u32be length | XChaCha20-Poly1305 secretstream ciphertext):
 *   first the file name (bytes 0-34 as additional data), then the file in
 *   chunks of up to 1 MiB, the last one tagged FINAL.
 *
 * v1 archives (.enc) are 7z files: open those with 7-Zip and the password.
 */

const MAGIC = 'MSEPC';
const FORMAT_VERSION = 2;
const KDF_ARGON2ID = 2;
const PREAMBLE_BYTES = 35;
const HEADER_BYTES = 59;
const CHUNK_BYTES = 1024 * 1024;
const MAX_NAME_BYTES = 255;
const MIN_OPSLIMIT = 1;
const MAX_OPSLIMIT = 16;
const MIN_MEMLIMIT = 8 * 1024 * 1024;
const MAX_MEMLIMIT = 1024 * 1024 * 1024;

exit(main($argv));

function main(array $argv): int
{
    if (!extension_loaded('sodium')) {
        fwrite(STDERR, "This needs PHP's sodium extension (bundled with PHP 7.2+; on some distributions it is a separate package, e.g. php-sodium).\n");

        return 2;
    }

    $options = getopt('f:F:p:o:h', ['force', 'help']);

    if ($options === false || isset($options['h']) || isset($options['help'])
        || (isset($options['f']) === isset($options['F']))) {
        fwrite(STDERR, usage());

        return isset($options['h']) || isset($options['help']) ? 0 : 2;
    }

    $force = isset($options['force']);
    $password = $options['p'] ?? null;

    if (is_array($password) || is_array($options['f'] ?? null) || is_array($options['F'] ?? null) || is_array($options['o'] ?? null)) {
        fwrite(STDERR, "Give each option once.\n");

        return 2;
    }

    if ($password === null) {
        $password = askPassword();
    }

    if ($password === '') {
        fwrite(STDERR, "No password given.\n");

        return 2;
    }

    $keys = [];

    if (isset($options['f'])) {
        $file = (string) $options['f'];

        if (!is_file($file)) {
            fwrite(STDERR, "No such file: {$file}\n");

            return 2;
        }

        $out = (string) ($options['o'] ?? getcwd());
        $result = decodeFile($file, $out, $password, $force, $keys);
        report($file, $result);

        return $result['ok'] ? 0 : 1;
    }

    $root = rtrim((string) $options['F'], '/');

    if (!is_dir($root)) {
        fwrite(STDERR, "No such folder: {$root}\n");

        return 2;
    }

    $out = (string) ($options['o'] ?? (getcwd() . '/' . basename(realpath($root) ?: $root) . '-decoded'));
    $realRoot = realpath($root);
    $files = [];
    $skippedV1 = 0;

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($walk as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $path = $entry->getPathname();

        if (str_ends_with($path, '.epc')) {
            $files[] = $path;
        } elseif (str_ends_with($path, '.enc')) {
            $skippedV1++;
        }
    }

    sort($files);

    if ($files === []) {
        fwrite(STDERR, "No .epc archives under {$root}.\n");

        return $skippedV1 > 0 ? 1 : 0;
    }

    $failed = 0;

    foreach ($files as $file) {
        $relative = ltrim(substr(dirname(realpath($file) ?: $file), strlen((string) $realRoot)), '/');
        $result = decodeFile($file, $relative === '' ? $out : "{$out}/{$relative}", $password, $force, $keys);
        report($file, $result);
        $failed += $result['ok'] ? 0 : 1;
    }

    $done = count($files) - $failed;
    fwrite(STDERR, "\n{$done} of " . count($files) . " archive(s) decrypted into {$out}"
        . ($failed > 0 ? ", {$failed} failed" : '')
        . ($skippedV1 > 0 ? "; {$skippedV1} v1 .enc archive(s) skipped (open those with 7-Zip)" : '')
        . ".\n");

    return $failed > 0 ? 1 : 0;
}

/**
 * @param array<string, string> $keys derived keys, by salt and cost — a whole
 *        stash shares one, so Argon2id runs once rather than once per file
 * @return array{ok: bool, message: string}
 */
function decodeFile(string $archive, string $outDir, string $password, bool $force, array &$keys): array
{
    $in = @fopen($archive, 'rb');

    if ($in === false) {
        return ['ok' => false, 'message' => 'cannot be read'];
    }

    $tmp = null;
    $out = null;

    try {
        $header = fread($in, HEADER_BYTES);

        if (!is_string($header) || strlen($header) !== HEADER_BYTES || substr($header, 0, 5) !== MAGIC) {
            return ['ok' => false, 'message' => 'is not a MyStash v2 archive'];
        }

        if (ord($header[5]) !== FORMAT_VERSION || ord($header[6]) !== KDF_ARGON2ID) {
            return ['ok' => false, 'message' => 'uses a format version this script does not know'];
        }

        $costs = unpack('Nops/Jmem', substr($header, 7, 12));
        $ops = (int) $costs['ops'];
        $mem = (int) $costs['mem'];

        if ($ops < MIN_OPSLIMIT || $ops > MAX_OPSLIMIT || $mem < MIN_MEMLIMIT || $mem > MAX_MEMLIMIT) {
            return ['ok' => false, 'message' => 'asks for an Argon2id cost outside the sane range (damaged?)'];
        }

        $salt = substr($header, 19, 16);
        $cacheKey = bin2hex($salt) . ":{$ops}:{$mem}";
        $keys[$cacheKey] ??= sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, PREAMBLE_BYTES), $keys[$cacheKey]);

        $frame = readFrame($in, MAX_NAME_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
        $opened = $frame === null
            ? false
            : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame, substr($header, 0, PREAMBLE_BYTES));

        if ($opened === false) {
            return ['ok' => false, 'message' => 'wrong password, or the archive has been damaged'];
        }

        // The stored name is authenticated, so getting this far proves the
        // password; the output is named after the archive instead (see top).
        $name = outputName(basename($archive));

        if (!is_dir($outDir) && !mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            return ['ok' => false, 'message' => "cannot create {$outDir}"];
        }

        $target = "{$outDir}/{$name}";

        if (file_exists($target) && !$force) {
            return ['ok' => false, 'message' => "{$target} already exists (use --force to overwrite)"];
        }

        // Written beside the target and renamed in only once the last chunk
        // has authenticated, so a bad archive never leaves half a file.
        $tmp = "{$target}.decoding-" . bin2hex(random_bytes(4));
        $out = fopen($tmp, 'xb');

        if ($out === false) {
            $tmp = null;

            return ['ok' => false, 'message' => "cannot write into {$outDir}"];
        }

        chmod($tmp, 0600);

        while (true) {
            $frame = readFrame($in, CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);

            if ($frame === null) {
                return ['ok' => false, 'message' => 'is cut short (truncated)'];
            }

            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);

            if ($opened === false) {
                return ['ok' => false, 'message' => 'has been damaged or tampered with'];
            }

            [$plain, $tag] = $opened;

            if ($plain !== '' && fwrite($out, $plain) !== strlen($plain)) {
                return ['ok' => false, 'message' => 'could not be written out (disk full?)'];
            }

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                return ['ok' => false, 'message' => 'has an unexpected chunk (damaged?)'];
            }
        }

        if (fread($in, 1) !== '') {
            return ['ok' => false, 'message' => 'has data after its end (tampered with?)'];
        }

        fclose($out);
        $out = null;

        if (!rename($tmp, $target)) {
            return ['ok' => false, 'message' => "could not be saved as {$target}"];
        }

        $tmp = null;

        return ['ok' => true, 'message' => "-> {$target}"];
    } catch (SodiumException $error) {
        return ['ok' => false, 'message' => 'could not be decrypted (' . $error->getMessage() . ')'];
    } finally {
        fclose($in);

        if ($out !== null) {
            fclose($out);
        }

        if ($tmp !== null) {
            @unlink($tmp);
        }
    }
}

/** @param resource $handle */
function readFrame($handle, int $maxBytes): ?string
{
    $length = readExactly($handle, 4);

    if ($length === null) {
        return null;
    }

    $bytes = unpack('N', $length)[1];

    if ($bytes < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $bytes > $maxBytes) {
        return null;
    }

    return readExactly($handle, $bytes);
}

/** @param resource $handle */
function readExactly($handle, int $bytes): ?string
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

/**
 * `1.mp4.epc` → `1.mp4`; `1.jpg.preview.epc` → `1.preview.jpg`.
 */
function outputName(string $archiveName): string
{
    $name = substr($archiveName, 0, -strlen('.epc'));

    if (preg_match('/^(.+)\.([A-Za-z0-9]+)\.preview$/', $name, $m) === 1) {
        return "{$m[1]}.preview.{$m[2]}";
    }

    return $name;
}

function askPassword(): string
{
    fwrite(STDERR, 'Password: ');

    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);

    if ($tty && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
    }

    $line = fgets(STDIN);

    if ($tty && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty echo');
    }

    fwrite(STDERR, "\n");

    return rtrim((string) $line, "\r\n");
}

/** @param array{ok: bool, message: string} $result */
function report(string $archive, array $result): void
{
    fwrite($result['ok'] ? STDOUT : STDERR, ($result['ok'] ? 'ok    ' : 'FAIL  ') . "{$archive} {$result['message']}\n");
}

function usage(): string
{
    return <<<TXT
    Decrypt MyStash v2 archives (.epc).

      php decode.php -f file.epc       [-p PASSWORD] [-o OUT_DIR] [--force]
      php decode.php -F folder-root/   [-p PASSWORD] [-o OUT_DIR] [--force]

      -f FILE      decrypt one archive (default output: the current directory)
                   1.mp4.epc is written as 1.mp4, 1.jpg.preview.epc as 1.preview.jpg
      -F FOLDER    decrypt every *.epc under FOLDER, keeping its structure
                   (default output: ./<folder name>-decoded)
      -p PASSWORD  leave out to be prompted without echo (safer: a password on
                   the command line lands in shell history and the process list)
      -o OUT_DIR   where to write the decrypted files
      --force      overwrite files that already exist

    TXT;
}
