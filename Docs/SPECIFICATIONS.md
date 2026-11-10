# MyStash — Specifications

## 1. Tech Stack

- **Backend:** PHP
- **Runtime:** a single Docker container — nginx on 8080 with PHP-FPM behind it on the container's own loopback (see `App/docker-entrypoint.sh` and Docs/DEPLOY.md §3)
- **Encryption (v2):** libsodium, built into PHP — Argon2id turns the password into a key, XChaCha20-Poly1305 secretstream encrypts each file as an authenticated `.epc` archive (§3.1). v1 used 7-Zip `.enc` archives; see Docs/v2_migration.md
- **Video processing:** PHP-driven conversion to MP4 (H.265/MPEG-H HEVC), frame extraction for previews
- **Frontend:** HTML/CSS/JS, dark-theme UI (see §4)

### Third-party dependencies

| Dependency | Purpose | Notes |
| --- | --- | --- |
| PHP `sodium` extension (libsodium) | Argon2id key derivation and XChaCha20-Poly1305 authenticated encryption for every archive in the datastore | Bundled with PHP; no external process, so neither the password nor the key is ever on a command line. Files are stored, not compressed — video and images are already compressed |
| `p7zip` (7-Zip CLI, `7z`) | **v1 only:** read the old `.enc` archives once, to upgrade a v1 stash (`App/src/MigrateV2.php`) | Nothing writes `.enc` any more. Removable once no v1 stash remains anywhere |
| `ffmpeg` | Transcode uploaded video to MP4 (H.265/HEVC); extract frame snapshots every 15s for preview generation; build short muted preview clips | Core dependency for upload processing and format conversion |
| PHP `exec`/`proc_open` | Shell out to `ffmpeg` (and to `7z` for a v1 upgrade) | Always with an argv array, never a shell string |
| (Optional) `ffprobe` | Read video metadata (duration, codec, resolution) on upload | Ships with ffmpeg |

### Nothing is fetched from anywhere else

No page loads a script, stylesheet, font or image from a host other than this
app. Everything the browser needs ships in `App/public/assets/`, including the
display face (`assets/fonts/carter-one-latin-400.woff2`, SIL OFL 1.1, licence
alongside it). This is not performance: the point of a stash encrypted at rest
on a machine you control is defeated if opening it announces that to a CDN, and
the site has to work with no internet connection at all. `dev/run_font_check.sh`
records every request the browser makes across the site and fails if any of them
leaves this origin.

## 2. App Flow

### 2.1 Registration & Login

**Creating a stash:** a user is nothing more than a datastore directory plus an index archive encrypted with a key derived from their password — there is no account record and no stored password. Registration (`register.php`) takes a username, a password and a confirmation, derives a fresh key (new random salt), then creates `App/Data/{user}/videos/{user}.json.epc` holding an empty video list, a `default` creator and a `Not Converted` category (both of which ingestion relies on). Usernames are **letters and digits only**, at most 32 characters, and must be unused. That rule is also what makes a username safe to use directly as a path segment — no separators or dots, so it cannot escape the data directory. The same validation guards login.

**Password rules:** at least **24 characters**. Because the encryption key is derived from the password, the password is never stored, can never be reset, and cannot be rate-limited where it matters — anyone holding a copy of the `.epc` files can attack them offline. Argon2id makes every guess cost ~0.5 s and 256 MB, but a short password is still a small search, so length remains the first defence. The minimum is enforced at registration and on password change; it is deliberately *not* enforced at login, so stashes created before the rule still open.

Because the key comes only from the password and neither is ever stored, a forgotten password means the stash cannot be recovered — by the user or anyone else.

