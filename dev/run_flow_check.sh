#!/usr/bin/env bash
# 7.4 — the app flow of Docs/SPECIFICATIONS.md §2, walked end to end.
#
# The other dev/ scripts each prove one property in depth: that nothing reaches
# a shell (run_injection_check), that one user cannot touch another's files
# (run_isolation_check), that a conversion never loses the video
# (run_convert_check). This one is deliberately shallow and wide. It follows
# the numbered steps of §2 in the order a user meets them — register, log in,
# upload, look at the wall, watch, tag, retire a tag — and asserts the claim
# the specification makes at each step.
#
# It exists because a specification is only worth what can be checked against
# it. Every check below names the section it comes from, so a spec change that
# nothing implements shows up here as a failure rather than as prose that
# quietly stopped being true.
#
# Everything runs against a throwaway stash this script creates and deletes.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="FlowProbe$(openssl rand -hex 3)"
PASS="probe-flow-password-aaaaaaaaaaa"     # 32 chars, comfortably over the §2.1 floor
JAR="/tmp/${PROBE}.cookies"
SRC="/tmp/${PROBE}.mov"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

cleanup() {
  rm -rf "App/Data/${PROBE}" "$JAR" "$SRC" "/tmp/${PROBE}".*
  # The failed registrations below land in the address bucket, which behind
  # this proxy is shared with every real login (7.3). Leaving them counted
  # would spend part of Herman's allowance on a test run.
  docker compose exec -T -u www-data app sh -c 'rm -rf /dev/shm/mystash-login' >/dev/null 2>&1
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_flow_check: all passed" || echo "run_flow_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

post() { curl -s -b "$JAR" -c "$JAR" -o "$1" -w '%{http_code} %{redirect_url}' "${@:2}"; }

# A .mov, so §2.3 step 2 ("not MP4/H.265 ⇒ tagged not converted") has something
# to bite on. Same bytes as the fixture; only the container name differs, which
# is exactly what the derived tag is meant to notice.
cp "$FIXTURE" "$SRC"

echo "--- §2.1 Registration ---"

# "Usernames are letters and digits only" — the rule that also keeps a username
# safe as a path segment.
OUT=$(post "/tmp/${PROBE}.r1" -d "username=bad-name&password=${PASS}&confirm_password=${PASS}" \
  http://localhost:8090/register.php)
grep -q 'letters and numbers' "/tmp/${PROBE}.r1"
check "§2.1 a username with a symbol in it is refused" $?

# "at least 24 characters ... enforced at registration"
post "/tmp/${PROBE}.r2" -d "username=${PROBE}&password=short23characterspass&confirm_password=short23characterspass" \
  http://localhost:8090/register.php >/dev/null
grep -q 'at least 24 characters' "/tmp/${PROBE}.r2"
check "§2.1 a password under 24 characters is refused" $?

test ! -d "App/Data/${PROBE}" && test ! -d "App/Data/bad-name"
check "§2.1 neither refusal created a stash directory" $?

# The real one. "Registration ... creates App/Data/{user}/videos/{user}.json.epc"
OUT=$(post /dev/null -d "username=${PROBE}&password=${PASS}&confirm_password=${PASS}" \
  http://localhost:8090/register.php)
echo "  register returned: ${OUT}"
[ -f "App/Data/${PROBE}/videos/${PROBE}.json.epc" ]
check "§2.1 registering creates the encrypted index archive" $?

case "$OUT" in *wall.php) true;; *) false;; esac
check "§2.1 registration logs straight in and lands on the wall" $?

# "holding an empty video list, a default creator and a Not Converted category
# (both of which ingestion relies on)"
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
$i = (new MyStash\Datastore())->loadIndex(getenv("U"), MyStash\StashKey::unlock(getenv("U"), getenv("P")));
printf("%s: §2.1 the new stash opens with the registered password\n", $i !== null ? "PASS" : "FAIL");
if ($i === null) { exit; }
printf("%s: §2.1 it starts with an empty video list\n", ($i["videos"] ?? null) === [] ? "PASS" : "FAIL");
printf("%s: §2.1 it defines the default creator ingestion assigns\n",
    isset($i["creators"]["default"]) ? "PASS" : "FAIL");
printf("%s: §2.1 it defines the Not Converted category ingestion assigns\n",
    isset($i["categories"]["Not Converted"]) ? "PASS" : "FAIL");
' | tee "/tmp/${PROBE}.o1"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o1")))

# "and must be unused"
post "/tmp/${PROBE}.r3" -d "username=${PROBE}&password=${PASS}&confirm_password=${PASS}" \
  http://localhost:8090/register.php >/dev/null
grep -q 'already taken' "/tmp/${PROBE}.r3"
check "§2.1 the username cannot be registered twice" $?

echo "--- §2.1 Login ---"

# Steps 3-4: "App attempts to extract {user}.json.epc using the submitted
# password. Success = login. Failure = rejected, no further detail given."
rm -f "$JAR"
OUT=$(post /dev/null -d "username=${PROBE}&password=wrong-password-entirely-xxx" http://localhost:8090/login.php)
case "$OUT" in *login.html*) true;; *) false;; esac
check "§2.1 the wrong password does not open the stash" $?

