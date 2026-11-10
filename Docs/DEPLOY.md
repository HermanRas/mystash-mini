# Deploying MyStash

MyStash is a single container: nginx on port 8080 with PHP-FPM behind it on
the container's own loopback, and `ffmpeg` alongside them (plus `7z`, kept
only to upgrade v1 stashes — §8). There is no
database, no queue and no external service — the whole of a user's data is
encrypted files under `App/Data`, and the whole of the runtime state is in
`/dev/shm`.

That shape makes deployment short. It also concentrates every risk in two
places, so this document spends most of its length on those: **where the
plaintext goes** and **what a backup is worth without the password.**

---

## 1. What you need

| | |
| --- | --- |
| Docker Engine + Compose v2 | `docker compose version` should report v2.24 or newer — the production overlay uses `!override` and §2 uses `!reset`, neither of which is in older versions |
| Disk | the size of your library, plus room for one video at a time while it is being converted |
| RAM | 4 GB free for `/dev/shm` on top of whatever else the host does (see §6) |
| A host you trust | the password is typed into this app; the key derived from it is held in RAM for the session; neither is ever stored |
| RAM for logins | each login attempt runs Argon2id at 256 MB for ~0.5 s — a burst of simultaneous attempts costs 256 MB each (§4) |

Nothing else. No PHP, no ffmpeg and no 7-Zip on the host — they are in the
image.

---

## 2. The short version

```bash
git clone https://github.com/HermanRas/MyStash.git mystash && cd mystash
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Then open <http://127.0.0.1:8090> and create a stash.

That is a complete, working deployment **for one machine, used locally**. If
anyone reaches it over a network, read §4 before you tell them the address.

The repository is still cloned even though nothing is built from it: the
compose files and the `App/Data` directory come from it. The application code
does not — nor does `php.prod.ini`, which is baked into the image and applied
unless `MYSTASH_DEV=1`, so a deployment gets the hardened settings whether or
not it remembers to ask for them.

**If the pull is denied**, the GHCR package is private. Packages start private
even on a public repository. Either make it public in the package settings on
GitHub, or log in first with a personal access token that has `read:packages`:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <your-username> --password-stdin
```

There are two separate permissions here and they are easy to confuse:

| Symptom | Cause | Fix |
| --- | --- | --- |
| `docker pull` denied | the package is private | make it public, or `docker login` with `read:packages` |
| CI's push denied with `permission_denied: read_package` | the package is not linked to the repository, so the workflow's `GITHUB_TOKEN` has no rights over it | publish an image carrying `org.opencontainers.image.source` (the Dockerfile sets it), or add the repository under *Package settings → Manage Actions access* with the Write role |

The second one is worth knowing about because a workflow declaring
`packages: write` still hits it: that permission grants rights to packages
owned by the repository, and a package first created by a `docker push` from
someone's laptop is not one of them until something links it.

### What you are running, and how to build it yourself instead

`docker-compose.prod.yml` runs `ghcr.io/hermanras/mystash-mini`. Every push to
`main` builds that image, starts it, checks it serves the app and runs the
smoke suite, and only then publishes it (`.github/workflows/image.yml`) — so a
published tag is an artefact that has already been started once and talked to,
not just one that compiled.

Two tags are published:

| Tag | Use |
| --- | --- |
| `latest` | what the overlay pulls by default |
| `sha-<commit>` | pin a known-good build, and roll back to it |

The commit is the full 40-character SHA, as `git rev-parse HEAD` prints it:

```bash
MYSTASH_TAG=sha-$(git rev-parse HEAD) docker compose \
  -f docker-compose.yml -f docker-compose.prod.yml up -d
```

If CI is the thing that is broken, `dev/push_image.sh` builds the container on
your machine, runs the same verification, and pushes both tags — after
`echo "$GITHUB_TOKEN" | docker login ghcr.io -u HermanRas --password-stdin`
with a token that has `write:packages`. Nothing is pushed if a check fails.

Running an image you did not build is a real thing to weigh: you are trusting
GitHub's builder and the commit it built from, rather than a working tree you
can read. `sha-` tags exist so you can at least name exactly which commit is
running. To build on the host instead, drop the overlay's image and let the
base file's `build:` apply:

```yaml
# docker-compose.local.yml
services:
  app:
    image: !reset null
    build: ./App
```

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               -f docker-compose.local.yml up -d --build
```

That needs the code, so the `./App` mount the prod overlay removed is not
needed either way — the image built from `./App` already contains it.

---

## 3. Development versus production

There are three compose files and they layer:

| File | What it adds |
| --- | --- |
| `docker-compose.yml` | the app itself: the one `app` container, port 8090 on the host, the 4 GB `/dev/shm`, `MYSTASH_DEV=1` |
| `docker-compose.prod.yml` | the published GHCR image, loopback-only port, no code mount, `restart: unless-stopped`, `MYSTASH_DEV=0` |
| `docker-compose.dev.yml` | the Playwright container the `dev/` checks drive |

Used as:

```bash
# production
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# development, with the browser checks available
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

The base file on its own is the development configuration: the port is
published on every interface, the code is mounted writable, and PHP renders
its errors to the browser. That last one is the reason the overlay exists.

### One container, two processes

nginx and php-fpm run side by side inside the `app` container, started by
`App/docker-entrypoint.sh`. nginx reaches php-fpm on `127.0.0.1:9000`, which is
not published and not on the compose network — nothing outside the container
can speak FastCGI to it at all.

They are together because they are not independently useful: nginx here serves
one document root, out of the same files php-fpm executes, and neither is ever
restarted or scaled without the other.

The entrypoint exits as soon as *either* process does, which is deliberate. The
failure worth guarding against is half a container — nginx still answering on
8080 while php-fpm is dead, so every page is a 502 and `docker compose ps`
reports the service as up. Instead the container exits, and
`restart: unless-stopped` brings the whole thing back.

Both processes log to the container's stdout/stderr, so `docker compose logs -f
app` is the access log, the nginx error log and PHP's `error_log()` interleaved
in one stream.

### What the production overlay actually changes

**PHP stops talking to the browser.** A fatal error renders a stack trace, and
a PHP stack trace carries each frame's arguments — which in this app means the
user's password, truncated to fifteen characters, printed on the page. The
overlay sets `display_errors = Off` and sends errors to the container log
instead, and sets `zend.exception_ignore_args = 1` so the log does not collect
what the page no longer shows. Verify it:

```bash
docker compose exec app php -i | grep -E '^display_errors|^zend.exception_ignore_args'
```

**The code is not mounted at all — it is in the image.** The only writable
path is `App/Data`. Every persistent write this application makes lands there;
sessions, job records, decrypt staging and the login counters are all in
`/dev/shm`. Nothing writes to `App/src` or `App/public` at run time, so the
application never needed them writable. The code in the image is owned by
root and mode 0755, and php-fpm runs as `www-data`, so an upload that found a
way to place a file in the web root would be refused by the filesystem — and
would in any case be writing into a container layer, not onto the host.

The development stack still bind-mounts `./App` over `/app`, which is what
makes editing a file take effect without a rebuild. That mount is exactly what
the production overlay removes: leaving it would put whatever is in the
deployment host's working tree on top of the image that was pulled.

**The session cookie is hardened.** `HttpOnly`, `SameSite=Lax` and
`session.use_strict_mode`. The cookie is the key to a decrypted stash for as
long as the login lasts; it is worth three lines.

**The port is bound to `127.0.0.1`.** Not a firewall — a default that makes
exposing the app a decision rather than an accident. See §4.

---

## 4. Exposing it to a network

**There is no TLS in this application, and the password is the encryption
key.** Over plain HTTP it crosses the network in a form POST in the clear, and
so does every byte of decrypted video. On `127.0.0.1` that does not matter. On
anything else it is the whole ballgame.

If the app is to be reachable from another machine, put a TLS-terminating
proxy in front of it and leave MyStash bound to loopback. Caddy is two lines:

```
stash.example.internal {
    reverse_proxy 127.0.0.1:8090
}
```

or nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name stash.example.internal;
    ssl_certificate     /etc/ssl/stash.crt;
    ssl_certificate_key /etc/ssl/stash.key;

    # Videos are large and streamed; the app's own nginx allows 2G bodies and
    # this one has to agree or the upload dies at the outer hop.
    client_max_body_size 2G;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;

        # media.php streams byte ranges out of a decrypted file. Buffering
        # here stages the whole response before the first byte reaches the
        # browser, so seeking in a long video stalls until the entire file
        # has been decrypted.
        proxy_buffering off;

        # nginx gives up on a response after 60s by default. Nothing in the app
        # is meant to take that long any more — the hover preview, the slow
        # part of an upload, is a background job — but encrypting a multi-GB
        # upload into the stash still happens inside the request, and a 504
        # there tells the user the upload failed when it did not. Match the
        # app's own 600s ceiling (App/nginx.conf, App/php-fpm.conf).
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
    }
}
```