1. User submits a **username** and **password**. Neither is stored anywhere by the app.
2. App checks that path `./{user}` exists (confirms a valid username).
3. App reads the salt and Argon2id cost from the header of `{user}.json.epc` and derives the key from the submitted password (`StashKey::unlock`). With no stash to take a salt from, it derives against a throwaway salt anyway, so an unknown username costs the same time as a wrong password.
4. App attempts to decrypt `{user}.json.epc` with that key. Success = login. Failure (bad path or bad password) = rejected, no further detail given.
5. Once logged in, the app reads the decrypted video index from `{user}.json.epc` and renders the video wall.
6. **A v1 stash** (`{user}.json.enc` and no `.epc`): the password is checked with 7z instead, and the right one starts the one-time upgrade to v2 as a background job (`upgrade.php` shows its progress) rather than a session. The user signs in again when it finishes. See Docs/v2_migration.md.

**Session storage:** the derived key — never the password — and a cached copy of the decrypted index live in a PHP session — but the session store itself is redirected to tmpfs (`/dev/shm`, RAM-backed) instead of PHP's default on-disk session path, so the key never touches persistent disk. It's gone on logout or container restart. See `App/src/Session.php`.

**Stale sessions:** if the index no longer decrypts with the key the session holds, the stash was re-keyed elsewhere and the session is ended immediately (back to the login page, with an explanation). It must not simply fall back to its cached index: every write re-encrypts with the session's key, so carrying on would rewrite the index and creator records under the *old* key and split the stash across two passwords.

**Index freshness:** every page and endpoint re-reads the index from disk (`Session::refreshIndex()`) rather than trusting the copy taken at login. Because each change rewrites the whole index, a session working from a login-time snapshot would silently revert changes made in another session — that is exactly how a deleted video reappeared on the wall once.

### 2.2 Video Wall Load

1. Preview images and video metadata load first, from the decrypted datastore index.
2. The video file itself is never loaded until the user clicks to play it.
3. On hover, the preview clip (or sprite sheet / animated image — see §4.4) loads and plays.

### 2.3 Upload

1. User uploads a video file. The creator list defaults to `[default]`; `format` is whatever was uploaded.
2. If the uploaded format is not MP4 (H.265/HEVC), the video is tagged `not converted`.
3. On ingestion, the app generates:
   - **A preview image** — by default the frame at 15s. The user may instead pick a different timestamp, or upload their own image. This can be changed after upload, from the video's edit screen.
   - **A preview clip** — a *timelapse*, not an excerpt: one frame sampled every 15s across the whole video, joined into a short clip with no sound. So the preview skims the entire video rather than showing one continuous moment.
   - Encrypted metadata
4. All generated artifacts are encrypted and written to the datastore (§3).

**Derived tags — quality and "not converted".** Neither is editable. They describe the file, so letting a user type them in would only let them lie about it; both are recalculated from the stored technical facts (pixel height, container, codec) on ingest, on **every video save**, and on conversion. Conversion measures the converted file while it is still decrypted in tmpfs, which is the one moment the real height is cheap to read.

Quality follows the standard tiers, keyed on vertical pixel count (`p` = progressive). A video takes the highest tier its height reaches. Standard definition gets no per-height badge — 360p and 480p are both just `SD` — and an unknown height gets no badge at all:

| Height | Badge | Class |
| --- | --- | --- |
| 4320 | `8K` | UHD — 8K UHD, 7680 × 4320 |
| 2160 | `4K` | UHD — 4K UHD, 3840 × 2160 |
| 1440 | `2K` | UHD — 2K / QHD, 2560 × 1440 |
| 1080 | `Full HD` | HD — the standard for streaming and Blu-ray, 1920 × 1080 |
| 720 | `HD` | HD — "Ready HD", the minimum for high definition, 1280 × 720 |
| below 720 | `SD` | SD — 360p and 480p, legacy formats for tube TVs and low-bandwidth streaming |

