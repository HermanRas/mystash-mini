# MyStash Mini

A variation of [MyStash](../MyStash) — same encrypted, self-hosted stash, cut
down to one flat library of videos **and pictures**:

- **No creators.** Everything is credited to `default`; the Creators screen,
  filter and edit fields are gone from the UI (the code is still there).
- **The wall is three large tiles wide** (two on a tablet, one on a phone), and
  **the filter panel starts closed**. Every type is shown by default, Shorts
  and Pictures included.
- **Bulk upload.** Pick any number of videos and pictures at once; they upload
  one after another with a per-file list, and a file that fails fails alone.
  Pictures (JPEG, PNG, GIF, WebP, BMP, AVIF) are stored encrypted as
  `Video{ID}/{ID}.img.enc` beside a JPEG tile, with `kind: picture` in the index.
- **Paging viewer.** Opening any tile shows ‹ › arrows; ← / → page through the
  wall in its current filter and sort order, and Esc goes back to it.
- Dev and prod both listen on **8090** (8080 is MyStash's), and the image is
  `ghcr.io/hermanras/mystash-mini`. Never point Mini and MyStash at the same
  data folder: MyStash does not know what a picture is.
- **Upstream fixes:** this repo carries MyStash's history, with MyStash as the
  `upstream` remote — `git fetch upstream && git merge upstream/main`.
  `dev/playwright/check_mini.js` checks all of the above in a real browser.

Everything below is the original MyStash README.

---

# MyStash v1.0.0

![MyStash](Docs/Assets/Banner.jpg)

A private, self-hosted video wall for a personal library. Everything is
encrypted at rest with your password, which is never stored anywhere — and
nothing on any page is fetched from anyone else's server.


*DEV*

```bash
git clone https://github.com/HermanRas/mystash-mini.git mystash-mini && cd mystash-mini
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

*PROD*

```yml
services:
  app:
    image: ghcr.io/hermanras/mystash-mini:latest
    ports:
      - "127.0.0.1:8090:8080"
    container_name: mystash-mini-app
    volumes:
      - /path/to/your/data:/app/Data
    # Not optional. Every decrypt, and every upload, stages plaintext in
    # /dev/shm; Docker's default is 64M, which is smaller than one video.
    # Leave this out and uploads fail with "No space left on device" while
    # the host still has terabytes free. See Docs/DEPLOY.md §6.
    shm_size: 4gb
    restart: unless-stopped
```

Then open <http://127.0.0.1:8090> and create a stash. Full instructions,
including what to do before anyone else can reach it, are in
[Docs/DEPLOY.md](Docs/DEPLOY.md).

---

## The idea

A stash is a directory and a password. There is no account record, no stored
password hash, and no administrator. The password **is** the AES key: it opens
a 7-Zip archive holding your video index, and if the archive does not decrypt,
the login has failed. Nothing else is consulted, because there is nothing else.

Everything follows from that:

- **Every file on disk is encrypted** — the videos, the preview clips, the
  thumbnails, the metadata, the creator records and their pictures.
- **Plaintext exists only in RAM.** Decryption stages into `/dev/shm` for as
  long as a video is playing or converting, and is wiped after.
- **Two stashes cannot see each other**, and that is tested rather than
  asserted.
- **A forgotten password is a destroyed library.** There is no reset. This is
  the cost of the guarantee above, and it is not negotiable after the fact.

It runs as one container — nginx, PHP-FPM, `ffmpeg` and `7z` — and needs
nothing else. No database, no queue, no cloud account, no CDN.

---

## The wall

![The video wall](Docs/Assets/README/03_wall.png)

The wall is the front page: a searchable, filterable, sortable grid. Each tile
carries a thumbnail, a quality badge derived from the file's own height
(SD / HD / Full HD / 4K), the running time, the view count, the creators
credited and the categories assigned.

([the whole wall, full page](Docs/Assets/README/03_wall_full.png))

**Hover a tile and it previews.** Not the first few seconds — a timelapse built
at upload time that samples frames across the whole video, so a hover skims
what the video *is* rather than how it opens. The preview is decrypted on
demand, plays muted on a 180ms debounce, and stops the moment the pointer
leaves. The amber line under the tile tracks how far through it has got.

![A tile previewing on hover](Docs/Assets/README/04_wall_hover.png)

---

## Project structure

```
App/
  public/          every page and endpoint — this is the web root
    assets/        CSS, the sliced icon set, the self-hosted display font
  src/             the application: crypto, datastore, ingest, queries, jobs
  views/           the header and small shared partials
  bin/             CLI entry points — the job worker and one-off migrations
  tests/           the smoke suite (227 checks)
  Data/            the encrypted datastore — gitignored, never leaves the host
  nginx.conf       the web server's whole configuration
  php.ini          upload limits; php.prod.ini is the production hardening, baked
                   into the image and active unless MYSTASH_DEV=1
  docker-entrypoint.sh   starts nginx and PHP-FPM, and takes the container
                         down if either of them stops
Docs/
  SPECIFICATIONS.md  what the app does and why, clause by clause
  PLAN.md            the phased build log
  DEPLOY.md          how to run it for real
  Assets/            branding, icon sheets and these screenshots
dev/                 the check scripts and the Playwright container's scripts
.github/workflows/   CI: builds the container, proves it serves the app, publishes it
```

Three things worth knowing about the shape:

- **`App/src` has no framework and no autoloader** — each class is one file
  with `require_once` at the top. The whole application is seventeen classes.
- **Every write goes through `Crypto7z`.** Nothing in `public/` touches a data
  file directly, which is why "is it encrypted?" has one answer instead of
  thirty.
- **Long jobs are detached.** Conversion and re-keying run in
  `bin/job_worker.php` with no request behind them; the browser polls
  `job_status.php`. Close the tab and the job carries on.

---

## Features

### Finding things

The site has exactly one search box, in the header, and it searches whatever
the page you are on is a list of: videos everywhere, creators while you are on
the Creators screen. A video matches when every term appears in its title, in
one of the people credited on it, or in one of its category tags — so "cat"
finds the video called *Two Cats*, the one tagged CATS, and anything by a
creator with that in their name.

![Search](Docs/Assets/README/07_search.png)

The filter panel narrows what the search returned rather than replacing it:
categories, individual creators, video length, and the age and gender of the
person credited — a video matches when any one of its creators does. Filters
apply on change; there is no Apply button to hunt for.

![Filters](Docs/Assets/README/05_filters.png)

![A filtered wall](Docs/Assets/README/06_filtered.png)

Sorting is a menu on the wall's own toolbar: upload date either way, title
A→Z or Z→A, length, and view count.

![Sorting](Docs/Assets/README/08_sort.png)

### Uploading

![The upload panel](Docs/Assets/README/09_upload.png)

Drop in a file and the rest is derived: duration, codec, resolution, the
quality badge, a thumbnail and the timelapse preview clip. You can name the
second the thumbnail is taken from, or attach a picture of your own instead —
and change your mind later from the video's edit screen.

Anything that is not already MP4/H.265 is tagged **Not Converted** on the way
in. Nothing is transcoded behind your back: the tag is a statement of fact, and
converting is a button you press.

### Watching

![The watch page](Docs/Assets/README/10_watch.png)

The source is not attached to the player until you press play, so opening a
page does not start decrypting a 4K file. Once playing, it streams through the
app with range requests — seeking works without decrypting the whole file
first.

![Playing](Docs/Assets/README/11_watch_playing.png)

Under the player are the video's categories, each carrying an optional
timestamp: a tag can point at 00:04:12 rather than at the video as a whole, and
clicking it jumps there.

### Editing

![Editing a video](Docs/Assets/README/12_watch_edit.png)

Title, description and the creators credited — a video can credit several, and
emptying the list falls back to `default` rather than leaving it orphaned. The
preview image can be re-taken from any timestamp or replaced with an uploaded
picture; a replacement is written beside the old one and swapped in only after
it verifies, so a failed attempt leaves the tile exactly as it was.

### Converting

![A video that needs converting](Docs/Assets/README/23_not_converted.png)

![Conversion in progress](Docs/Assets/README/24_converting.png)

Conversion runs in the background with a progress bar the page polls. Leave the
page, keep browsing, or close the tab — it carries on, and the original stays
exactly as it is until the converted copy has been written and verified.

### Stats

**Stats** in the user menu lists every video by its size on disk, largest
first. **Inspect** reads a video's frame rate and pixel size. Anything over
30fps or 1920×1080 is then offered a **Reduce**, which re-encodes it with
ffmpeg in the background exactly the way a conversion does.

### Creators

![Creators](Docs/Assets/README/14_creators.png)

Creators are records, not tags: a name, age, gender, a short bio and a profile
picture, stored encrypted like everything else. The grid searches and sorts,
and each card counts the videos and views credited to that person.

![Editing a creator](Docs/Assets/README/15_creator_edit.png)

Renaming a creator updates every video that credits them. Deleting one drops
them from those videos rather than deleting the videos — a video credited to
two people keeps the other, and a video left with nobody falls back to
`default`, which is the one creator that cannot be deleted.

### Categories

![Categories](Docs/Assets/README/16_categories.png)

Categories are global: defined once, given a colour, then assigned to videos
with a timestamp. The screen shows how many videos each is on and recolours
them in place. Removing one takes it off the list and out of the filter panel
but leaves the videos already tagged with it alone — retiring a category is not
the same as editing every video that ever used it.

### Playlists

![Playlists](Docs/Assets/README/17_playlists.png)

A playlist is an ordered set of videos. Nothing is copied — a video can sit on
any number of playlists, and removing it from one leaves the video alone.

![Editing a playlist](Docs/Assets/README/18_playlist_edit.png)

Reordering is a drag of the handle, and the order saves as you drop it.

![Dragging a row](Docs/Assets/README/19_playlist_drag.png)

Adding videos is the same global search in a dialog, with a checkbox against
each title.

![Adding videos to a playlist](Docs/Assets/README/20_playlist_modal.png)

And from the watch page, a **Playlist +** menu lists every playlist with a tick
against the ones this video is already on.

![Playlist menu on the watch page](Docs/Assets/README/13_playlist_dropdown.png)

### The stash itself

![The user menu](Docs/Assets/README/21_user_menu.png)

![Profile](Docs/Assets/README/22_profile.png)

Changing the password re-encrypts **every archive in the stash**, because the
password is the key. It runs as a background job with a progress bar; each
archive keeps its previous bytes as `.enc.old` until the whole run succeeds,
and the index is rewritten last — so an interrupted re-key leaves your current
password still working.

Deleting a stash deletes it. The directory, the archives, the videos: gone,
with nothing retained and the username free to use again.

### Getting in

![Login](Docs/Assets/README/01_login.png)

![Register](Docs/Assets/README/02_register.png)

A wrong password and an unknown username are deliberately indistinguishable —
both are "access denied", and both take the same minimum time, so the login
screen cannot be used to find out which stashes exist. Repeated failures are
throttled, and every lockout expires on its own: with no password reset and no
administrator, a permanent one would lock the owner out forever.

Passwords are at least 24 characters. Anyone holding a copy of the files can
attack them offline as fast as their hardware allows, so length is the only
defence that means anything.

---

## Nothing leaves the machine

No page loads a script, stylesheet, font or image from anywhere but this app.
The display face is served from `assets/fonts/`, the icons are PNGs in the
repository, and there is no analytics, no telemetry and no CDN. The app works
with the machine offline.

`dev/run_font_check.sh` enforces it: it records every request the browser makes
across the site and fails if any of them leaves this origin.

---

## Development

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose exec app php /app/tests/smoke_test.php     # 251 checks
```

The `dev/` scripts each prove one property against real HTTP endpoints and, for
the parts curl cannot reach, a real browser in a Playwright container:

| Script | What it proves |
| --- | --- |
| `run_flow_check.sh` | the app flow in SPECIFICATIONS.md §2, step by step |
| `run_isolation_check.sh` | one stash cannot read another |
| `run_injection_check.sh` | filenames and fields cannot escape into a shell or a path |
| `run_throttle_check.sh` | login throttling, and the timing oracle it also closes |
| `run_password_check.sh` | changing a password re-encrypts the whole stash |
| `run_playlist_check.sh` | playlists, including a genuine drag-and-drop |
| `run_preview_check.sh` | re-previewing from a timestamp and from an upload |
| `run_delete_check.sh` | deleting videos, creators and whole stashes |
| `run_convert_check.sh` | converting a video, as a detached background job |
| `run_stats_check.sh` | the Stats screen: sizes, Inspect, and a 30fps / 1920×1080 reduction |
| `run_layout_check.sh` | the management screens share the watch page's column |
| `run_font_check.sh` | nothing on the site is fetched from another host |

`dev/push_image.sh` builds the container here, runs the same verification CI
does, and pushes it to GHCR — for when the thing that is broken is CI itself.
It needs `docker login ghcr.io` first.

To fill a development stash with the library shown in these screenshots:

```bash
dev/make_sample_clips.sh     # synthesises twelve clips, four resolutions
dev/seed_sample_data.sh      # uploads them through the real endpoints
dev/run_readme_shots.sh      # retakes every screenshot on this page
```

Two of the screenshots are taken by hand: the hover preview and the playing
video. The Chromium that ships with Playwright is built without H.264 and HEVC,
so both are blank there and fine in a real browser — `run_readme_shots.sh`
leaves those two files alone.

The clips are generated rather than filmed, and the twelve videos, three
creators, four categories and two playlists are a fixed list — so the wall
above can be reproduced exactly, and compared against after a change.

## Documentation

| Document | Contents |
| --- | --- |
| [Docs/SPECIFICATIONS.md](Docs/SPECIFICATIONS.md) | what the app does and why — app flow, datastore layout, encryption model, UI spec |
| [Docs/DEPLOY.md](Docs/DEPLOY.md) | running it for real: TLS, backups, upgrades, and what this deployment is not |
| [Docs/PLAN.md](Docs/PLAN.md) | the phased build log, including the reasoning behind each decision |
