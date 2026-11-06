#!/usr/bin/env bash
# 7.3 — login throttling, and the timing oracle it also closes.
#
# Drives login.php over HTTP against a throwaway stash. It never throttles a
# real account: a locked name has to wait the window out, and there is no reset
# to shortcut it, so locking TestUser would mean locking it for real.
set -uo pipefail
cd "$(dirname "$0")/.."

PROBE="ThrProbe$(openssl rand -hex 3)"
PASS="throttle-probe-password-aaaaaaa"
WRONG="definitely-the-wrong-password-x"
fails=0

check() { if [ "$2" = "0" ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }

# Every run starts from an empty counter store, and leaves one behind, so a
# check here can never lock the developer out of their own stash.
clear_store() { docker compose exec -T -u www-data app sh -c 'rm -rf /dev/shm/mystash-login' >/dev/null 2>&1; }

cleanup() {
  rm -rf "App/Data/${PROBE}" "/tmp/${PROBE}".*
  clear_store
  echo "removed throwaway stash ${PROBE} and cleared the throttle store"
  [ "$fails" = "0" ] && echo "run_throttle_check: all passed" || echo "run_throttle_check: ${fails} failed"
  exit "$fails"
}
trap cleanup EXIT

clear_store
curl -s -o /dev/null --data-urlencode "username=${PROBE}" --data-urlencode "password=${PASS}" \
  --data-urlencode "confirm_password=${PASS}" http://localhost:8090/register.php
[ -d "App/Data/${PROBE}" ]; check "a throwaway stash exists to attack" $?

attempt() { # username password -> "redirect|seconds"
  curl -s -o /dev/null -w '%{redirect_url}|%{time_total}' \
    --data-urlencode "username=$1" --data-urlencode "password=$2" \
    http://localhost:8090/login.php
}

mean_ms() { # username -> mean milliseconds over $2 attempts
  local total=0 t n=$2
  for _ in $(seq 1 "$n"); do
    t=$(attempt "$1" "$WRONG" | cut -d'|' -f2)
    total=$(echo "$total + $t" | bc -l)
  done
  # Multiply before dividing: with scale=0 a mean of 0.25s truncates to 0
  # first, and then every timing comparison below is comparing zeroes.
  echo "$(echo "scale=0; ($total * 1000) / $n" | bc -l)"
}

# --- the timing oracle ----------------------------------------------------
# A wrong password against a real stash runs 7z; against a name with no stash
# nothing runs at all. Before the constant-time floor that was ~130ms against
# ~16ms — replies identical, clock not, which is a username oracle and exactly
# what 7.1 says login must not be.
clear_store
REAL_MS=$(mean_ms "$PROBE" 6)
clear_store
FAKE_MS=$(mean_ms "NoSuchUser$(openssl rand -hex 3)" 6)
clear_store
echo "  wrong password, stash exists:     ${REAL_MS}ms"
echo "  wrong password, no such stash:    ${FAKE_MS}ms"

# Within 2x of each other. Generous on purpose: this is a shared dev machine
# and the point is that the 8x tell is gone, not that the clock is perfect.
[ "$REAL_MS" -gt 0 ] && [ "$FAKE_MS" -gt 0 ] \
  && [ "$((REAL_MS * 100 / FAKE_MS))" -lt 200 ] && [ "$((FAKE_MS * 100 / REAL_MS))" -lt 200 ]
check "an existing stash and an unknown name take the same time to refuse" $?

# --- the limit ------------------------------------------------------------
clear_store
LOCKED_AT=0
for i in $(seq 1 14); do
  OUT=$(attempt "$PROBE" "$WRONG")
  case "$OUT" in
    *wait=*) [ "$LOCKED_AT" = "0" ] && LOCKED_AT=$i ;;
  esac
done
echo "  locked out on attempt ${LOCKED_AT}"
[ "$LOCKED_AT" = "11" ]; check "the 11th failure in the window is refused (limit is 10)" $?

# Refusing must be cheap, or the throttle is itself the denial of service:
# past the limit nothing is spawned and nothing sleeps.
LOCKED_MS=$(printf '%.0f' "$(echo "$(attempt "$PROBE" "$WRONG" | cut -d'|' -f2) * 1000" | bc -l)")
echo "  a refused attempt costs ${LOCKED_MS}ms against ${REAL_MS}ms for one that runs 7z"
[ "$LOCKED_MS" -lt "$((REAL_MS / 2))" ]
check "a throttled attempt is refused without spawning the decrypt" $?

# The lock has to hold against the real password too, or it is bypassable by
# the one party it is meant to stop.
printf '%s' "$(attempt "$PROBE" "$PASS")" | grep -q 'wait='
check "the correct password is also refused while locked out" $?

# ...and it must say how long. A lock with no stated end, on an app with no
# password reset, is indistinguishable from a broken stash.
printf '%s' "$(attempt "$PROBE" "$WRONG")" | grep -qE 'wait=[0-9]+'
check "the page is told how many minutes to wait" $?

# --- the lock lets go on its own -----------------------------------------
# Nothing here may be permanent: the password is the only key there is, so a
# lockout that outlived its window would mean no way back in, ever.
docker compose exec -T -u www-data app php -r '
require_once "/app/src/LoginThrottle.php";
printf("window is %d seconds (%d minutes)\n",
    MyStash\LoginThrottle::WINDOW_SECONDS, MyStash\LoginThrottle::WINDOW_SECONDS / 60);
' | sed 's/^/  /'
docker compose exec -T -u www-data app php -r '
require_once "/app/src/LoginThrottle.php";
exit(MyStash\LoginThrottle::WINDOW_SECONDS > 0 && MyStash\LoginThrottle::WINDOW_SECONDS <= 1800 ? 0 : 1);
'
check "the lockout window is bounded, so it always expires without intervention" $?

# Attacking a locked name must not extend the lock — otherwise anyone who knows
# the username can keep the owner out indefinitely just by keeping at it.
FIRST=$(curl -s -o /dev/null -w '%{redirect_url}' -d "username=${PROBE}&password=${WRONG}" \
  http://localhost:8090/login.php | sed 's/.*wait=//')
for _ in $(seq 1 5); do attempt "$PROBE" "$WRONG" >/dev/null; done
AFTER=$(curl -s -o /dev/null -w '%{redirect_url}' -d "username=${PROBE}&password=${WRONG}" \
  http://localhost:8090/login.php | sed 's/.*wait=//')
echo "  minutes remaining: ${FIRST} before five more attempts, ${AFTER} after"
[ "${AFTER:-99}" -le "${FIRST:-0}" ]
check "hammering a locked name does not extend the lockout" $?

# --- a successful login forgives --------------------------------------
clear_store
for _ in $(seq 1 5); do attempt "$PROBE" "$WRONG" >/dev/null; done
attempt "$PROBE" "$PASS" >/dev/null
for _ in $(seq 1 8); do attempt "$PROBE" "$WRONG" >/dev/null; done
printf '%s' "$(attempt "$PROBE" "$WRONG")" | grep -qv 'wait='
check "a successful login clears the failures that came before it" $?