**Views** are counted when playback actually starts (the player's `playing` event), not when the watch page loads — opening a video without watching it does not count, and a file the browser cannot decode is not counted as watched. The count is written to both the per-video metadata and the index entry, so the wall can show it without decrypting anything. There are no likes, ratings or comments anywhere in the app.

### 2.4 Conversion

- User-triggered: convert any video to MP4 (H.265/HEVC).
- On success, the `not converted` category tag is removed.

### 2.5 Edit / Delete

- **Video edit:** available from the video watch page; edits title, description, creators and category assignments. Quality and "not converted" are not editable — see §2.3.
- **Creator edit:** available from the user dropdown in the nav bar ("manage creators"); edits name, age, gender, bio and profile picture. Renaming a creator repoints every video that referenced the old name; the creator's ID and files stay put.
- **Delete:** removes a video (and its datastore files), or a Creator (and their record + profile picture). Deleting a creator **drops them from every video they were credited on** rather than reassigning the whole video, so a video credited to two people keeps the other; a video left with nobody falls back to `default` (see §3). `default` itself cannot be deleted.

### 2.6 Password Change

1. User provides current password + new password (entered twice for confirmation); the new password must meet the §2.1 minimum length.
2. App derives a new key from the new password, with a **new salt** at the current Argon2id cost, and re-encrypts the entire datastore under it — **every** archive, not just the videos: the four files per video, each creator's record and profile picture, and the index.
3. Order of operations: reprocess everything else first, then update `{user}.json.epc` last. While the index still opens with the old password, an interrupted run is retryable.
4. While reprocessing, the previous encrypted file is kept alongside as `{name}.epc.old` until the run completes successfully, then removed. A failed re-encrypt restores that one archive from its `.old` and aborts before touching anything further.

The re-encryption pass is `App/src/Rekey.php`. It is reached from the account screen (`user.php` → `password_change.php`) and, because re-encrypting every archive is minutes of work on a large stash, it runs as a detached background job rather than inside the request — the browser polls `job_status.php` for progress. `App/bin/rekey_user.php {user} {old} {new}` remains as the command-line entry to the same code, which is what makes an interrupted run retryable without a session.

The current password is checked by deriving it again with the stash's salt and comparing keys (`StashKey::matches`) — the session holds only the key, so there is no password to compare against. The job is handed the *current key* and the new password, never the current password.

### 2.7 Categories

Categories work like creators: they are **global definitions** (name + colour) managed once per user, and videos *reference* them.

- **Definitions** live in the index (`{user}.json`) as `{"<name>": "<hex colour>"}` and are managed on the Categories screen.
- **Assignments** live in the video's own metadata (`Video{ID}/{ID}.json`) as `{"name": "<category>", "timestamp_seconds": N}`.
- Each assignment carries a timestamp (`hh:mm:ss`, default `00:00:00`) so a category can point at a specific moment; clicking it on the watch page seeks the player there.
- The **same category may be assigned multiple times** at different timestamps. Only the exact same category at the exact same timestamp is rejected as a duplicate.
- On the video edit screen you pick a category from the global list — categories can't be invented ad hoc per video.
- Removing a global category only **retires the definition**: it can no longer be assigned to videos and it disappears from the wall's filter panel, but videos already tagged with it **keep their tags** (they render in a neutral grey, since there's no colour to look up).
- The wall's filter list is populated from the global definitions.
- Categories are managed on their own screen (`category.php`), reached from the user dropdown in the nav bar — the same place as "Manage Creators" — and from the `Categories` pill in the top nav.
- Clicking a category on that screen opens the wall filtered to it (`wall.php?category[]=<name>`), which is the same URL the filter panel produces.

The index also keeps a **de-duplicated copy of each video's category names** on its entry. That copy is derived, not authoritative: it exists so the wall grid and its filters can render without decrypting every video's metadata archive on each page load.

### 2.8 Search / Filter / Sort

- **Search:** videos by title, creator, category tags.
- **Filter:** videos by type, category, creator and length range; creators by age, gender, other details.
- **Sort:** videos by title, length, views or upload date.

