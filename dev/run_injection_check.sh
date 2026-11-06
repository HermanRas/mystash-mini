#!/usr/bin/env bash
# 7.2 — command-injection safety at the boundary where PHP runs other programs.
#
# The unit-level proof lives in smoke_test.php ("hostile input at the command
# boundary"). This is the other half: the same hostile values pushed through
# the real HTTP endpoints a browser talks to, on a throwaway stash, so the
# whole stack is exercised — nginx, php-fpm, the session, the upload handler —
# rather than the classes in isolation.
#
# Canaries are checked *inside the php container*, because that is where the
# command would run. Checking the host would pass no matter what happened.
set -uo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=dev/fixture.sh
. "$(dirname "$0")/fixture.sh"
ensure_fixture || { echo "could not build the upload fixture"; exit 1; }

PROBE="InjProbe$(openssl rand -hex 3)"
# Every shell metacharacter that matters, and long enough for 7.0.1's minimum.
PASS='inj$(touch /tmp/c-pw)`touch /tmp/c-bt`;|&<>"'"'"' --zz'
JAR="/tmp/${PROBE}.cookies"
CANARIES="/tmp/c-pw /tmp/c-bt /tmp/c-fn /tmp/c-up"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

incontainer() { docker compose exec -T -u www-data app sh -c "$1" | tr -d '\r'; }

cleanup() {
  for _ in $(seq 1 60); do
    running=$(incontainer 'pgrep -fc "[j]ob_worker" || true')
    [ "${running:-0}" = "0" ] && break
    sleep 1
  done
  rm -rf "App/Data/${PROBE}" "$JAR" "/tmp/${PROBE}.mp4"
  incontainer "rm -f ${CANARIES}" >/dev/null
  echo "removed throwaway stash ${PROBE}"
  [ "$fails" = "0" ] && echo "run_injection_check: all passed" || echo "run_injection_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

# Start from a clean slate, or a leftover canary would read as a fresh hit.
incontainer "rm -f ${CANARIES}" >/dev/null

# --- register over HTTP, with a password made of shell metacharacters -----
# The password is handed to 7z as -p<password> on every encrypt and extract in
# the app, so if anything reached a shell it would reach it here.
curl -s -c "$JAR" -o /dev/null \
  --data-urlencode "username=${PROBE}" \
  --data-urlencode "password=${PASS}" \
  --data-urlencode "confirm_password=${PASS}" \
  http://localhost:8090/register.php

[ -d "App/Data/${PROBE}" ]; check "a stash registers with a password of pure shell metacharacters" $?

# If the password had been mangled on the way in, the index would not reopen.
rm -f "$JAR"
curl -s -c "$JAR" -o /dev/null \
  --data-urlencode "username=${PROBE}" --data-urlencode "password=${PASS}" \
  http://localhost:8090/login.php
WALL=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' http://localhost:8090/wall.php)
[ "$WALL" = "200" ]; check "...and it logs back in, so the password was stored verbatim (HTTP ${WALL})" $?

# --- upload with a hostile filename through the real form ----------------
cp "$FIXTURE" "/tmp/${PROBE}.mp4"
UP=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' \
  -F 'video=@/tmp/'"${PROBE}"'.mp4;filename=evil$(touch /tmp/c-fn)`touch /tmp/c-up`;id.mp4' \
  http://localhost:8090/upload.php)
echo "  upload.php answered ${UP}"
[ -d "App/Data/${PROBE}/videos/Video1" ]; check "a video with a hostile filename uploads normally" $?

for c in $CANARIES; do
  HIT=$(incontainer "test -e ${c} && echo yes || echo no")
  [ "$HIT" = "no" ]; check "nothing executed in the container: ${c}" $?
done

# --- preview_at, which upload.php casts straight from the form -----------
# 7.2.1: a timestamp past the end used to lose the whole upload and leave an
# unreachable directory behind.
cp "$FIXTURE" "/tmp/${PROBE}.mp4"
curl -s -b "$JAR" -o /dev/null \
  -F "video=@/tmp/${PROBE}.mp4;filename=far.mp4" -F "preview_at=999999" \
  http://localhost:8090/upload.php
[ -f "App/Data/${PROBE}/videos/Video2/2.jpg.preview.enc" ]
check "an out-of-range preview_at still produces a video with a preview" $?

# Every directory on disk must be one the index knows about — that is exactly
# what an orphan is not.
# Counted as *distinct ids*, not as occurrences: each tile references media.php
# more than once (thumbnail and hover clip), so a plain grep -c would pass
# however many directories were orphaned.
ONDISK=$(ls -d App/Data/${PROBE}/videos/Video* 2>/dev/null | sed 's/.*Video//' | sort -u | tr '\n' ' ')
ONWALL=$(curl -s -b "$JAR" http://localhost:8090/wall.php \
  | grep -o 'media\.php?id=[0-9]*' | sed 's/.*=//' | sort -u | tr '\n' ' ')
echo "  ids on disk: [${ONDISK}] ids on the wall: [${ONWALL}]"
[ -n "$ONDISK" ] && [ "$ONDISK" = "$ONWALL" ]
check "no orphaned video directories: the ids on disk are exactly the ids on the wall" $?

# --- tampering with the job endpoints ------------------------------------
# kind is allowlisted, and Jobs::path() re-checks the assembled id against a
# regex, so a crafted kind cannot become a path.
for BAD in '../../etc/passwd' 'convert;id' 'rekey/../../..' '' 'CONVERT'; do
  CODE=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' \
    --get --data-urlencode "kind=${BAD}" --data-urlencode "target=1" \
    http://localhost:8090/job_status.php)
  [ "$CODE" = "400" ]; check "job_status.php refuses kind='${BAD}' (HTTP ${CODE})" $?
done

# The worker refuses an id that is not hash-shaped, so a crafted argv is inert.
# Jobs::path() throws InvalidArgumentException on anything that is not
# {kind}-{16 hex}, so a crafted argv dies before it can be used as a path. The
# output is shown rather than swallowed: a check that cannot be seen to have
# run is one that can silently stop running.
incontainer "rm -f /tmp/c-fn" >/dev/null
# The \$ is escaped so the container's own sh hands the substitution to PHP
# untouched instead of running it itself — without that, this test executes the
# canary on its own and reports the app for it. (It did, once.)
OUT=$(incontainer 'php /app/bin/job_worker.php "convert-\$(touch /tmp/c-fn)x" 2>&1 | tr "\n" " " | cut -c1-110')
echo "  worker, crafted job id -> ${OUT:-(no output)}"
printf '%s' "$OUT" | grep -q 'Bad job id'
check "the job worker rejects a job id that is not hash-shaped" $?
HIT=$(incontainer 'test -e /tmp/c-fn && echo yes || echo no')
[ "$HIT" = "no" ]; check "...and nothing in that id was executed" $?
