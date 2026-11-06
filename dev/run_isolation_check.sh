#!/usr/bin/env bash
# 7.1 — isolation between App/Data/{user}/ datastores.
#
# Two throwaway stashes: one plays the attacker, one the victim. Every check
# drives the real endpoints over HTTP as the attacker and then looks at the
# victim's directory on disk, because the only question that matters is
# whether the victim's bytes changed.
#
# The victim is a stash this script creates and destroys. TestUser is never
# the target of an attack here, for obvious reasons.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

ATK="IsoAtk$(openssl rand -hex 3)"
VIC="IsoVic$(openssl rand -hex 3)"
PW="isolation-probe-password-aaaaaaaa"
VPW="victim-isolation-password-bbbbbbbb"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

cleanup() {
  for _ in $(seq 1 60); do
    running=$(docker compose exec -T -u www-data app sh -c 'pgrep -fc "[j]ob_worker" || true' | tr -d '\r')
    [ "${running:-0}" = "0" ] && break
    sleep 1
  done
  rm -rf "App/Data/${ATK}" "App/Data/${VIC}" "/tmp/${ATK}".* "/tmp/${VIC}".*
  echo "removed throwaway stashes ${ATK} and ${VIC}"
  [ "$fails" = "0" ] && echo "run_isolation_check: all passed" || echo "run_isolation_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

mkstash() { # name password
  curl -s -c "/tmp/$1.jar" -o /dev/null \
    --data-urlencode "username=$1" --data-urlencode "password=$2" \
    --data-urlencode "confirm_password=$2" http://localhost:8090/register.php
  cp "$FIXTURE" "/tmp/$1.mp4"
  curl -s -b "/tmp/$1.jar" -o /dev/null \
    -F "video=@/tmp/$1.mp4;filename=v.mp4" http://localhost:8090/upload.php
}

mkstash "$ATK" "$PW"
mkstash "$VIC" "$VPW"

[ -d "App/Data/${VIC}/videos/Video1" ]; check "both stashes exist with a video each" $?

# Everything below is judged against this: the victim's files, byte for byte.
victim_state() { find "App/Data/${VIC}" -type f -exec sha256sum {} \; 2>/dev/null | sort; }
BEFORE=$(victim_state)

# --- the feature still works for its owner --------------------------------
# Checked first and deliberately: it would be easy to "fix" isolation by
# breaking deletion for everybody, and that would pass every test below.
curl -s -b "/tmp/${ATK}.jar" -o /dev/null -d "id=1" http://localhost:8090/video_delete.php
[ ! -d "App/Data/${ATK}/videos/Video1" ]; check "a user can still delete their own video" $?

# Put it back, so the traversal attempts below have a real Video1 to pivot from.
curl -s -b "/tmp/${ATK}.jar" -o /dev/null \
  -F "video=@/tmp/${ATK}.mp4;filename=again.mp4" http://localhost:8090/upload.php

# --- deleting across stashes ----------------------------------------------
# The one that actually worked: deletion never decrypts, so the encryption
# protecting every other endpoint protected nothing here.
for BAD in \
  "1/../../../${VIC}/videos/Video1" \
  "2/../../../${VIC}/videos/Video1" \
  "../../../${VIC}/videos/Video1" \
  "1/../../.." ; do
  curl -s -b "/tmp/${ATK}.jar" -o /dev/null --data-urlencode "id=${BAD}" \
    http://localhost:8090/video_delete.php
done
[ -d "App/Data/${VIC}/videos/Video1" ]
check "video_delete.php cannot delete another user's video" $?
[ -d "App/Data/${VIC}" ]; check "...nor another user's whole stash" $?

# --- reading across stashes -----------------------------------------------
for T in thumb video preview; do
  CODE=$(curl -s -b "/tmp/${ATK}.jar" -o /dev/null -w '%{http_code}' \
    --get --data-urlencode "id=1/../../../${VIC}/videos/Video1" --data-urlencode "type=${T}" \
    http://localhost:8090/media.php)
  [ "$CODE" = "400" ]; check "media.php refuses a traversing id for type=${T} (HTTP ${CODE})" $?
done

CODE=$(curl -s -b "/tmp/${ATK}.jar" -o /dev/null -w '%{http_code}' \
  --get --data-urlencode "type=avatar" --data-urlencode "creator=1/../../../${VIC}/creators/Creator1" \
  http://localhost:8090/media.php)
[ "$CODE" = "400" ]; check "media.php refuses a traversing creator id (HTTP ${CODE})" $?

# --- writing across stashes -----------------------------------------------
# These do decrypt, so they would fail anyway — but they would fail *after*
# writing, which is the interesting part.
curl -s -b "/tmp/${ATK}.jar" -o /dev/null \
  --data-urlencode "id=1/../../../${VIC}/videos/Video1" --data-urlencode "name=Not Converted" \
  --data-urlencode "timestamp=00:00:05" http://localhost:8090/video_category_add.php
curl -s -b "/tmp/${ATK}.jar" -o /dev/null \
  --data-urlencode "id=1/../../../${VIC}/videos/Video1" --data-urlencode "title=owned" \
  http://localhost:8090/video_save.php
curl -s -b "/tmp/${ATK}.jar" -o /dev/null \
  --data-urlencode "id=1/../../../${VIC}/videos/Video1" http://localhost:8090/video_convert.php

[ "$(victim_state)" = "$BEFORE" ]
check "no endpoint changed a single byte of the victim's stash" $?

# --- the wall shows only your own -----------------------------------------
ATK_WALL=$(curl -s -b "/tmp/${ATK}.jar" http://localhost:8090/wall.php)
printf '%s' "$ATK_WALL" | grep -q "$VIC"; [ $? -ne 0 ]
check "the attacker's wall does not mention the victim anywhere" $?

# --- can one user prove another exists? -----------------------------------
# Login must not distinguish "no such user" from "wrong password": a stash that
# cannot be opened is a stash that cannot be opened, whichever the reason.
REAL=$(curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' \
  --data-urlencode "username=${VIC}" --data-urlencode "password=wrong-password-entirely-aaaa" \
  http://localhost:8090/login.php)
FAKE=$(curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' \
  --data-urlencode "username=NoSuchUser$(openssl rand -hex 3)" --data-urlencode "password=wrong-password-entirely-aaaa" \
  http://localhost:8090/login.php)
echo "  existing user, wrong password: ${REAL}"
echo "  user that does not exist:      ${FAKE}"
[ "$REAL" = "$FAKE" ]
check "login gives the same answer for a wrong password and an unknown user" $?

# --- job records are namespaced per user ----------------------------------
# Jobs::id() hashes the username in, so there is no id to guess and no way to
# ask about somebody else's job.
STATUS=$(curl -s -b "/tmp/${ATK}.jar" \
  --get --data-urlencode "kind=convert" --data-urlencode "target=1" \
  http://localhost:8090/job_status.php)
echo "  attacker polling convert/1: ${STATUS}"
printf '%s' "$STATUS" | grep -q '"state":"none"'
check "a job poll only ever sees this user's own jobs" $?

# --- wipe() refuses to work outside the datastore -------------------------
# NEVER point this at a real directory.
#
# The obvious way to test "wipe() refuses /app/src" is to call it on /app/src
# and check /app/src is still there. That is only safe if it refuses — which is
# the thing under test. Running exactly that with the guard temporarily removed,
# to prove this suite could still catch the bug it was written for, deleted
# App/src and the whole of App/Data for real.
#
# So the refusal is proved against sacrificial directories that nothing needs:
# if the guard is ever missing again, the only casualties are these.
OUT=$(docker compose exec -T -u www-data app php -r '
require_once "/app/src/Datastore.php";
$outside = "/tmp/iso-sacrifice-" . bin2hex(random_bytes(4));   // not under Data, not /dev/shm
@mkdir($outside . "/sub", 0700, true); touch($outside . "/sub/keep");
MyStash\Datastore::wipe($outside);
printf("a directory outside the datastore survives: %s\n", is_dir($outside) ? "yes" : "NO");
@unlink($outside . "/sub/keep"); @rmdir($outside . "/sub"); @rmdir($outside);

$inside = "/dev/shm/iso-work-" . bin2hex(random_bytes(4));
@mkdir($inside . "/sub", 0700, true); touch($inside . "/sub/keep");
MyStash\Datastore::wipe($inside);
printf("a real tmpfs work dir is still removed: %s\n", is_dir($inside) ? "NO" : "yes");

// The roots themselves are never the thing being removed, so they must be
// refused too — asserted by asking, not by calling wipe() on them.
printf("the data root itself is refused: %s\n",
    MyStash\Datastore::wipeWouldRefuse("/app/Data") ? "yes" : "NO");
printf("the source tree is refused: %s\n",
    MyStash\Datastore::wipeWouldRefuse("/app/src") ? "yes" : "NO");
printf("/etc is refused: %s\n",
    MyStash\Datastore::wipeWouldRefuse("/etc") ? "yes" : "NO");
' 2>&1 | tr -d '\r')
echo "$OUT" | sed 's/^/  /'
[ "$(printf '%s' "$OUT" | grep -c ': yes')" = "5" ]
check "Datastore::wipe() refuses every path outside the datastore, and still does its job" $?

# Bad ids must never reach a path builder — and if one ever does, it throws.
THROWS=$(docker compose exec -T -u www-data app php -r '
require_once "/app/src/Datastore.php";
foreach (["1/../../x", "../..", "", "abc", "1;rm -rf /", str_repeat("9", 40)] as $bad) {
    try { MyStash\Datastore::videoDir("u", $bad); echo "ACCEPTED: {$bad}\n"; }
    catch (InvalidArgumentException) { echo "refused\n"; }
}' 2>&1 | tr -d '\r')
[ "$(printf '%s' "$THROWS" | grep -c '^refused')" = "6" ]
check "Datastore::videoDir() refuses every malformed id (6/6)" $?