**Sort options** (the wall's sort menu, right of the result count):

| Sort | Order |
| --- | --- |
| Uploaded | new → old *(default)*, old → new |
| Title | A → Z, Z → A |
| Length | short → long, long → short |
| Views | min → max, max → min |

Filtering and sorting are applied **server-side**, against the already-decrypted index, and carried in the query string (`?type[]=…&category[]=…&creator[]=…&len_min=…&len_max=…&sort=…`). That means a filtered wall renders exactly the tiles it should rather than hiding rows in the browser, the two compose with each other, and any filter can be linked to — which is how clicking a category on the Categories screen opens a filtered wall. `All Videos` in the top nav links to the bare wall URL, so it doubles as the reset. See `App/src/VideoQuery.php`.

The top of the length slider is an **open end**, not a ceiling: at maximum it reads "any" and stops filtering on length.

**Type** sorts every video into one of three bands by running time: **Shorts** (under 4:00), **Videos** (4:00 up to 30:00) and **Movies** (30:00 and over) — so exactly 4:00 is a video and exactly 30:00 a movie. The wall opens with **Videos and Movies ticked and Shorts hidden**; that applies to any wall URL without a `type`, including `All Videos` and the Categories screen's links, and the count line says "(filtered)" because of it. The filter form always sends an empty `type[]`, so a form with every box unticked is told apart from a bare wall — and, like categories and creators, nothing ticked means no type filter. The Type group stays collapsed while it holds the default.

### 2.9 Playlists

A playlist is a **named, ordered set of videos**. Nothing is copied: a video can
sit on any number of playlists, and taking it off one leaves the video and every
other playlist alone.

- **Where they live.** In the index, beside categories and creators — a playlist
  owns no files of its own, so §3 gains no new paths.
- **Keyed by id, not name.** Categories and creators are keyed by display name
  because that name *is* the reference a video holds, which is why renaming a
  creator has to repoint every video. Nothing references a playlist by name, so
  a rename is one field on one record. Two playlists may share a name; a name is
  a label, not a key.
- **Membership is stored once, on the playlist** — a video carries no playlist
  field. The order has to live on the playlist anyway, so a copy on the video
  could only ever be the one that goes stale. "Which playlists is this video
  in?" is a scan of a few short arrays.
- **The order is the playlist.** It is what dragging a row rewrites, and it is
  stored exactly as posted. Ids the stash does not hold are dropped and repeats
  collapse to their first position.
- **Ids are never reused.** They appear in URLs, so a bookmark to a deleted
  playlist must land nowhere rather than quietly open whatever was created after
  it. The index keeps a high-water mark (`playlist_seq`) that survives a delete.
- **Deleting a video** drops it from every playlist that held it, in the same
  write. **Deleting a playlist** removes the list only — never the videos.

**Reached from the user dropdown in the nav bar**, beside "Manage Creators" and
"Manage Categories". Deliberately *not* a fourth nav pill: §4.2 fixes that bar
at three destinations, and a fourth would make it the very thing category names
were kept out of it for.

**The playlists screen** (`playlist.php`) is a grid of cards, each showing the
playlist's name, its video count, and the first **three** videos as a strip of
thumbnails. A card is for recognising a playlist, not reading it — the whole
list is one click away, and a card that grew with its playlist would push the
next one off the screen.

**The playlist screen** (`playlist.php?id=N`) lists every video in playlist
order. Rows are reordered by **dragging them by their handle**, and the new
order saves on drop — the browser posts the whole order it is showing, never a
"move X above Y", so what is stored is what the user can see even if the list
changed in another tab. Each row also has a Remove, and the screen carries
rename and delete.

**Adding videos** is a button in the header — the same place the Creators screen
puts "Add Creator" — opening a modal of every video in the stash with a checkbox
each. Videos already on the playlist show ticked and disabled. The modal's
search matches the same three fields the site's search does (title, creators,
category names), filtered in the browser because the list is already decrypted
for that page.

**From a video's watch page**, a `Playlist +` button opens a dropdown of every
playlist with a checkbox against each — built like the sort and user menus, so
the three dropdowns on the site behave alike. Each tick posts on its own; a
newly added video goes on the **end**, because appending is the only insertion
point that does not disturb an order the user arranged.