OUT=$(post /dev/null -d "username=${PROBE}&password=${PASS}" http://localhost:8090/login.php)
case "$OUT" in *wall.php) true;; *) false;; esac
check "§2.1 the right password opens it" $?

echo "--- §2.3 Upload ---"

post /dev/null -F "video=@${SRC}" http://localhost:8090/upload.php >/dev/null

# §2.3 step 4 + §3: "All generated artifacts are encrypted and written to the
# datastore" — the four files per video the layout names.
VID="App/Data/${PROBE}/videos/Video1"
for f in 1.mp4.epc 1.mp4.preview.epc 1.jpg.preview.epc 1.json.epc; do
  [ -f "${VID}/${f}" ]
  check "§3 the upload wrote ${f}" $?
done

docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
require_once "/app/src/VideoCategories.php";
require_once "/app/src/VideoCreators.php";
require_once "/app/src/VideoQuality.php";
$u = getenv("U"); $p = MyStash\StashKey::unlock($u, getenv("P"));
$store = new MyStash\Datastore();
$v = ($store->loadIndex($u, $p)["videos"] ?? [])[0] ?? null;
if ($v === null) { echo "FAIL: §2.3 the upload produced an index entry\n"; exit; }
echo "PASS: §2.3 the upload produced an index entry\n";

// "The creator list defaults to [default]"
printf("%s: §2.3 the creator list defaults to [default] (%s)\n",
    MyStash\VideoCreators::of($v) === ["default"] ? "PASS" : "FAIL",
    MyStash\VideoCreators::label($v));

// "If the uploaded format is not MP4 (H.265/HEVC), the video is tagged not converted"
printf("%s: §2.3 a .mov upload is tagged not converted\n", !empty($v["not_converted"]) ? "PASS" : "FAIL");

// §2.4 calls that tag a category, and ingestion assigns it as a real one.
$a = (new MyStash\VideoCategories())->load($u, $p, (string) $v["id"]);
$names = array_column($a, "name");
printf("%s: §2.4 it carries the Not Converted category assignment (%s)\n",
    in_array("Not Converted", $names, true) ? "PASS" : "FAIL", implode(", ", $names) ?: "none");

// "Quality ... recalculated from the stored technical facts (pixel height)"
printf("%s: §2.3 quality is derived from the stored height (%dp -> %s)\n",
    ($v["quality"] ?? "") === (MyStash\VideoQuality::tagForHeight($v["height"] ?? null) ?? "") ? "PASS" : "FAIL",
    (int) ($v["height"] ?? 0), $v["quality"] ?? "none");
' | tee "/tmp/${PROBE}.o2"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o2")))

# "Neither is editable ... letting a user type them in would only let them lie
# about it." Posting them must change nothing.
post /dev/null -d "id=1&title=Renamed&description=&quality=8K&not_converted=0" \
  http://localhost:8090/video_save.php >/dev/null
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
$v = ((new MyStash\Datastore())->loadIndex(getenv("U"), MyStash\StashKey::unlock(getenv("U"), getenv("P")))["videos"] ?? [])[0];
printf("%s: §2.3 the save did take the title (%s)\n", $v["title"] === "Renamed" ? "PASS" : "FAIL", $v["title"]);
printf("%s: §2.3 but a posted quality is ignored (%s)\n", ($v["quality"] ?? "") !== "8K" ? "PASS" : "FAIL", $v["quality"] ?? "none");
printf("%s: §2.3 and a posted not_converted is ignored\n", !empty($v["not_converted"]) ? "PASS" : "FAIL");
' | tee "/tmp/${PROBE}.o3"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o3")))

echo "--- §2.2 Video wall load ---"

curl -s -b "$JAR" -o "/tmp/${PROBE}.wall" http://localhost:8090/wall.php

# Step 2: "The video file itself is never loaded until the user clicks to play
# it." The tile links to the watch page and carries the preview clip with
# preload="none"; the video archive is not referenced at all.
grep -q 'type=thumb' "/tmp/${PROBE}.wall"
check "§2.2 the wall loads preview images from the index" $?

grep -q 'thumb-preview[^>]*preload="none"' "/tmp/${PROBE}.wall"
check "§2.2 the hover clip is preload=\"none\", so nothing streams on load" $?

! grep -q 'type=video' "/tmp/${PROBE}.wall"
check "§2.2 the wall never references the video file itself" $?

# The control for that negative: the string is a real one that does appear
# where the video genuinely is. Without this the grep above would pass just as
# well against a typo.
curl -s -b "$JAR" -o "/tmp/${PROBE}.watch" "http://localhost:8090/video.php?id=1"
grep -q 'type=video' "/tmp/${PROBE}.watch"
check "§2.2 (control) the watch page does reference it, so the grep above means something" $?

# Stronger than §2.2 asks: even the watch page holds the source on a data
# attribute rather than the player's src, so the file is not fetched until the
# play button is pressed.
grep -q 'data-video-src="media.php' "/tmp/${PROBE}.watch"
check "§2.2 the watch page holds the video back until play is pressed" $?

