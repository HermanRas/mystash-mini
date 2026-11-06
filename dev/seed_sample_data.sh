#!/usr/bin/env bash
# Builds the demo stash the README screenshots are taken from.
#
# Everything here goes through the real HTTP endpoints — register, upload,
# video_save, creator_save, category_save, playlist_save — so the result is a
# stash the app itself produced, not one written behind its back. That matters
# for a README: the screenshots then show what a user would actually get.
#
# The assignment of videos to creators and categories is fixed rather than
# random. It reads as random, and it is reproducible, so re-running this after
# a UI change gives a wall that can be compared with the last screenshots
# instead of a different wall every time.
#
# Usage: dev/seed_sample_data.sh [source-dir] [user] [password]
set -uo pipefail
cd "$(dirname "$0")/.."

SRC="${1:-${MYSTASH_SAMPLE_DIR:-dev/sample}}"
USER_NAME="${2:-TestUser}"
PASSWORD="${3:-DS89HONPtufGDncNUoGfshCg}"
BASE="http://localhost:8090"
JAR="$(mktemp -d)/cookies"

[ -d "$SRC/clips" ] || { echo "no clips at $SRC/clips — run dev/make_sample_clips.sh first"; exit 1; }

post() { curl -s -o /dev/null -b "$JAR" "$@"; }

echo "== register $USER_NAME =="
curl -s -o /dev/null -c "$JAR" \
  -d "username=${USER_NAME}&password=${PASSWORD}&confirm_password=${PASSWORD}" \
  "$BASE/register.php"
curl -s -o /dev/null -c "$JAR" -b "$JAR" \
  -d "username=${USER_NAME}&password=${PASSWORD}" "$BASE/login.php"
curl -s -b "$JAR" "$BASE/wall.php" | grep -q 'video-grid' \
  || { echo "could not log in — does the stash already exist?"; exit 1; }

echo "== categories =="
while IFS='|' read -r name colour; do
  [ -z "$name" ] && continue
  post -d "name=${name}&color=%23${colour}" "$BASE/category_save.php"
  echo "  $name"
done <<'CATS'
NEWS|4a90d9
FAMILY|5cb85c
SPORT|e8703a
CATS|b06fd6
CATS

echo "== creators =="
add_creator() { # name age gender picture bio
  # The picture is optional: the clips are generated, but the three portraits
  # are not in the repository, so a checkout without them still seeds — with
  # creators that have no avatar rather than with no creators.
  local args=(-F "original_name=" -F "name=$1" -F "age=$2" -F "gender=$3" -F "bio=$5")
  if [ -f "${SRC}/$4" ]; then
    args+=(-F "profile=@${SRC}/$4")
  else
    echo "  (no picture at ${SRC}/$4)"
  fi
  post "${args[@]}" "$BASE/creator_save.php"
  echo "  $1"
}
add_creator "Marco Deniz" 31 "Male" "Creator1.jpg" \
  "Shoots the city at street level, mostly at dusk and mostly handheld. Came to video from stills and still frames everything like a photograph — long lenses, available light, no rigs. Records the audio separately and almost never uses it."
add_creator "Robin Vance" 19 "Female" "Creator2.jpg" \
  "Portrait work, close and unhurried. Everything is filmed at home against whatever window happens to be open, which is why half of it is gold and the other half is overcast. Posts in bursts of four or five and then disappears for a month."
add_creator "Elena Ward" 28 "Female" "Creator3.jpg" \
  "Explains things for a living: short documentary pieces, one idea each, script written before a camera is touched. Keeps a tablet in frame as a deliberate tell that the piece is prepared rather than improvised."