Then **uncomment `session.cookie_secure = 1`** in `App/php.prod.ini` and
rebuild — the file now ships inside the image, so editing your checkout alone
changes nothing in production until CI republishes it and you pull. To set it
without waiting for a build, add `- ./php-extra.ini:/usr/local/etc/php/conf.d/zzz-local.ini:ro`
to the compose file instead (`zzz` sorts after `zz-mystash-prod.ini`) and
restart. Do this only once TLS is actually in front: with it set, the
browser will not send the cookie over plain HTTP at all, and the symptom is a
login that appears to succeed and bounces straight back to the login screen.

Two things a proxy does not fix:

- **The login throttle becomes nearly global.** It keys on `REMOTE_ADDR`,
  which behind a proxy is the proxy. The per-address limit is deliberately
  loose (50 failures in 10 minutes) precisely because of this; the per-username
  limit of 10 is the one that does the work. If you want the per-address limit
  to mean anything, configure nginx's `real_ip` module in `App/nginx.conf` so
  `REMOTE_ADDR` is the client's — and only if you trust the header, which you
  do only if the proxy always sets it.
- **Anyone who can reach the login page can attempt a login**, and each attempt
  costs the server an Argon2id run — ~0.5 s of CPU and 256 MB of RAM, by
  design, whether or not the name exists. The throttle refuses before that work
  once the limits are hit, but sixteen simultaneous attempts still ask for
  4 GB. Exposing this to the open internet is not a thing to do casually.

---

## 5. Creating the first stash

Open the app and register. There is no administrator and no first-run setup:
a user *is* a directory plus archives encrypted with a key derived from their
password.

- Usernames are letters and digits, up to 32 characters.
- Passwords are at least 24 characters. Anyone holding a copy of `App/Data` can
  attack it offline; Argon2id makes each guess cost ~0.5 s and 256 MB, but only
  length makes the search too large to finish. A random password from a
  password manager is best: about 40 random characters already exceed the
  256-bit key, so more adds nothing.
- **There is no reset.** Not "an administrator has to do it" — there is no
  stored password to reset, no recovery key, and no copy of the plaintext
  anywhere. A forgotten password is a destroyed library. Say this out loud to
  anyone you hand a stash to.

To fill a stash with demo content instead — a dozen videos, three creators,
categories and playlists, all through the real endpoints:

```bash
dev/make_sample_clips.sh          # synthesises twelve clips
dev/seed_sample_data.sh           # uploads them as TestUser
```

Do that on a development instance, not on the machine holding real data.

---

## 6. Sizing and limits

**`/dev/shm` is 4 GB and that number is load-bearing.** Every decrypt stages
plaintext there: playing a video extracts it, converting one extracts it,
uploading one stages the whole original there while it encrypts, and re-keying
works through the whole stash. Docker's default is 64 MB, which is smaller than
a single video — extraction simply fails.

This is not a theoretical limit. A hand-written production compose file that
omitted `shm_size` entirely ran on the 64 MB default, and a 101 MB upload died
with `move_uploaded_file(): … errno=28 No space left on device` while the host
had 1.8 TB free — because the device that was full was the container's
`/dev/shm`, not the disk. **Any compose file you write by hand must carry
`shm_size`**; it is repeated in `docker-compose.prod.yml` and in the README
snippet for exactly that reason. Check a running container with:

```bash
docker exec mystash-mini-app df -h /dev/shm        # Size must not read 64M
docker inspect mystash-mini-app --format '{{.HostConfig.ShmSize}}'
```

4 GB is sized against the 2 GB upload cap below: a convert holds the original
*and* the output at once, so the ceiling has to be comfortably more than twice
the largest video you will hold. Raise both together if you raise either.
tmpfs allocates lazily, so a higher ceiling costs nothing until it is used —
but it is a ceiling on RAM, so do not set it near the host's total.

**Uploads are capped in two places** and both have to agree: `client_max_body_size`
in `App/nginx.conf` and `upload_max_filesize` / `post_max_size` in
`App/php.ini`, all 2 GB by default. A proxy in front makes a third (§4).

**PHP-FPM runs 16 workers.** A range request holds a worker for as long as the
player is pulling bytes, so a wall of hovering tiles plus one playing video is
several at once. Raise `pm.max_children` in `App/php-fpm.conf` if you have the
RAM and the viewers.

**Long jobs are detached.** Conversion and re-keying run in
`bin/job_worker.php` with no request behind them, and the browser polls
`job_status.php`. Closing the tab does not stop them; restarting the container
does.

---

## 7. Backups

