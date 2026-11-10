<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/User.php';

/**
 * Turns a password into the key every `.epc` archive is encrypted under
 * (Docs/SPECIFICATIONS.md §2.1, Docs/v2_migration.md).
 *
 * The password itself never encrypts anything. It goes through Argon2id once,
 * at login, with a random per-stash salt:
 *
 *     key = Argon2id(password, salt, OPSLIMIT, MEMLIMIT)   ~0.5 s, 256 MB
 *
 * and from then on the app works with the 32-byte key alone. That is the whole
 * point of the exercise: someone holding a copy of the archives has to pay
 * that half-second and quarter-gigabyte for every password they guess, and no
 * GPU can run thousands of those side by side. The salt makes the work
 * per-stash, so it cannot be done once and reused against everyone.
 *
 * The salt and cost are not secret. They are written into the header of every
 * archive (StashCrypto), so any one archive — and tools/decode.php — has all
 * it needs to re-derive the key from the password, with no side file to lose.
 *
 * Inside the app the key travels as a *token*: an opaque string carrying the
 * cost, the salt and the key, so that whoever encrypts with it can write the
 * matching header. The session and the job key files hold the token; nothing
 * holds the password once login has finished with it.
 */
final class StashKey
{
    /** Argon2id, as libsodium numbers it. Stored in each header. */
    public const ALG = SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;

    /** libsodium's "moderate": ~0.5 s and 256 MB on the hardware this runs on. */
    public const OPSLIMIT = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
    public const MEMLIMIT = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;

    public const SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;
    public const KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    /**
     * The costs a header is allowed to ask for. A header is read before
     * anything is authenticated, so these bound what a doctored file can make
     * a login spend — memory especially.
     */
    public const MIN_OPSLIMIT = 1;
    public const MAX_OPSLIMIT = 16;
    public const MIN_MEMLIMIT = 8 * 1024 * 1024;
    public const MAX_MEMLIMIT = 1024 * 1024 * 1024;

    private const TOKEN_PREFIX = 'epck1.';

    /**
     * A brand-new key for a new password: fresh salt, current cost.
     * Used when a stash is created, re-keyed, or upgraded from v1.
     */
    public static function create(string $password): string
    {
        $salt = random_bytes(self::SALT_BYTES);

        return self::token(self::OPSLIMIT, self::MEMLIMIT, $salt, self::derive($password, $salt, self::OPSLIMIT, self::MEMLIMIT));
    }

    /**
     * The key $password would give for $user's stash, derived with the salt
     * and cost recorded in the stash's index archive.
     *
     * This does not prove the password is right — only decrypting with the key
     * does that (Datastore::loadIndex). It returns null when there is no v2
     * index to take a salt from, and in that case still spends the same
     * Argon2id work on a throwaway salt: otherwise "no such stash" would answer
     * in microseconds and "wrong password" in half a second, and the
     * difference would tell anyone which usernames exist (Docs/PLAN.md 7.3).
     */
    public static function unlock(string $user, string $password): ?string
    {
        $header = User::isValidName($user)
            ? StashCrypto::readHeader(Datastore::indexArchivePath($user))
            : null;

        if ($header === null) {
            self::derive($password, random_bytes(self::SALT_BYTES), self::OPSLIMIT, self::MEMLIMIT);

            return null;
        }

        return self::forHeader($header, $password);
    }

    /**
     * The key $password gives under an already-parsed archive header.
     *
     * @param array{ops: int, mem: int, salt: string} $header
     */
    public static function forHeader(array $header, string $password): string
    {
        return self::token(
            $header['ops'],
            $header['mem'],
            $header['salt'],
            self::derive($password, $header['salt'], $header['ops'], $header['mem']),
        );
    }

    /**
     * Would $password produce exactly this key? Used where the app must check
     * a password it is handed against the session — the session has no
     * password to compare with, only the key, so the check is re-deriving.
     */
    public static function matches(string $token, string $password): bool
    {
        $parsed = self::parse($token);

        if ($parsed === null) {
            return false;
        }

        return hash_equals($parsed['key'], self::derive($password, $parsed['salt'], $parsed['ops'], $parsed['mem']));
    }

    /**
     * @return array{ops: int, mem: int, salt: string, key: string}|null
     */
    public static function parse(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        try {
            $raw = sodium_base642bin(
                substr($token, strlen(self::TOKEN_PREFIX)),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            );
        } catch (\SodiumException) {
            return null;
        }

        if (strlen($raw) !== 4 + 8 + self::SALT_BYTES + self::KEY_BYTES) {
            return null;
        }

        $costs = unpack('Nops/Jmem', substr($raw, 0, 12));

        return [
            'ops' => (int) $costs['ops'],
            'mem' => (int) $costs['mem'],
            'salt' => substr($raw, 12, self::SALT_BYTES),
            'key' => substr($raw, 12 + self::SALT_BYTES, self::KEY_BYTES),
        ];
    }

    public static function isAcceptableCost(int $ops, int $mem): bool
    {
        return $ops >= self::MIN_OPSLIMIT && $ops <= self::MAX_OPSLIMIT
            && $mem >= self::MIN_MEMLIMIT && $mem <= self::MAX_MEMLIMIT;
    }

    private static function token(int $ops, int $mem, string $salt, string $key): string
    {
        return self::TOKEN_PREFIX . sodium_bin2base64(
            pack('NJ', $ops, $mem) . $salt . $key,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    private static function derive(string $password, string $salt, int $ops, int $mem): string
    {
        if (!self::isAcceptableCost($ops, $mem)) {
            throw new \InvalidArgumentException('Refusing an Argon2id cost outside the accepted range');
        }

        return sodium_crypto_pwhash(self::KEY_BYTES, $password, $salt, $ops, $mem, self::ALG);
    }
}