echo "== videos =="
upload() { # file title description creators... (pipe-separated) views cat1@ts cat2@ts
  local file="$1" title="$2" desc="$3" creators="$4" views="$5" tags="$6" preview="${7:-}"
  local args=(-F "video=@${SRC}/${file}")
  [ -n "$preview" ] && [ -f "${SRC}/${preview}" ] && args+=(-F "preview_image=@${SRC}/${preview}")
  curl -s -o /dev/null -b "$JAR" "${args[@]}" "$BASE/upload.php"

  ID=$(curl -s -b "$JAR" "$BASE/wall.php" \
        | grep -o 'video\.php?id=[0-9]*' | sed 's/.*=//' | sort -n | tail -1)

  local cargs=(-d "id=${ID}" --data-urlencode "title=${title}" --data-urlencode "description=${desc}")
  IFS='|' read -ra people <<< "$creators"
  for p in "${people[@]}"; do cargs+=(--data-urlencode "creators[]=${p}"); done
  post "${cargs[@]}" "$BASE/video_save.php"

  IFS='|' read -ra tagList <<< "$tags"
  for t in "${tagList[@]}"; do
    post -d "id=${ID}" --data-urlencode "name=${t%@*}" -d "timestamp=${t#*@}" \
      "$BASE/video_category_add.php"
  done

  docker compose exec -T -u www-data app \
    php /app/bin/set_views.php "$USER_NAME" "$PASSWORD" "$ID" "$views" >/dev/null
  echo "  [$ID] $title"
}

upload clips/01-harbour-road.mp4  "Harbour Road at Closing Time" \
  "The last twenty minutes of light along the harbour road, shot handheld from the far pavement." \
  "Marco Deniz" 128 "NEWS@00:00:04"
upload clips/02-rain-window.mp4  "Four Minutes of Rain" \
  "Filmed through the kitchen window during the first real rain of the season. No audio worth keeping." \
  "Robin Vance" 47 "FAMILY@00:00:02"
upload clips/03-kitchen-table.mp4    "Kitchen Table, Sunday" \
  "Everyone talking over everyone. Left in as it was recorded." \
  "Robin Vance|Marco Deniz" 233 "FAMILY@00:00:01|NEWS@00:00:03"
upload clips/04-long-way-around.mp4     "The Long Way Around" \
  "Shot in 4K because the light deserved it, then watched once and filed." \
  "Elena Ward" 12 "SPORT@00:00:02"
upload clips/05-lenses-lie.mp4  "Five Minutes on Why Lenses Lie" \
  "A short piece on focal length and what it does to a face. Written first, filmed second." \
  "Elena Ward" 512 "NEWS@00:00:06"
upload clips/06-milo-carrier.mp4  "Milo Refuses the Carrier" \
  "Third attempt. The carrier won eventually." \
  "Robin Vance" 891 "CATS@00:00:03|FAMILY@00:00:00"
upload clips/07-sunday-league.mp4    "Sunday League, Second Half" \
  "The half where it started raining and nobody left." \
  "Marco Deniz" 64 "SPORT@00:00:02"
upload clips/08-two-cats.mp4     "Two Cats, One Windowsill" \
  "A territorial dispute conducted entirely without movement." \
  "Robin Vance|Elena Ward" 1204 "CATS@00:00:01" "Custom Preview.jpg"
upload clips/09-borrowed-kitchen.mp4  "Notes From a Borrowed Kitchen" \
  "Filmed while housesitting. The knives were better than mine." \
  "Elena Ward" 78 "FAMILY@00:00:05"
upload clips/10-night-buses.mp4  "Night Buses" \
  "Forty minutes at a stop, cut to the four that were interesting." \
  "Marco Deniz" 305 "NEWS@00:00:02"
upload clips/11-training-week-six.mp4    "Training, Week Six" \
  "Kept for the record rather than for anyone to watch." \
  "default" 9 "SPORT@00:00:03"
upload clips/12-cat-on-the-script.mp4     "The Cat Who Sits on the Script" \
  "Every take. Without exception." \
  "Elena Ward|Robin Vance" 655 "CATS@00:00:02|FAMILY@00:00:04"

echo "== playlists =="
make_playlist() { # name id...
  local name="$1"; shift
  post --data-urlencode "name=${name}" "$BASE/playlist_save.php"
  local pid
  pid=$(curl -s -b "$JAR" "$BASE/playlist.php" \
        | grep -o 'playlist\.php?id=[0-9]*' | sed 's/.*=//' | sort -n | tail -1)
  local args=(-d "id=${pid}" -H "X-Requested-With: fetch")
  for v in "$@"; do args+=(-d "videos[]=${v}"); done
  post "${args[@]}" "$BASE/playlist_videos.php"
  echo "  ${name} (${pid}): $*"
}
make_playlist "Cats, Obviously" 6 8 12
make_playlist "Watch Again" 1 5 10 3

echo
echo "seeded $(curl -s -b "$JAR" "$BASE/wall.php" | grep -c 'tile-title') videos for ${USER_NAME}"
