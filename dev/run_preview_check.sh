#!/usr/bin/env bash
# 3.9 — setting and changing a video's preview image, through the real forms.
#
# Rewrites the preview archive, so it runs against a throwaway stash this
# script builds and removes rather than a real user's.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="PrevProbe$(openssl rand -hex 3)"
PASS="probe-preview-password-aaaaaaaa"
fails=0

DC="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

cleanup() {
  rm -rf "App/Data/${PROBE}"
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_preview_check: all passed" || echo "run_preview_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

$DC exec -T -u www-data -e U="$PROBE" -e P="$PASS" app php -r '
require_once "/app/src/User.php";
require_once "/app/src/VideoEncoder.php";
require_once "/app/src/VideoCategories.php";
require_once "/app/src/VideoIngest.php";
require_once "/app/src/Datastore.php";
$u = getenv("U"); $p = getenv("P");
if (!($p = (new MyStash\User())->create($u, $p))) { fwrite(STDERR, "create failed\n"); exit(1); }
$store = new MyStash\Datastore();
$index = $store->loadIndex($u, $p);
$entry = (new MyStash\VideoIngest())->ingest(
    $u, $p, "/app/tests/fixture.mp4", "preview-probe.mp4", $index);
$index["videos"][] = $entry;
$store->saveIndex($u, $p, $index);
echo "ingested video {$entry["id"]}\n";
' || { echo "FAIL: could not build the throwaway stash"; fails=1; exit 1; }

$DC exec -T -e NODE_PATH=/opt/pwlib/node_modules \
  -e PROBE_USER="$PROBE" -e PROBE_PASS="$PASS" \
  playwright node /work/check_preview.js
fails=$?