There is no "play all" or shuffle: a playlist is a way of keeping videos
together, and playback is still one video at a time from the watch page.

## 3. Datastore Layout

All data is stored locally per-user; the app stores nothing server-side outside each user's own datastore directory. Multiple users each get their own isolated datastore and video wall.

The datastore root in this repo is `App/Data/` (gitignored — see [../README.md](../README.md) Repository Layout), so paths below are relative to that:

```
App/Data/{user}/videos/{user}.json.epc                         # encrypted video index — its header's salt is the stash's
App/Data/{user}/videos/Video{ID}/{ID}.mp4.epc                  # encrypted video file
App/Data/{user}/videos/Video{ID}/{ID}.mp4.preview.epc          # encrypted short preview clip
App/Data/{user}/videos/Video{ID}/{ID}.jpg.preview.epc          # encrypted preview thumbnail
App/Data/{user}/videos/Video{ID}/{ID}_trim.mp4.epc             # encrypted trimmed copy, while one is pending
App/Data/{user}/videos/Video{ID}/{ID}.json.epc                 # encrypted per-video metadata
App/Data/{user}/creators/Creator{ID}/{ID}.json.epc             # encrypted creator record
App/Data/{user}/creators/Creator{ID}/{ID}.profile.png.epc      # encrypted creator profile picture
App/Data/{user}/**/*.epc.old                                   # transient safety copy during re-encryption only
```

A v1 stash has the same layout with `.enc` (7-Zip) in place of `.epc`; it is converted in place, once, by the upgrade (§2.1 step 6, Docs/v2_migration.md).

### 3.1 The `.epc` archive

Every archive is one file encrypted on its own (`App/src/StashCrypto.php`). All integers are big-endian:

| Offset | Size | Field |
| --- | --- | --- |
| 0 | 5 | magic `MSEPC` |
| 5 | 1 | format version, `2` |
| 6 | 1 | key derivation: `2` = Argon2id (libsodium `ARGON2ID13`) |
| 7 | 4 | Argon2id opslimit |
| 11 | 8 | Argon2id memlimit, in bytes |
| 19 | 16 | Argon2id salt — the same for every archive in a stash, new on every re-key |
| 35 | 24 | libsodium secretstream header |
| 59 | … | frames: a 4-byte length, then XChaCha20-Poly1305 secretstream ciphertext |

- The **first frame** is the file's name, encrypted with bytes 0–34 as additional data — so the cost and salt cannot be edited without the archive refusing to open, and the name is hidden (as v1's `-mhe=on` hid it).
- **Every later frame** is up to 1 MiB of the file. The last is tagged `FINAL`; an archive that ends without it was truncated, and anything after it is refused.
- The **key** is `Argon2id(password, salt, opslimit, memlimit)`, 32 bytes — libsodium's MODERATE cost (3 passes, 256 MB, ~0.5 s). It is derived once per login and carried in the session as a token (`StashKey`), never stored on disk.
- The header carries everything needed to re-derive the key from the password, so any single archive can be decrypted on its own — which is what `tools/decode.php` does, with no app code at all.

**Playlists add no files.** They live in the index (§2.9) as a list of
`{id, name, videos: [<video id>, ...]}`, plus a `playlist_seq` high-water mark
so a deleted playlist's id is never reissued. A playlist owns nothing on disk,
which is also why deleting one can never cost a video.

**A video may credit more than one creator.** Both the index entry and the per-video metadata carry `"creators": ["<name>", ...]`, and the edit screen offers the global creator list as checkboxes. Every video has at least one: emptying the list falls back to `default`, the creator ingestion assigns and the only one that cannot be deleted. Deleting a creator drops them from every video they were on rather than reassigning the whole video, so a video credited to two people keeps the other. Entries written before this carry a single `creator` string; reads go through `VideoCreators::of()`, and `bin/migrate_records.php` rewrites them.

