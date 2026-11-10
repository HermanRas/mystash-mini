#!/usr/bin/env bash
# 4.28/4.29 — conversion runs in the background, and must never lose the video
# it is converting.
#
# Drives the real video_convert.php endpoint over HTTP, against a throwaway
# stash this script creates and deletes. It never touches a real user: a
# conversion rewrites the video's archive, which is not something to do to
# someone's stash to prove a point.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="ConvProbe$(openssl rand -hex 3)"
PASS="probe-convert-password-aaaaaaaa"
JAR="/tmp/${PROBE}.cookies"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

cleanup() {
  # A worker still running could write into what is about to be removed — in
  # v1, 7zip created missing parent directories, so a convert finishing after
  # the rm left a half-resurrected stash behind. That actually happened here.
  for _ in $(seq 1 60); do
    running=$(docker compose exec -T -u www-data app sh -c 'pgrep -fc "[j]ob_worker" || true' | tr -d "\r")
    [ "${running:-0}" = "0" ] && break
    sleep 1
  done

  rm -rf "App/Data/${PROBE}" "$JAR"
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_convert_check: all passed" || echo "run_convert_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

# --- a stash with one real, unconverted video -----------------------------
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/User.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/VideoCategories.php";
require_once "/app/src/VideoIngest.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
if (!($p = (new MyStash\User())->create($u, $p))) { fwrite(STDERR, "create failed\n"); exit(1); }
$store = new MyStash\Datastore();
$index = $store->loadIndex($u, $p);
// A .mov container makes it "Not Converted" whatever its codec, so the
// convert button has real work to do.
copy("/app/tests/fixture.mp4", "/dev/shm/probe-src.mov");
$entry = (new MyStash\VideoIngest())->ingest($u, $p, "/dev/shm/probe-src.mov", "probe.mov", $index);
$index["videos"][] = $entry;
$store->saveIndex($u, $p, $index);
echo "ingested video {$entry["id"]}, not_converted=", var_export($entry["not_converted"], true), "\n";
' || { echo "FAIL: could not build the throwaway stash"; fails=1; exit 1; }

# The bytes we must still have afterwards, whatever happens.
BEFORE=$(docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/StashCrypto.php";
require_once "/app/src/StashKey.php";
$c = new MyStash\StashCrypto();
$c->extract("/app/Data/" . getenv("U") . "/videos/Video1/1.mp4.epc", "/dev/shm/before", MyStash\StashKey::unlock(getenv("U"), getenv("P")));
echo hash_file("sha256", glob("/dev/shm/before/*")[0]);
array_map("unlink", glob("/dev/shm/before/*")); rmdir("/dev/shm/before");
')
echo "  original video sha256: ${BEFORE:0:16}…"

# --- convert it through the endpoint a user would press -------------------
curl -s -c "$JAR" -o /dev/null -d "username=${PROBE}&password=${PASS}" http://localhost:8080/login.php

# 4.28: the POST starts a job and returns. If it ever goes back to doing the
# transcode inline, this is the assertion that notices — the whole failure was
# a request that never came back.
POST_MS=$(curl -s -b "$JAR" -o /dev/null -w '%{time_total}' -d "id=1" http://localhost:8080/video_convert.php \
  | awk '{printf "%d", $1 * 1000}')
echo "  convert POST returned in ${POST_MS}ms"
[ "$POST_MS" -lt 3000 ]; check "the convert POST returns immediately instead of blocking on ffmpeg" $?

# ...and the rest of the site answers while it runs. This is the symptom that
# started 4.28: one transcode and the whole app stopped responding.
WALL_MS=$(curl -s -b "$JAR" -o /dev/null -w '%{time_total}' http://localhost:8080/wall.php \
  | awk '{printf "%d", $1 * 1000}')
echo "  wall.php answered in ${WALL_MS}ms while the conversion was running"
[ "$WALL_MS" -lt 5000 ]; check "the site still answers while a conversion is running" $?

# A second press *while the first is still running* must not start a second
# ffmpeg over the same archive. The job id is derived from the user and the
# video, so the second press finds the first job instead of launching another.
# (The pattern is bracketed so pgrep does not count the shell running it.)
curl -s -b "$JAR" -o /dev/null -d "id=1" http://localhost:8080/video_convert.php
WORKERS=$(docker compose exec -T -u www-data app sh -c 'pgrep -fc "[j]ob_worker" || true' | tr -d "\r")
echo "  job_worker processes after a second press: ${WORKERS:-0}"
[ "${WORKERS:-0}" -le 1 ]; check "a second Convert press does not start a second worker" $?

# --- watch the job the way the browser does -------------------------------
STATE=""; SEEN_RUNNING=0; PEAK=0
for _ in $(seq 1 180); do
  JSON=$(curl -s -b "$JAR" "http://localhost:8080/job_status.php?kind=convert&target=1")
  STATE=$(printf '%s' "$JSON" | sed -n 's/.*"state":"\([a-z]*\)".*/\1/p')
  PERCENT=$(printf '%s' "$JSON" | sed -n 's/.*"percent":\([0-9]*\).*/\1/p')
  [ "$STATE" = "running" ] && SEEN_RUNNING=1
  [ -n "${PERCENT:-}" ] && [ "$PERCENT" -gt "$PEAK" ] && PEAK=$PERCENT
  case "$STATE" in done|failed) break;; esac
  sleep 1
done
echo "  job ended in state '${STATE}', highest reported progress ${PEAK}%"
[ "$SEEN_RUNNING" = "1" ]; check "the job reports itself as running while it works" $?
[ "$STATE" = "done" ]; check "the job finishes successfully" $?

# --- the video must still be there, and now be MP4/H.265 ------------------
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" -e B="$BEFORE" app php -r '
require_once "/app/src/StashCrypto.php";
require_once "/app/src/StashKey.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = MyStash\StashKey::unlock($u, getenv("P"));
$dir = "/app/Data/{$u}/videos/Video1";
$c = new MyStash\StashCrypto();
$ok = $c->extract("{$dir}/1.mp4.epc", "/dev/shm/after", $p);
$file = $ok ? (glob("/dev/shm/after/*")[0] ?? null) : null;
printf("%s: the video archive still decrypts\n", $file !== null ? "PASS" : "FAIL");
if ($file !== null) {
    $enc = new MyStash\VideoEncoder();
    printf("%s: it is now MP4/H.265 (%s)\n",
        $enc->isAlreadyMp4Hevc($file) ? "PASS" : "FAIL", $enc->videoCodec($file) ?? "?");
    printf("%s: the bytes changed, so it really was re-encoded\n",
        hash_file("sha256", $file) !== getenv("B") ? "PASS" : "FAIL");
    printf("  converted size: %d bytes\n", filesize($file));
}
$litter = array_merge(glob("{$dir}/*.old"), glob("{$dir}/*.new"));
printf("%s: no .old or .new copies left behind (%s)\n",
    $litter === [] ? "PASS" : "FAIL", $litter === [] ? "none" : implode(", ", array_map("basename", $litter)));
$index = (new MyStash\Datastore())->loadIndex($u, $p);
printf("%s: the index still opens and no longer says Not Converted\n",
    ($index !== null && empty($index["videos"][0]["not_converted"])) ? "PASS" : "FAIL");
if (is_dir("/dev/shm/after")) { array_map("unlink", glob("/dev/shm/after/*")); rmdir("/dev/shm/after"); }
' | tee /tmp/${PROBE}.out
fails=$((fails + $(grep -c '^FAIL' /tmp/${PROBE}.out)))
rm -f /tmp/${PROBE}.out
