#!/bin/sh
# End-to-end checks for the v2 format (Docs/v2_migration.md), over HTTP, against
# the running app: register, login (and its timing), upload and stream back,
# a password change, a stash delete, and a v1 stash upgrading on sign-in.
#
# It runs INSIDE the app container, talking to nginx on localhost:8080 there,
# so it works whatever the host's port 8080 is doing:
#
#   docker compose up -d
#   dev/run_v2_check.sh
#
# Every stash it makes is a throwaway with a random name, removed at the end.
if [ -z "${MYSTASH_INSIDE:-}" ]; then
  cd "$(dirname "$0")/.." || exit 1
  exec docker compose exec -T -u www-data -e MYSTASH_INSIDE=1 app sh -s < "dev/$(basename "$0")"
fi

B=http://localhost:8080
fails=0
check() { if [ "$2" = 0 ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }
loc() { curl -s -o /dev/null -w '%{redirect_url}' "$@"; }

U=E2e$(head -c3 /dev/urandom | od -An -tx1 | tr -d ' \n')
P='e2e-password-with-$pecial-chars-!@#%^&*()aa'
P2='e2e-second-password-bbbbbbbbbbbbbbbbbbbbbbbb'
J=/tmp/$U.jar

echo "--- register / login ---"
R=$(loc -c $J --data-urlencode "username=$U" --data-urlencode "password=$P" --data-urlencode "confirm_password=$P" $B/register.php)
case "$R" in *wall.php) true;; *) false;; esac; check "register lands on the wall ($R)" $?
[ -f /app/Data/$U/videos/$U.json.epc ]; check "registration wrote the v2 index (.epc)" $?
[ -z "$(find /app/Data/$U -name '*.enc')" ]; check "no v1 .enc anywhere" $?
grep -rq "$P" /dev/shm/mystash-sessions/ 2>/dev/null; [ $? -ne 0 ]; check "the password is not in any session file" $?
grep -rq "epck1." /dev/shm/mystash-sessions/; check "the session holds a key token instead" $?
[ "$(curl -s -b $J -o /dev/null -w '%{http_code}' $B/wall.php)" = 200 ]; check "the wall opens in that session" $?

rm -f $J
R=$(loc -c $J --data-urlencode "username=$U" --data-urlencode "password=wrong-password-xxxxxxxxxxxxx" $B/login.php)
case "$R" in *error=1*) true;; *) false;; esac; check "a wrong password is refused" $?
R=$(loc -c $J --data-urlencode "username=$U" --data-urlencode "password=$P" $B/login.php)
case "$R" in *wall.php) true;; *) false;; esac; check "the right password signs in ($R)" $?

echo "--- timing: no username oracle ---"
t() { curl -s -o /dev/null -w '%{time_total}' --data-urlencode "username=$1" --data-urlencode "password=$2" $B/login.php; }
A=$(t "$U" wrong-password-yyyyyyyyyyyyyyy); N=$(t "Nobody$U" wrong-password-yyyyyyyyyyyyyyy)
echo "  wrong password on a real stash: ${A}s, on no stash: ${N}s"
awk -v a=$A -v n=$N 'BEGIN{d=a-n; if(d<0)d=-d; exit !(d < 0.25*(a>n?a:n))}'; check "the two take about the same time" $?

echo "--- upload, then fetch the video back ---"
UP=$(curl -s -b $J -o /dev/null -w '%{http_code}' -F "video=@/app/tests/fixture.mp4" $B/upload.php)
echo "  upload answered HTTP $UP"
[ -f /app/Data/$U/videos/Video1/1.mp4.epc ]; check "the upload wrote 1.mp4.epc" $?
head -c 5 /app/Data/$U/videos/Video1/1.mp4.epc | grep -q MSEPC; check "...in the MSEPC format" $?
curl -s -b $J -o /tmp/$U.mp4 "$B/media.php?id=1&type=video&download=1"
cmp -s /tmp/$U.mp4 /app/tests/fixture.mp4; check "media.php streams back exactly the uploaded bytes" $?
RANGE=$(curl -s -b $J -H 'Range: bytes=100-199' -o /tmp/$U.part -w '%{http_code}' "$B/media.php?id=1&type=video")
[ "$RANGE" = 206 ] && [ "$(stat -c%s /tmp/$U.part)" = 100 ]; check "range requests still work (HTTP $RANGE)" $?
for i in $(seq 1 60); do [ -f /app/Data/$U/videos/Video1/1.mp4.preview.epc ] && break; sleep 1; done
[ -f /app/Data/$U/videos/Video1/1.mp4.preview.epc ]; check "the background preview job wrote its .epc" $?
[ "$(curl -s -b $J -o /dev/null -w '%{http_code}' "$B/media.php?id=1&type=thumb")" = 200 ]; check "the thumbnail serves" $?

