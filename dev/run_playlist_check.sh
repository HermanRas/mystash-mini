#!/usr/bin/env bash
# 5.4 — playlists, driven through the real endpoints over HTTP.
#
# The smoke tests cover App/src/Playlists.php directly, which is where the
# ordering and membership rules live. This covers what the smoke tests cannot:
# that the endpoints in front of that model are wired to it, that they refuse
# what they should, and that a playlist survives a round trip through the
# encrypted index rather than only through an array in memory.
#
# Runs against a throwaway stash it creates and deletes.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="PlProbe$(openssl rand -hex 3)"
PASS="probe-playlist-password-aaaaaaa"
JAR="/tmp/${PROBE}.cookies"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

cleanup() {
  rm -rf "App/Data/${PROBE}" "$JAR" "/tmp/${PROBE}".*
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_playlist_check: all passed" || echo "run_playlist_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

post() { curl -s -b "$JAR" -c "$JAR" -o "${1}" -w '%{http_code}' "${@:2}"; }

# Reads one value out of the encrypted index, so every assertion below is
# about what was actually stored rather than what a page happened to render.
peek() { docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r "$1"; }

# --- a stash with four titled videos --------------------------------------
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/User.php";
require_once "/app/src/VideoIngest.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
if (!(new MyStash\User())->create($u, $p)) { fwrite(STDERR, "create failed\n"); exit(1); }
$store = new MyStash\Datastore();
$index = $store->loadIndex($u, $p);
foreach (["Alpha", "Beta", "Gamma", "Delta"] as $n) {
    copy("/app/tests/fixture.mp4", "/dev/shm/pl-{$n}.mp4");
    $e = (new MyStash\VideoIngest())->ingest($u, $p, "/dev/shm/pl-{$n}.mp4", "{$n}.mp4", $index);
    $e["title"] = $n;
    $index["videos"][] = $e;
    @unlink("/dev/shm/pl-{$n}.mp4");
}
$store->saveIndex($u, $p, $index);
echo "seeded ", count($index["videos"]), " videos\n";
' || { echo "FAIL: could not build the throwaway stash"; fails=1; exit 1; }

curl -s -c "$JAR" -o /dev/null -d "username=${PROBE}&password=${PASS}" http://localhost:8090/login.php

echo "--- creating ---"

post /dev/null -d "name=Watch+Later" http://localhost:8090/playlist_save.php >/dev/null
[ "$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo count(MyStash\Playlists::all((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P"))));')" = "1" ]
check "creating a playlist stores it in the encrypted index" $?

# A blank name must not produce a nameless playlist.
post /dev/null -d "name=" http://localhost:8090/playlist_save.php >/dev/null
post /dev/null -d "name=+++" http://localhost:8090/playlist_save.php >/dev/null
[ "$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo count(MyStash\Playlists::all((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P"))));')" = "1" ]
check "a blank or whitespace name creates nothing" $?

# --- adding videos, the way the modal posts -------------------------------
echo "--- contents and order ---"

