#!/usr/bin/env bash
# 4.32 — the management screens' column, and the convert progress panel's
# position while a conversion is actually running.
#
# The panel check needs a real running job, and a job long enough to still be
# running when the browser looks. That means converting a real video, which is
# never done to a real stash: this builds a throwaway one and removes it.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="LayProbe$(openssl rand -hex 3)"
PASS="probe-layout-password-aaaaaaaa"
JAR="/tmp/${PROBE}.cookies"
fails=0

DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

cleanup() {
  # Same reason as run_convert_check.sh: a worker still running would recreate
  # part of what is about to be removed, because 7zip creates missing parents.
  for _ in $(seq 1 60); do
    running=$($DC exec -T -u www-data app sh -c 'pgrep -fc "[j]ob_worker" || true' | tr -d "\r")
    [ "${running:-0}" = "0" ] && break
    sleep 1
  done

  rm -rf "App/Data/${PROBE}" "$JAR"
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_layout_check: all passed" || echo "run_layout_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

# --- the management screens, against the real TestUser stash (read-only) ---
$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules playwright node /work/check_layout.js
fails=$((fails + $?))

# --- a throwaway stash with one unconverted video -------------------------
$DC exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/User.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/VideoCategories.php";
require_once "/app/src/VideoIngest.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
if (!(new MyStash\User())->create($u, $p)) { fwrite(STDERR, "create failed\n"); exit(1); }
$store = new MyStash\Datastore();
$index = $store->loadIndex($u, $p);
// Not the shared fixture: this check needs a conversion that is still
// running when the browser looks at the page, and the fixture converts in
// about a second. Half a minute of 720p is still being converted when the
// browser looks, without making the cleanup wait minutes for the worker.
exec("ffmpeg -v error -y -f lavfi -i testsrc=size=1280x720:rate=30:duration=30 "
   . "-c:v libx264 -preset ultrafast -pix_fmt yuv420p /dev/shm/layout-src.mov");
$entry = (new MyStash\VideoIngest())->ingest($u, $p, "/dev/shm/layout-src.mov", "probe.mov", $index);
$index["videos"][] = $entry;
$store->saveIndex($u, $p, $index);
echo "ingested video {$entry["id"]}\n";
' || { echo "FAIL: could not build the throwaway stash"; fails=$((fails+1)); exit 1; }

# --- start a conversion and look at the page while it runs ----------------
curl -s -c "$JAR" -o /dev/null -d "username=${PROBE}&password=${PASS}" http://localhost:8090/login.php
curl -s -b "$JAR" -o /dev/null -d "id=1" http://localhost:8090/video_convert.php

$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules \
  -e PROBE_USER="$PROBE" -e PROBE_PASS="$PASS" \
  playwright node /work/check_convert_panel.js
fails=$((fails + $?))
