#!/usr/bin/env bash
# The Stats screen: sizes on disk, Inspect, and the 30fps / 1920x1080 reduction.
#
# Drives the real endpoints over HTTP against a throwaway stash holding one
# generated 2560x1440 60fps clip — over both lines, so both reduce options are
# offered — and deletes the stash afterwards. A reduction rewrites the video's
# archive, which is not something to do to a real stash to prove a point.
set -uo pipefail
cd "$(dirname "$0")/.."

PROBE="StatsProbe$(openssl rand -hex 3)"
PASS="probe-stats-password-aaaaaaaaaa"
JAR="/tmp/${PROBE}.cookies"
BASE="http://localhost:8090"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }
page() { curl -s -b "$JAR" "${BASE}/stats.php"; }

cleanup() {
  # A worker finishing after the rm could write into the stash (v1's 7zip
  # recreated missing parents), so wait for it first.
  for _ in $(seq 1 60); do
    running=$(docker compose exec -T -u www-data app sh -c 'pgrep -fc "[j]ob_worker" || true' | tr -d "\r")
    [ "${running:-0}" = "0" ] && break
    sleep 1
  done
  rm -rf "App/Data/${PROBE}" "$JAR"
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_stats_check: all passed" || echo "run_stats_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

# --- a stash with one 1440p60 video -----------------------------------------
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app sh -c '
ffmpeg -v error -y -f lavfi -i testsrc=size=2560x1440:rate=60:duration=3 \
  -f lavfi -i sine=frequency=440:duration=3 \
  -c:v libx264 -preset ultrafast -c:a aac -pix_fmt yuv420p -shortest /dev/shm/stats-src.mp4 </dev/null &&
php -r "
require_once \"/app/src/User.php\";
require_once \"/app/src/VideoEncoder.php\";
require_once \"/app/src/VideoCategories.php\";
require_once \"/app/src/VideoIngest.php\";
require_once \"/app/src/Datastore.php\";
\$u = getenv(\"U\"); \$p = getenv(\"P\");
if (!(\$p = (new MyStash\User())->create(\$u, \$p))) { fwrite(STDERR, \"create failed\n\"); exit(1); }
\$store = new MyStash\Datastore();
\$index = \$store->loadIndex(\$u, \$p);
\$entry = (new MyStash\VideoIngest())->ingest(\$u, \$p, \"/dev/shm/stats-src.mp4\", \"Big sixty.mp4\", \$index);
\$index[\"videos\"][] = \$entry;
\$store->saveIndex(\$u, \$p, \$index);
echo \"ingested video {\$entry[\"id\"]}\n\";
"; rc=$?; rm -f /dev/shm/stats-src.mp4; exit $rc' || { echo "FAIL: could not build the throwaway stash"; fails=1; exit 1; }

curl -s -c "$JAR" -o /dev/null -d "username=${PROBE}&password=${PASS}" "${BASE}/login.php"

# --- the page, before inspection ---------------------------------------------
HTML=$(page)
grep -q 'href="stats.php"' <<<"$HTML"; check "the user menu links to Stats" $?
grep -q 'id="video-1"' <<<"$HTML"; check "the video has a row" $?
grep -q 'Not inspected' <<<"$HTML"; check "an uninspected video says so" $?
grep -qE '[0-9.]+ (KB|MB)</td>' <<<"$HTML"; check "the row shows a size on disk" $?
! grep -q 'video_reduce.php' <<<"$HTML"; check "nothing is offered for reduction before inspection" $?

# --- inspect -----------------------------------------------------------------
LOC=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' -d "id=1" "${BASE}/video_inspect.php")
grep -q 'inspected=1' <<<"$LOC"; check "inspect redirects back with the video marked ($LOC)" $?

HTML=$(page)
grep -q '60 fps' <<<"$HTML"; check "inspect measured 60 fps" $?
grep -q '2560×1440' <<<"$HTML"; check "inspect measured 2560×1440" $?
grep -q 'name="fps"' <<<"$HTML"; check "the 30fps reduction is offered" $?
grep -q 'name="scale"' <<<"$HTML"; check "the 1920×1080 reduction is offered" $?

# STATS_SHOTS=1 photographs the inspected row and its reduce offer, which
# only exists on a stash that has been inspected — so it is taken here, on the
# throwaway one, rather than by screenshot_stats.js against TestUser.
if [ "${STATS_SHOTS:-0}" = "1" ]; then
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T \
    -e NODE_PATH=/opt/pwlib/node_modules -e U="$PROBE" -e P="$PASS" playwright node -e '
const { chromium } = require("playwright");
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 600 } });
  await p.goto("http://app:8080/login.html");
  await p.fill("#username", process.env.U); await p.fill("#password", process.env.P);
  await p.click("button[type=submit]"); await p.waitForSelector(".video-grid");
  await p.goto("http://app:8080/stats.php?inspected=1");
  await p.screenshot({ path: "/work/screenshots/stats_inspected.png" });
  await b.close();
})();'
fi

# --- the endpoint refuses what does not apply ----------------------------------
LOC=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' -d "id=1" "${BASE}/video_reduce.php")
grep -q 'error=nothing' <<<"$LOC"; check "a reduce with no options ticked is refused" $?

# --- reduce both ---------------------------------------------------------------
curl -s -b "$JAR" -o /dev/null -d "id=1&fps=1&scale=1" "${BASE}/video_reduce.php"
grep -q 'id="job-card"' <<<"$(page)"; check "Stats shows the progress card while it runs" $?

STATE=""
for _ in $(seq 1 180); do
  STATE=$(curl -s -b "$JAR" "${BASE}/job_status.php?kind=convert&target=1" | sed -n 's/.*"state":"\([a-z]*\)".*/\1/p')
  case "$STATE" in done|failed) break;; esac
  sleep 1
done
[ "$STATE" = "done" ]; check "the reduction finishes (state '${STATE}')" $?

HTML=$(page)
grep -q '30 fps' <<<"$HTML"; check "Stats now shows 30 fps" $?
grep -q '1920×1080' <<<"$HTML"; check "Stats now shows 1920×1080" $?
grep -q 'Within 30fps and 1920×1080' <<<"$HTML"; check "nothing more is offered" $?

# --- and the stored file really is what the page says --------------------------
docker compose exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/StashCrypto.php";
require_once "/app/src/StashKey.php";
require_once "/app/src/VideoEncoder.php";
$dir = "/app/Data/" . getenv("U") . "/videos/Video1";
(new MyStash\StashCrypto())->extract("{$dir}/1.mp4.epc", "/dev/shm/stats-after", MyStash\StashKey::unlock(getenv("U"), getenv("P")));
$file = glob("/dev/shm/stats-after/*")[0] ?? null;
$probe = $file ? (new MyStash\VideoEncoder())->probe($file) : null;
printf("%s: the stored video is 1920x1080 at 30fps H.265 (%s)\n",
    $probe && $probe["width"] === 1920 && $probe["height"] === 1080 && abs($probe["fps"] - 30) < 0.01 && $probe["codec"] === "hevc" ? "PASS" : "FAIL",
    json_encode($probe));
$litter = array_merge(glob("{$dir}/*.old"), glob("{$dir}/*.new*"));
printf("%s: no .old or .new copies left behind\n", $litter === [] ? "PASS" : "FAIL");
if ($file) { unlink($file); } @rmdir("/dev/shm/stats-after");
' | tee "/tmp/${PROBE}.out"
fails=$((fails + $(grep -c '^FAIL' "/tmp/${PROBE}.out")))
rm -f "/tmp/${PROBE}.out"