`App/Data` is the entire library. Everything in it is already encrypted at
rest, which makes backing it up unusually simple and unusually unforgiving:

```bash
docker compose stop app          # so nothing is mid-write
tar -czf mystash-$(date +%F).tar.gz App/Data
docker compose start app
```

- **Back up while nothing is writing.** A backup taken during an upload or a
  re-key can catch a half-written archive. Stopping the container for the
  duration is the cheap way to be sure — and `docker compose stop app` is a
  clean stop: the entrypoint signals nginx and php-fpm to finish, and the
  container is down in under a second rather than being killed after Docker's
  ten-second grace period.
- **The password is not in the backup and cannot be recovered from it.** A
  backup of a stash whose password is forgotten is a folder of noise. Whatever
  you use to remember passwords, that is the other half of this backup.
- **A backup can be read without this app.** `tools/decode.php` (in the
  repository, not the image) decrypts a single `.epc` or a whole stash with
  only PHP and the password — `php tools/decode.php -F backup/App/Data/me/`.
  It is also the quickest way to test that a restore is real.
- **Test a restore.** Copy the tarball to a scratch machine, unpack it, bring
  the stack up and log in. A backup nobody has restored is a hypothesis.
- `/dev/shm` is never backed up, by design — it holds the only plaintext the
  system ever produces.

---

## 8. Upgrading

```bash
git pull   # the compose files
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

- **Note which of those two pulls matters.** `git pull` updates the compose
  files; `docker compose pull` updates the application — and now also
  `php.prod.ini`, which travels in the image. Doing only the first changes
  nothing about the code that runs.

- **Do not upgrade while a re-key is running.** Changing a stash's password
  rewrites every archive in it and keeps `.epc.old` copies until the run
  completes; killing the container mid-run leaves that half-done. The Profile
  screen shows progress — wait for it.
- **Restarting ends every session** (`/dev/shm` is cleared) and kills any
  in-flight conversion. A conversion can simply be run again; nothing is lost
  but the CPU time.
- The datastore format has changed before, and one-off migrations live in
  `App/bin/` (`migrate_categories.php`, `migrate_records.php`). If an upgrade
  needs one, it will say so in the release notes. Back up first.

### 8.1 v1 → v2.0.0

v2.0.0 changes the encryption of every file (7-Zip `.enc` → libsodium `.epc`).
**Back up `App/Data` first** (§7), then upgrade as above. Nothing is converted
until each stash's owner signs in: the right password starts a one-time
background upgrade with a progress page, and they sign in again when it
finishes. An operator can do it ahead of time instead:

```bash
docker compose exec -u www-data app php bin/migrate_v2.php {user}   # asks for the password
```

Until a stash has been upgraded it cannot be used, and its v1 files are not
touched until every v2 file has been written and verified. Plan disk space for
a second copy of the largest stash while it runs. The details, and what
changed, are in [v2_migration.md](v2_migration.md).

---

## 9. Verifying an install

```bash
docker compose exec -u www-data app php /app/tests/smoke_test.php
```

355 checks over encryption (including tampering and the v1 upgrade), ingestion, querying, re-keying, playlists and the
injection guards. It is self-contained: the one real video it needs is
synthesised with ffmpeg into tmpfs and thrown away afterwards, so this runs on
a fresh clone with an empty datastore.

The browser checks under `dev/` need the development overlay (they drive a
Playwright container) and a seeded `TestUser`:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
dev/run_font_check.sh        # nothing on the site is fetched from another host
dev/run_flow_check.sh        # the app flow in SPECIFICATIONS.md §2, end to end
dev/run_isolation_check.sh   # one stash cannot see another
```

`dev/run_font_check.sh` is the one worth running on a machine you are about to
expose: it fails if any page fetches anything from a host that is not this app.

---

## 10. What this deployment is not

Stated plainly, because the gap between "runs on my machine" and "runs for
other people" is where the surprises live:

- **No TLS of its own.** §4.
- **No multi-tenancy beyond the datastore.** Two stashes cannot read each
  other — that is enforced and tested — but they share one host, one CPU and
  one `/dev/shm`, and one user converting a 4K video is felt by the other.
- **No quotas.** Anyone with a stash can fill the disk.
- **No password reset, no account recovery, no administrator.** §5.
- **Not hardened against a hostile host.** The password passes through RAM at
  login, the derived key is in RAM (and the tmpfs session) for the session,
  and the plaintext is in `/dev/shm` while a video plays. Anyone with
  root on the machine can read both. The threat model is a stolen disk and a
  curious housemate, not a compromised server.
