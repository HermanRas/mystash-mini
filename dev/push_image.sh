#!/usr/bin/env bash
# Builds the container here and pushes it to GHCR, without GitHub Actions.
#
# This exists for the times CI is the thing that is broken, or when you want a
# tag published from a working tree that is not committed yet. It runs the same
# verification the workflow does before pushing, for the same reason: what goes
# to the registry should be something that has been started and talked to, not
# just something that compiled.
#
# You need to be logged in first. A personal access token with write:packages:
#
#   echo "$GITHUB_TOKEN" | docker login ghcr.io -u HermanRas --password-stdin
#
# Usage: dev/push_image.sh [tag]        (default: latest, plus sha-<commit>)
set -uo pipefail
cd "$(dirname "$0")/.."

IMAGE="ghcr.io/hermanras/mystash-mini"
TAG="${1:-latest}"
PORT=8099                     # not 8080: whatever you have running stays running
PROJECT="mystashminipush"
LOCAL="mystash-mini-push:${TAG}"
fails=0

cleanup() {
  docker compose -p "$PROJECT" -f "$COMPOSE" down -v >/dev/null 2>&1
  rm -f "$COMPOSE"
  rmdir "$DATA" 2>/dev/null
  [ "$fails" = 0 ] || echo "nothing was pushed"
  exit "$fails"
}

DATA="$(mktemp -d)"
chmod 777 "$DATA"
COMPOSE="$(mktemp --suffix=.yml)"
cat > "$COMPOSE" <<YAML
services:
  app:
    image: ${LOCAL}
    ports: ["127.0.0.1:${PORT}:8080"]
    volumes: ["${DATA}:/app/Data"]
    shm_size: 2gb
YAML
trap cleanup EXIT

echo "== build =="
docker build -t "$LOCAL" ./App || { echo "FAIL: build"; fails=1; exit 1; }

echo "== start =="
# No code mount, deliberately: this is the image as a puller would receive it.
# A bind mount of ./App here would test this working tree, not the artefact.
docker compose -p "$PROJECT" -f "$COMPOSE" up -d >/dev/null || { fails=1; exit 1; }

for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/" || true)
  [ "$code" = 302 ] && break
  sleep 1
done

check() { # description expected actual
  if [ "$3" = "$2" ]; then echo "PASS: $1"; else
    echo "FAIL: $1 (got $3, wanted $2)"; fails=$((fails + 1)); fi
}

echo "== verify =="
# `/` rather than a static file: a 502 here is php-fpm dead behind a live
# nginx, which a request for a .html file cannot see.
check "a PHP request is answered" 302 "$code"
check "the login page is served" 200 \
  "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/login.html")"
check "the font is served from this host" 200 \
  "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/assets/fonts/carter-one-latin-400.woff2")"

if docker compose -p "$PROJECT" -f "$COMPOSE" exec -T app \
     sh -c 'pgrep -x nginx >/dev/null && pgrep -x php-fpm >/dev/null'; then
  echo "PASS: nginx and php-fpm are both running"
else
  echo "FAIL: nginx and php-fpm are not both running"; fails=$((fails + 1))
fi

echo "== smoke suite =="
docker compose -p "$PROJECT" -f "$COMPOSE" exec -T -u www-data app \
  php /app/tests/smoke_test.php | tail -1
[ "${PIPESTATUS[0]}" = 0 ] || fails=$((fails + 1))

[ "$fails" = 0 ] || exit 1

echo "== push =="
SHA="sha-$(git rev-parse HEAD)"
for t in "$TAG" "$SHA"; do
  docker tag "$LOCAL" "${IMAGE}:${t}"
  docker push "${IMAGE}:${t}" || { echo "FAIL: push ${t} — are you logged in to ghcr.io?"; fails=1; }
  docker rmi "${IMAGE}:${t}" >/dev/null 2>&1
done
[ "$fails" = 0 ] && echo "pushed ${IMAGE}:${TAG} and ${IMAGE}:${SHA}"