echo "--- password change (rekey job) ---"
R=$(loc -b $J --data-urlencode "current_password=not-it-xxxxxxxxxxxxxxxxxxx" --data-urlencode "new_password=$P2" --data-urlencode "confirm_password=$P2" $B/password_change.php)
case "$R" in *error=wrong*) true;; *) false;; esac; check "a wrong current password is refused" $?
R=$(loc -b $J --data-urlencode "current_password=$P" --data-urlencode "new_password=$P2" --data-urlencode "confirm_password=$P2" $B/password_change.php)
case "$R" in *rekeying=1*) true;; *) false;; esac; check "the right one starts the re-key ($R)" $?
for i in $(seq 1 120); do S=$(curl -s -b $J "$B/job_status.php?kind=rekey"); case "$S" in *'"state":"done"'*|*'"state":"failed"'*) break;; esac; sleep 1; done
echo "  $S"
case "$S" in *'"state":"done"'*) true;; *) false;; esac; check "the re-key finishes" $?
grep -rq "$P2" /dev/shm/mystash-jobs/ 2>/dev/null; [ $? -ne 0 ]; check "no password left in the job files" $?
rm -f $J
R=$(loc -c $J --data-urlencode "username=$U" --data-urlencode "password=$P" $B/login.php)
case "$R" in *error=1*) true;; *) false;; esac; check "the old password no longer opens it" $?
R=$(loc -c $J --data-urlencode "username=$U" --data-urlencode "password=$P2" $B/login.php)
case "$R" in *wall.php) true;; *) false;; esac; check "the new password does" $?
curl -s -b $J -o /tmp/$U.mp4 "$B/media.php?id=1&type=video&download=1"
cmp -s /tmp/$U.mp4 /app/tests/fixture.mp4; check "the video survived the re-key byte for byte" $?

echo "--- delete stash ---"
R=$(loc -b $J --data-urlencode "confirm_name=$U" --data-urlencode "current_password=$P2" $B/stash_delete.php)
echo "  delete answered: $R"
[ ! -d /app/Data/$U ]; check "the stash is deleted with the right password" $?

echo "--- a v1 stash upgrades on sign-in ---"
V=V1e2e$(head -c3 /dev/urandom | od -An -tx1 | tr -d ' \n')
php -r '
require_once "/app/src/Crypto7z.php"; require_once "/app/src/Datastore.php";
$u = $argv[1]; $p = $argv[2]; $c = new MyStash\Crypto7z();
$d = MyStash\Datastore::userDir($u); $w = "/dev/shm/v1e2e-" . $u; @mkdir($w, 0700, true);
@mkdir("$d/videos/Video1", 0700, true); @mkdir("$d/creators/Creator1", 0700, true);
file_put_contents("$w/$u.json", json_encode(["categories" => ["Not Converted" => "#cc4444"], "creators" => ["default" => ["id" => "1", "name" => "default"]], "videos" => [["id" => "1", "title" => "Old one", "length_seconds" => 20, "views" => 0, "format" => "mp4", "codec" => "h264", "height" => 360, "uploaded_at" => date("c"), "categories" => [], "creators" => ["default"]]]]));
$c->encrypt("$w/$u.json", "$d/videos/$u.json.enc", $p);
file_put_contents("$w/1.json", json_encode(["id" => "1", "title" => "Old one", "categories" => []]));
$c->encrypt("$w/1.json", "$d/videos/Video1/1.json.enc", $p);
copy("/app/tests/fixture.mp4", "$w/1.mp4"); $c->encrypt("$w/1.mp4", "$d/videos/Video1/1.mp4.enc", $p);
file_put_contents("$w/1.json", json_encode(["id" => "1", "name" => "default"]));
$c->encrypt("$w/1.json", "$d/creators/Creator1/1.json.enc", $p);
array_map("unlink", glob("$w/*")); rmdir($w);
' "$V" "$P"
rm -f $J
R=$(loc -c $J --data-urlencode "username=$V" --data-urlencode "password=wrong-password-zzzzzzzzzzzzz" $B/login.php)
case "$R" in *error=1*) true;; *) false;; esac; check "a wrong password on a v1 stash is refused, not upgraded" $?
R=$(loc -c $J --data-urlencode "username=$V" --data-urlencode "password=$P" $B/login.php)
case "$R" in *upgrade.php) true;; *) false;; esac; check "the right password goes to the upgrade page ($R)" $?
[ "$(curl -s -b $J -o /dev/null -w '%{http_code}' $B/wall.php)" = 302 ]; check "the wall is not reachable during the upgrade" $?
curl -s -b $J $B/upgrade.php | grep -q 'Upgrading your stash\|Sign in to your upgraded'; check "the upgrade page renders" $?
for i in $(seq 1 120); do S=$(curl -s -b $J "$B/upgrade.php?status=1"); case "$S" in *'"state":"done"'*|*'"state":"failed"'*) break;; esac; sleep 1; done
echo "  $S"
case "$S" in *'"state":"done"'*) true;; *) false;; esac; check "the upgrade job finishes" $?
[ -z "$(find /app/Data/$V -name '*.enc')" ] && [ -f /app/Data/$V/videos/$V.json.epc ]; check "the stash is all .epc now" $?
R=$(loc -c $J --data-urlencode "username=$V" --data-urlencode "password=$P" $B/login.php)
case "$R" in *wall.php) true;; *) false;; esac; check "signing in again opens the upgraded stash ($R)" $?
curl -s -b $J "$B/wall.php?type%5B%5D=" | grep -q "Old one"; check "the wall shows the v1 video" $?
curl -s -b $J -o /tmp/$V.mp4 "$B/media.php?id=1&type=video&download=1"
cmp -s /tmp/$V.mp4 /app/tests/fixture.mp4; check "the v1 video plays back byte for byte" $?
rm -rf /app/Data/$V /tmp/$U.* /tmp/$V.*

echo; [ $fails = 0 ] && echo "ALL E2E CHECKS PASSED" || echo "$fails E2E CHECK(S) FAILED"
exit $fails