post /dev/null -d "id=1&videos[]=3&videos[]=1&videos[]=2" http://localhost:8090/playlist_videos.php >/dev/null
ORDER=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo implode(",", MyStash\Playlists::find((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")), "1")["videos"]);')
echo "  stored order: ${ORDER}"
[ "$ORDER" = "3,1,2" ]
check "the posted order is the order stored" $?

# A drag posts the whole list in its new order.
post /dev/null -d "id=1&videos[]=1&videos[]=2&videos[]=3" http://localhost:8090/playlist_videos.php >/dev/null
ORDER=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo implode(",", MyStash\Playlists::find((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")), "1")["videos"]);')
echo "  after a reorder: ${ORDER}"
[ "$ORDER" = "1,2,3" ]
check "a reorder rewrites the stored order" $?

# An id this stash does not hold must not end up on a list.
post /dev/null -d "id=1&videos[]=1&videos[]=999&videos[]=2" http://localhost:8090/playlist_videos.php >/dev/null
ORDER=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo implode(",", MyStash\Playlists::find((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")), "1")["videos"]);')
[ "$ORDER" = "1,2" ]
check "an id the stash does not hold is refused at the endpoint (${ORDER})" $?

# A playlist that does not exist must not be created by posting to it.
post /dev/null -d "id=77&videos[]=1" http://localhost:8090/playlist_videos.php >/dev/null
[ "$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo count(MyStash\Playlists::all((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P"))));')" = "1" ]
check "posting to a playlist id that does not exist creates nothing" $?

echo "--- the watch page's checkbox ---"

BODY=$(curl -s -b "$JAR" -H 'X-Requested-With: fetch' -d "playlist=1&video=4" http://localhost:8090/playlist_toggle.php)
echo "  toggle on:  ${BODY}"
printf '%s' "$BODY" | grep -q '"in_playlist":true'
check "ticking the box adds the video and says so" $?

ORDER=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo implode(",", MyStash\Playlists::find((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")), "1")["videos"]);')
[ "$ORDER" = "1,2,4" ]
check "...appended, so the order the user arranged is left alone (${ORDER})" $?

BODY=$(curl -s -b "$JAR" -H 'X-Requested-With: fetch' -d "playlist=1&video=4" http://localhost:8090/playlist_toggle.php)
echo "  toggle off: ${BODY}"
printf '%s' "$BODY" | grep -q '"in_playlist":false'
check "ticking it again removes the video" $?

CODE=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d "playlist=1&video=999" http://localhost:8090/playlist_toggle.php)
[ "$CODE" = "404" ]
check "toggling a video the stash does not hold is refused (${CODE})" $?

# --- the pages render what is stored --------------------------------------
echo "--- the screens ---"

curl -s -b "$JAR" -o "/tmp/${PROBE}.grid" http://localhost:8090/playlist.php
grep -q 'Watch Later' "/tmp/${PROBE}.grid"
check "the playlists screen shows the playlist" $?

# §"the first 3 videos in a card": the card must stop at CARD_PREVIEW_COUNT
# however many the list holds.
post /dev/null -d "id=1&videos[]=1&videos[]=2&videos[]=3&videos[]=4" http://localhost:8090/playlist_videos.php >/dev/null
curl -s -b "$JAR" -o "/tmp/${PROBE}.grid" http://localhost:8090/playlist.php
THUMBS=$(grep -c 'playlist-thumb"' "/tmp/${PROBE}.grid")
echo "  thumbnails on the card for a 4-video playlist: ${THUMBS}"
[ "$THUMBS" = "3" ]
check "a card shows the first three videos and stops" $?

grep -q '4 videos' "/tmp/${PROBE}.grid"
check "...while the card still reports the true count" $?

curl -s -b "$JAR" -o "/tmp/${PROBE}.edit" "http://localhost:8090/playlist.php?id=1"
ROWS=$(grep -c 'class="playlist-row"' "/tmp/${PROBE}.edit")
[ "$ROWS" = "4" ]
check "the edit screen lists every video on the playlist (${ROWS})" $?

grep -q 'draggable="true"' "/tmp/${PROBE}.edit"
check "...with draggable rows" $?

# The modal offers the whole stash, with what is already on the list disabled
# rather than absent — so it reads as a picture of the playlist, not a shop.
DISABLED=$(grep -c 'checked disabled' "/tmp/${PROBE}.edit")
[ "$DISABLED" = "4" ]
check "the add-videos modal shows videos already on the list as ticked (${DISABLED})" $?

# A bookmark to a deleted playlist must not open an edit screen for nothing.
LOC=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "http://localhost:8090/playlist.php?id=404")
case "$LOC" in *playlist.php) true;; *) false;; esac
check "a playlist id that does not exist redirects to the grid" $?

echo "--- deleting ---"

# Deleting a video has to leave the playlists consistent.
curl -s -b "$JAR" -o /dev/null -d "id=2" http://localhost:8090/video_delete.php
ORDER=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo implode(",", MyStash\Playlists::find((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")), "1")["videos"]);')
echo "  playlist after deleting video 2: ${ORDER}"
[ "$ORDER" = "1,3,4" ]
check "deleting a video drops it from the playlists that held it" $?

# ...and deleting a playlist must not take the videos with it.
curl -s -b "$JAR" -o /dev/null -d "id=1" http://localhost:8090/playlist_delete.php
COUNT=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
$i = (new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P"));
echo count(MyStash\Playlists::all($i)), ":", count($i["videos"]);')
echo "  playlists:videos after deleting the playlist: ${COUNT}"
[ "$COUNT" = "0:3" ]
check "deleting a playlist removes the list and leaves every video alone" $?

# Ids appear in URLs, so the id of a deleted playlist must not be reissued.
post /dev/null -d "name=Second" http://localhost:8090/playlist_save.php >/dev/null
NEWID=$(peek 'require_once "/app/src/Datastore.php"; require_once "/app/src/Playlists.php";
echo MyStash\Playlists::all((new MyStash\Datastore())->loadIndex(getenv("U"), getenv("P")))[0]["id"];')
echo "  id issued after deleting playlist 1: ${NEWID}"
[ "$NEWID" != "1" ]
check "the id of a deleted playlist is not handed out again" $?

# --- the browser half, last ------------------------------------------------
# Dragging is the one part of this feature that does not exist at the HTTP
# level at all: curl can post an order, but only a browser can prove a row can
# be picked up and dropped somewhere else.
#
# It runs after everything else because it is not read-only — it creates a
# playlist and ticks boxes. Run in the middle, it quietly changed the contents
# that the delete assertions below it were checking, and they failed for a
# reason that had nothing to do with deleting.
echo "--- in a browser ---"
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T \
  -e NODE_PATH=/opt/pwlib/node_modules -e PROBE_USER="$PROBE" -e PROBE_PASSWORD="$PASS" \
  playwright node /work/check_playlist.js 2>&1 | tee "/tmp/${PROBE}.browser"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.browser")))