**Creators** are stored the same way videos are. The creator record (`{ID}.json`: id, name, age, gender, bio, created/updated timestamps) is authoritative; the index keeps a denormalized summary of each so the wall, the creator grid and the filter panel can render without decrypting every creator archive on each page load — the same arrangement as the category names on video entries. Creators are still keyed by **display name** in the index, because that is what a video's `creator` field references; the ID only addresses the files, and survives a rename. Uploaded profile pictures are normalised to PNG before encryption, so the stored filename always describes the actual bytes.

**Seeding a development stash.** `App/Data` holds nothing but real stashes —
there is no checked-in fixture, and the smoke suite synthesises the video it
needs into tmpfs rather than depending on one. To fill a development stash with
something to look at, `dev/make_sample_clips.sh` generates twelve clips across
the four resolutions (four of them deliberately in a codec the app treats as
unconverted) and `dev/seed_sample_data.sh` uploads them as `TestUser` through
the real endpoints — register, upload, video_save, creator_save, playlist_save
— so the result is a stash the application itself produced. `App/bin/seed_testuser.php`
remains for metadata-only demo rows; entries it writes carry no media, so
playback and conversion for them report "no encrypted video file".

## 4. UI/UX & Layout Specification

### 4.0 Branding & Icons

All artwork is generated by the project owner and delivered into `Docs/Assets/` (prompts kept in `Docs/Assets/gemini_asset prompt.md`). Nothing in the UI should invent its own iconography while the sheet covers it.

- `site_icons.png` — a sheet of flat `#ffa31a` icons on black, sliced at build time into transparent PNGs under `App/public/assets/img/icons/` (upload, download, creator, A-Z / Z-A / 0-9 / 9-0 sorts, tag, user, login, logout, videos, register, chevrons).
- `icon.png` — the logo lockup, cropped into `mark.png` (the 32px header mark) and `logo.png` (the login/register cards).

Because the icons are a single flat colour, any icon placed on an amber ground (an active nav pill, a primary button, the user avatar) is knocked back to dark ink with `filter: brightness(0)` rather than shipping a second set of files.

Every inline UI icon is one size, set once as `--icon-size` — nav pills, menu items and buttons all use it, so no screen ends up with a mix. Selects are themed globally (`select { … }`) rather than per screen; left alone they render as white native controls against the dark theme.

### 4.1 Visual Theme & Color Palette

High-contrast dark theme, optimized for media consumption.

- **Primary Background:** Deep Matte Dark Grey / Black (`#111111`–`#1b1b1b`)
- **Secondary Surface / Cards:** Dark Charcoal (`#222222`–`#2a2a2a`)
- **Primary Text & Icons:** White (`#ffffff`)
- **Secondary Text & Metadata:** Muted Mid-Grey (`#888888`)
- **Accent / Interactive:** Amber/Orange (`#ffa31a` / `#ff9900`) — CTAs, duration badges, active tabs
- **Borders & Dividers:** Dark Grey (`#333333`)

### 4.2 Page Structure & Components

