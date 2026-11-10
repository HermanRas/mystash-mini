# v1 → v2 migration

MyStash **v2.0.0** changes how every file in a stash is encrypted. The layout,
the features and your password stay the same; the archives do not.

## What we are moving from and to

| | v1 (up to v1.2.0) | v2 (v2.0.0) |
| --- | --- | --- |
| File extension | `.enc` | `.epc` |
| Container | 7-Zip archive, store mode (`-mx=0`), headers encrypted | MyStash's own `.epc` format ([SPECIFICATIONS §3.1](SPECIFICATIONS.md)) |
| Cipher | AES-256 (CBC) | XChaCha20-Poly1305, libsodium secretstream, 1 MiB chunks |
| Tamper detection | none: a changed byte decrypts into changed data | every chunk authenticated; changed, reordered, truncated or extended files are refused |
| Password → key | 7-Zip's SHA-256 × 2¹⁹, no salt | Argon2id, 256 MB, ~0.5 s, random per-stash salt |
| Password exposure | on the `7z` command line for every encrypt and decrypt | never on a command line; not kept in the session (only the derived key) |
| Engine | external `7z` process per file | PHP's built-in sodium extension, in-process (several times faster) |
| Read without the app | 7-Zip and the password | `tools/decode.php` and the password |

In short: AES-256 was never the weak point and nobody breaks either cipher.
What v2 fixes is everything around it — silent tampering, a fast unsalted
password hash, and the password showing up in the process list.

## How a stash is upgraded

1. **Back up `App/Data`** ([DEPLOY §7](DEPLOY.md)). The upgrade is built not
   to lose anything, but this is an encryption change to every file you have.
2. Pull and start v2.0.0 ([DEPLOY §8](DEPLOY.md)).
3. **Sign in as usual.** The app sees a v1 stash, checks the password with 7z,
   and starts the upgrade as a background job with a progress page. Closing the
   tab does not stop it.
4. When it says it is done, **sign in again with the same password.** That
   derives the new key and opens the v2 stash, which proves the upgrade worked.

Or, ahead of time, from the host (asks for the password, nothing on the
command line):

```bash
docker compose exec -u www-data app php bin/migrate_v2.php {user}
```

### What happens to the files

- Each `X.enc` is decrypted into RAM (`/dev/shm`) and written as `X.epc` beside
  it, under one new key. Each new file is decrypted again and compared byte for
  byte with the original before it is kept.
- **The `.enc` files are not touched until every `.epc` has been written and
  checked.** Any failure deletes the `.epc` files made so far and leaves the v1
  stash exactly as it was; signing in again retries it.
- The v2 index (`{user}.json.epc`) is written last; it is what marks the stash
  as v2. Only after that are the `.enc` files deleted.
- Free disk space needed while it runs: about the size of the stash being
  upgraded, since both copies exist until the end.

### Afterwards

- A v1 stash cannot be used until it is upgraded; there is no mixed mode.
- `7z` stays in the image only to read v1 for this upgrade. It will be removed
  in a later release once no v1 stash is left — upgrade every stash before then.
- Leftover v1 crash litter (`*.enc.old`, `*.enc.new.*` from an interrupted v1
  operation) is never read and can be deleted by hand.
- To go back to v1, restore the backup from step 1. v1 cannot read `.epc`.

## Reading your files without the app

[`tools/decode.php`](../tools/decode.php) is a single standalone PHP file (in
the repository, not the Docker image) that decrypts `.epc` archives with only
PHP 8 + sodium and the password:

```bash
php tools/decode.php -f file.epc -p 'password'            # one file, into the current directory
php tools/decode.php -F ~/folder-root/ -p 'password'      # a whole stash, structure kept
php tools/decode.php -F ~/folder-root/ -o ~/plain/        # no -p: asks without echo (safer)
```

`1.mp4.epc` is written as `1.mp4`, `1.jpg.preview.epc` as `1.preview.jpg`. A
wrong password, or a damaged or tampered file, is reported and nothing is
written for it. Quote the password in single quotes if it has `$`, `!` or
spaces, or leave `-p` out and type it at the prompt.

For v1 `.enc` files, use 7-Zip and the password, as before.