echo "--- §2.3 Views ---"

curl -s -b "$JAR" -o /dev/null "http://localhost:8090/video.php?id=1"
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
$v = ((new MyStash\Datastore())->loadIndex(getenv("U"), MyStash\StashKey::unlock(getenv("U"), getenv("P")))["videos"] ?? [])[0];
printf("%s: §2.3 opening the watch page does not count a view (%d)\n",
    (int) ($v["views"] ?? -1) === 0 ? "PASS" : "FAIL", (int) ($v["views"] ?? -1));
' | tee "/tmp/${PROBE}.o4"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o4")))

# The player posts this from its "playing" event.
curl -s -b "$JAR" -o /dev/null -d "id=1" http://localhost:8090/video_view.php
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = MyStash\StashKey::unlock($u, getenv("P"));
$store = new MyStash\Datastore();
$v = ($store->loadIndex($u, $p)["videos"] ?? [])[0];
$m = $store->loadVideoMetadata($u, $p, (string) $v["id"]);
printf("%s: §2.3 playback counts the view on the index entry (%d)\n",
    (int) ($v["views"] ?? 0) === 1 ? "PASS" : "FAIL", (int) ($v["views"] ?? 0));
// "written to both the per-video metadata and the index entry, so the wall can
// show it without decrypting anything"
printf("%s: §2.3 and on the per-video metadata too (%d)\n",
    (int) ($m["views"] ?? 0) === 1 ? "PASS" : "FAIL", (int) ($m["views"] ?? 0));
' | tee "/tmp/${PROBE}.o5"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o5")))

echo "--- §2.7 Categories ---"

curl -s -b "$JAR" -o /dev/null -d "name=Highlights&color=%23ffa31a" http://localhost:8090/category_save.php

# "The same category may be assigned multiple times at different timestamps."
curl -s -b "$JAR" -o /dev/null -d "id=1&name=Highlights&timestamp=00:00:10" http://localhost:8090/video_category_add.php
curl -s -b "$JAR" -o /dev/null -d "id=1&name=Highlights&timestamp=00:00:30" http://localhost:8090/video_category_add.php
# "Only the exact same category at the exact same timestamp is rejected."
curl -s -b "$JAR" -o /dev/null -d "id=1&name=Highlights&timestamp=00:00:30" http://localhost:8090/video_category_add.php
# "categories can't be invented ad hoc per video"
curl -s -b "$JAR" -o /dev/null -d "id=1&name=NotAGlobalCategory&timestamp=00:00:05" http://localhost:8090/video_category_add.php

docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/VideoCategories.php";
$a = (new MyStash\VideoCategories())->load(getenv("U"), MyStash\StashKey::unlock(getenv("U"), getenv("P")), "1");
$h = array_values(array_filter($a, fn($x) => $x["name"] === "Highlights"));
printf("%s: §2.7 the same category sits at two different timestamps (%d)\n",
    count($h) === 2 ? "PASS" : "FAIL", count($h));
$ts = array_map(fn($x) => (int) $x["timestamp_seconds"], $h);
sort($ts);
printf("%s: §2.7 the two that survived are the two distinct timestamps, so the repeat of 00:00:30 was refused (%s)\n",
    $ts === [10, 30] ? "PASS" : "FAIL", implode(", ", $ts));
printf("%s: §2.7 a category not in the global list cannot be assigned\n",
    !in_array("NotAGlobalCategory", array_column($a, "name"), true) ? "PASS" : "FAIL");
' | tee "/tmp/${PROBE}.o6"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o6")))

# "Removing a global category only retires the definition ... videos already
# tagged with it keep their tags."
curl -s -b "$JAR" -o /dev/null -d "name=Highlights" http://localhost:8090/category_delete.php
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/Datastore.php";
require_once "/app/src/VideoCategories.php";
$u = getenv("U"); $p = MyStash\StashKey::unlock($u, getenv("P"));
$i = (new MyStash\Datastore())->loadIndex($u, $p);
printf("%s: §2.7 deleting the category retires the global definition\n",
    !isset($i["categories"]["Highlights"]) ? "PASS" : "FAIL");
$a = (new MyStash\VideoCategories())->load($u, $p, "1");
printf("%s: §2.7 but the video keeps the tags it already had\n",
    in_array("Highlights", array_column($a, "name"), true) ? "PASS" : "FAIL");
' | tee "/tmp/${PROBE}.o7"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.o7")))

# The wall filter list comes from the global definitions, so a retired one
# leaves it. (Grepped inside the filter panel, not the whole page: the video
# still wears the tag in its tile stats, which is the point of the check above.)
curl -s -b "$JAR" -o "/tmp/${PROBE}.wall2" http://localhost:8090/wall.php
! grep -q 'name="category\[\]" value="Highlights"' "/tmp/${PROBE}.wall2"
check "§2.7 a retired category disappears from the wall's filter panel" $?

grep -A2 'tile-stats' "/tmp/${PROBE}.wall2" | grep -q 'Highlights'
check "§2.7 while the tile still shows it as a tag" $?
