#!/bin/sh
# Checks tools/decode.php — the standalone way to decrypt .epc archives —
# against a stash the running app has just written (Docs/v2_migration.md).
#
# decode.php is run in a stock php:8.3-cli container, NOT the app image, with
# nothing but the script and the archives mounted: that is the point of it.
#
#   docker compose up -d
#   dev/run_decode_check.sh
cd "$(dirname "$0")/.." || exit 1

WORK=$(mktemp -d /tmp/mystash-decode-XXXXXX)
trap 'rm -rf "$WORK"; docker compose exec -T -u www-data app php -r "require_once \"/app/src/Datastore.php\"; MyStash\\Datastore::wipe(MyStash\\Datastore::userDir(\"DecodeProbe\"));"' EXIT

docker compose exec -T -u www-data app php -r '
require_once "/app/src/User.php"; require_once "/app/src/VideoIngest.php"; require_once "/app/src/Datastore.php";
$u = "DecodeProbe"; $p = "decode-me-\$ecret-with spaces & quotes\"'"'"'-aaaa";
MyStash\Datastore::wipe(MyStash\Datastore::userDir($u));
$k = (new MyStash\User())->create($u, $p);
$s = new MyStash\Datastore(); $i = $s->loadIndex($u, $k);
$e = (new MyStash\VideoIngest())->ingest($u, $k, "/app/tests/fixture.mp4", "clip.mp4", $i);
$i["videos"][] = $e; $s->saveIndex($u, $k, $i);' || exit 1

cp -r App/Data/DecodeProbe "$WORK/stash"
cp App/tests/fixture.mp4 "$WORK/fixture.mp4"

cat > "$WORK/run.sh" <<'INNER'
set -u
P='decode-me-$ecret-with spaces & quotes"'"'"'-aaaa'
D=/tool/decode.php; S=/data/stash; fails=0
check() { if [ "$2" = 0 ]; then echo "PASS: $1"; else echo "FAIL: $1"; fails=$((fails+1)); fi; }
cd /work
php -r 'echo "php ", PHP_VERSION, ", memory_limit ", ini_get("memory_limit"), ", sodium ", SODIUM_LIBRARY_VERSION, "\n";'

php $D -f $S/videos/Video1/1.mp4.epc -p "$P" -o /work/one >/dev/null 2>&1; check "-f decrypts one archive" $?
cmp -s /work/one/1.mp4 /data/fixture.mp4; check "...to the original video, byte for byte" $?

php $D -F $S/ -p "$P" -o /work/all > /work/all.log 2>&1; check "-F decrypts a whole stash" $?
[ "$(find /work/all -type f | wc -l)" = 5 ]; check "...all 5 files, folder structure kept" $?
php -r 'exit(is_array(json_decode(file_get_contents("/work/all/videos/DecodeProbe.json"), true)) ? 0 : 1);'; check "...the index is readable JSON" $?
cmp -s /work/all/videos/Video1/1.mp4 /data/fixture.mp4; check "...the video matches" $?
file /work/all/videos/Video1/1.preview.jpg | grep -q JPEG; check "...the thumbnail is a JPEG" $?

php $D -F $S/ -p 'wrong-password-xxxxxxxxxxxx' -o /work/wrong > /work/wrong.log 2>&1; [ $? = 1 ]; check "a wrong password fails (exit 1)" $?
grep -q "wrong password" /work/wrong.log; check "...and says why" $?
[ -z "$(find /work/wrong -type f 2>/dev/null)" ]; check "...and writes nothing" $?

cp $S/videos/Video1/1.mp4.epc /work/tampered.epc
printf '\x00' | dd of=/work/tampered.epc bs=1 seek=200000 conv=notrunc 2>/dev/null
php $D -f /work/tampered.epc -p "$P" -o /work/t > /work/t.log 2>&1; [ $? = 1 ]; check "a tampered archive is refused" $?
[ -z "$(find /work/t -type f 2>/dev/null)" ]; check "...with no partial file left" $?

head -c 100000 $S/videos/Video1/1.mp4.epc > /work/cut.epc
php $D -f /work/cut.epc -p "$P" -o /work/c > /work/c.log 2>&1; [ $? = 1 ] && grep -q "cut short\|damaged" /work/c.log; check "a truncated archive is refused" $?

php $D -f $S/videos/Video1/1.mp4.epc -p "$P" -o /work/one > /work/dup.log 2>&1; [ $? = 1 ] && grep -q "already exists" /work/dup.log; check "it will not overwrite without --force" $?
php $D -f $S/videos/Video1/1.mp4.epc -p "$P" -o /work/one --force >/dev/null 2>&1; check "...and does with --force" $?

printf '%s\n' "$P" | php $D -f $S/videos/Video1/1.json.epc -o /work/prompt >/dev/null 2>&1; check "with no -p it reads the password from the prompt" $?
[ -f /work/prompt/1.json ]; check "...and decrypts" $?

php $D -h >/dev/null 2>&1; check "-h prints help (exit 0)" $?
php $D -p x >/dev/null 2>&1; [ $? = 2 ]; check "neither -f nor -F is a usage error (exit 2)" $?
echo; [ $fails = 0 ] && echo "ALL DECODE CHECKS PASSED" || echo "$fails FAILED"; exit $fails
INNER

docker run --rm \
  -v "$PWD/tools:/tool:ro" -v "$WORK/run.sh:/tool-run.sh:ro" \
  -v "$WORK/stash:/data/stash:ro" -v "$WORK/fixture.mp4:/data/fixture.mp4:ro" \
  --tmpfs /work php:8.3-cli \
  sh -c 'apt-get -qq update >/dev/null 2>&1; apt-get -qq install -y file >/dev/null 2>&1; sh /tool-run.sh'