```
+-----------------------------------------------------------------------+
|  LOGO  |  [ Search Bar... ]       |  [+ Upload] [Filters] [User Profile]|
+-----------------------------------------------------------------------+
| (All Videos)  [Creators]  [Categories]                                 |
+-----------------------------------------------------------------------+
| FILTERS  |  7 of 7 videos                              Sort [ ______ ] |
+-----------------------------------------------------------------------+
|                                                                       |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  |
|  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | Title Text   |  | Title Text   |  | Title Text   |  | Title Text   |  |
|  | Creator Name |  | Creator Name |  | Creator Name |  | Creator Name |  |
|  | Length•Views•Cats| Length•Views•Cats| Length•Views•Cats| Length•Views•Cats|
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Header & Navigation:** Sticky, dark. Brand mark left, search bar (with auto-complete) center, user actions right. A pill bar sits directly beneath with exactly three destinations, identical on every page: **All Videos**, **Creators**, **Categories** (`App/views/header.php`).

Playlists are reached from the user dropdown, not from this bar, for the same
reason the bar carries no category names: three destinations, fixed, identical
on every page.

The nav deliberately carries **no category names** — those live in the wall's left filter panel, and having both was two ways to do the same thing. It carries no "Most Recent" either: that is a sort order, and it belongs in the sort menu. There are likewise no ranking pills such as "Trending" or "Top Rated" — this is a personal wall with no ratings (see §2.8). `All Videos` points at the bare wall URL, so it also resets whatever filters are applied.

**Wall toolbar:** above the grid — the result count on the left (`2 of 7 videos (filtered)`), the sort menu on the right.

**Filter panel:** left side, **open by default**, collapsed by the Filters button. Titled **Filters**, with each group a native `<details>` accordion in the order **Categories → Videos → Creators**. Categories is open on load; a group holding an active filter re-opens too, so a filter is never hidden behind a collapsed heading. Category checkboxes, a length range, creator checkboxes, and creator age/gender. There is **no Apply button** — the form submits on change (range inputs fire `change` on release, so dragging doesn't reload mid-drag) — and **no Reset button**, because `All Videos` in the top nav is the bare wall URL and already does exactly that.

**Media Grid:** CSS Grid, `repeat(auto-fill, minmax(280px, 1fr))`. Gap 12–16px. Container padding 16–24px.

**Creator Directory:** 6–8 column grid, circular avatars (`border-radius: 50%`), hover border transition to `#ffa31a`. Metadata: display name, video count, view count, age. There is deliberately no "verified" badge: this is a private stash, with nobody to verify a creator against.

### 4.2.1 Watch Page Order

Top to bottom, single column (no side rail): **title**, player, meta line (views • length • quality • conversion state), category chips, **creator card**, actions (Edit / Convert), description. The title leads because it names what you are looking at before you look at it; the creator follows the categories because it is reference material, not the point of the page.

### 4.3 Video Tile Specification

| Component | Position | Styling |
| --- | --- | --- |
| Thumbnail Box | Top | 16:9 (`aspect-ratio: 16/9`), 4px rounded corners, overflow hidden |
| Duration Badge | Bottom-right overlay | `rgba(0,0,0,0.8)` bg, white text, 11px, e.g. `14:20` |
| Quality Badge | Bottom-left overlay | Small amber rounded tag, e.g. `4K`, `Full HD`, `480p` — derived from pixel height, see §2.3 |
| Title | Below thumbnail | 14px, line-height 1.3, bold white, 2-line clamp + ellipsis |
| Creator/Channel | Under title | 12px, `#888888`. All credited creators, comma-separated |
| Stats Line | Bottom row | 12px, `#888888`. Shows length, view count and categories, e.g. `14:20 • 128 views • Personal, Highlights`. No rating or score — there are no likes/ratings anywhere in the app |

### 4.4 Hover-to-Preview Technical Specification

**Trigger logic:**
1. `mouseenter` on thumbnail container.
2. Debounce 150–200ms before triggering, to avoid firing during rapid scroll.
3. `mouseleave` cancels pending timers, stops playback, restores static thumbnail instantly.

**In use: option 2 (short video clip injection)** — the encrypted `{ID}.mp4.preview.epc` timelapse from §2.3, served through `media.php` and played in a `<video muted loop playsinline>` on hover. The other two options are recorded here as alternatives only.

**Preview implementation options:**

1. **Sprite sheet frame scrubbing (recommended for performance):** one composite `spritesheet.jpg` with 10–15 frames generated at ingestion; on hover, `setInterval` every 200–300ms steps `background-position`.
2. **Short video clip injection:** low-bitrate 3–5s `preview.mp4`; on hover, append/unhide a `<video muted loop playsinline>` and `.play()`.
3. **Animated image swap:** matching animated `.webp`/`.gif`; on hover, swap the `<img>` `src`.

**Progress indicator:** 2px accent-colored (`#ffa31a`) bar animates across the thumbnail's bottom edge during preview playback.
