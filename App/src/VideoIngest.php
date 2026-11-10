<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoQuality.php';
require_once __DIR__ . '/VideoCreators.php';
require_once __DIR__ . '/VideoPreview.php';

/**
 * Upload/ingestion pipeline (Docs/PLAN.md Phase 3, Docs/SPECIFICATIONS.md §2.3):
 * format-checks the upload, generates the preview image, encrypts the video,
 * image and metadata into the datastore layout, and returns the index summary
 * entry to append to the user's video index. The silent hover clip is not made
 * here — it is a background job (see VideoPreview::buildClip).
 */
final class VideoIngest
{
    public function __construct(
        private VideoEncoder $encoder = new VideoEncoder(),
        private StashCrypto $crypto = new StashCrypto(),
    ) {
    }

    /**
     * @param array $index current decrypted index, used only to pick the next ID
     * @param float|null $previewTimestamp user-chosen preview capture point in
     *        seconds; defaults to 15s (clamped to the video's duration)
     * @param string|null $previewImagePathIn a picture the user supplied to use
     *        as the preview instead of a frame from the video (3.9). Wins over
     *        $previewTimestamp when both are given — someone who attached an
     *        image meant it.
     * @return array the new video's index summary entry
     */
    public function ingest(
        string $user,
        string $key,
        string $uploadedTmpPath,
        string $originalFilename,
        array $index,
        ?float $previewTimestamp = null,
        ?string $previewImagePathIn = null,
    ): array {
        $id = Datastore::nextVideoId($index);
        $ext = self::safeExtension($originalFilename);

        $workDir = Datastore::tmpfsWorkDir('ingest');

        // Null until the claim below succeeds, and cleared again once the entry
        // is safely built: what the finally uses to tell "we own this directory
        // and the ingest did not finish" from "there is nothing to undo".
        $claimedDir = null;

        try {
            $originalPath = "{$workDir}/original.{$ext}";
            if (!move_uploaded_file($uploadedTmpPath, $originalPath)) {
                // Fall back to copy for non-HTTP-upload callers (e.g. CLI/tests).
                if (!copy($uploadedTmpPath, $originalPath)) {
                    throw new \RuntimeException('Failed to stage uploaded file');
                }
            }

            $codec = $this->encoder->videoCodec($originalPath);
            $notConverted = VideoQuality::isNotConverted($ext, $codec);

            $duration = $this->encoder->durationSeconds($originalPath) ?? 0.0;

            // Clamped, never trusted. upload.php casts preview_at straight from
            // the form to a float with no bounds of its own, so a timestamp
            // past the end of the video arrives here intact — and ffmpeg, asked
            // for a frame that does not exist, writes no file at all while
            // still exiting 0. The encrypt() below then died on the missing
            // file and took the whole upload down with it, after the video had
            // already been transferred in full (7.2.1).
            //
            // VideoPreview::fromTimestamp() refuses an out-of-range timestamp
            // outright, and is right to: the user is on the edit screen and can
            // simply be told. Here the video is already staged, so the right
            // answer is to move the frame, not to throw the video away.
            $defaultAt = min(15.0, max(0.0, $duration - 0.5));
            $previewAt = $previewTimestamp ?? $defaultAt;

            if (!is_finite($previewAt) || $previewAt < 0.0 || ($duration > 0.0 && $previewAt > $duration)) {
                $previewAt = $defaultAt;
            }

            $previewImagePath = "{$workDir}/preview.jpg";
            $customPreview = false;

            // A supplied picture is re-encoded to JPEG rather than stored as
            // it arrived, so the archive's name describes its contents and
            // nothing but ffmpeg's own output is ever served back (3.9). If it
            // turns out not to be an image, fall through to the frame grab —
            // refusing the whole upload over the thumbnail would be a poor
            // trade for a video that has already been transferred.
            if ($previewImagePathIn !== null) {
                $customPreview = $this->encoder->convertImage(
                    $previewImagePathIn,
                    $previewImagePath,
                    VideoPreview::MAX_EDGE,
                );
            }

            if (!$customPreview) {
                $this->encoder->extractFrame($originalPath, $previewImagePath, $previewAt);
            }

            // Belt and braces for what the clamp cannot foresee: a truncated
            // file, a duration ffprobe could not read, a timestamp with no
            // decodable frame behind it. Every video is required to have a
            // preview — media.php 404s without one and the wall tile shows a
            // broken image — so fall back to the very first frame, and only
            // then give up, with a message that says what actually happened.
            if (!is_file($previewImagePath)) {
                $this->encoder->extractFrame($originalPath, $previewImagePath, 0.0);
                $previewAt = 0.0;
            }

            if (!is_file($previewImagePath)) {
                throw new \RuntimeException('No frame could be read from this video.');
            }

            // No hover clip here. Building it decodes the entire video, which
            // on a long upload outlasted the reverse proxy and turned a
            // successful upload into a 504. The caller starts a `preview` job
            // for it once the entry is saved (VideoPreview::buildClip).

            // Both derived tags come from the file itself and are never
            // user-editable — see VideoQuality.
            $height = $this->encoder->videoHeight($originalPath);
            $quality = VideoQuality::tagForHeight($height);

            // Category assignments reference a global category by name and
            // carry the timestamp they point at (see VideoCategories).
            $assignments = $notConverted ? [['name' => 'Not Converted', 'timestamp_seconds' => 0]] : [];
            $categories = Datastore::categoryNames($assignments);

            // Claim the directory before anything is written into it.
            //
            // nextVideoId() reads the *index*, so two uploads that both start
            // before either has saved compute the same id — and the second
            // would then encrypt its files straight over the first's, losing
            // that video outright. mkdir() is the atomic test-and-set that
            // settles it: whoever creates the directory owns the id, and the
            // loser tries the next one. Single-threaded PHP made this
            // unreachable; nginx in front of php-fpm (7.5) does not.
            $videoDir = Datastore::videoDir($user, $id);

            while (!@mkdir($videoDir, 0700, true)) {
                if (!is_dir($videoDir)) {
                    throw new \RuntimeException("Could not create {$videoDir}");
                }

                $id = (string) ((int) $id + 1);
                $videoDir = Datastore::videoDir($user, $id);
            }

            // The loop only exits once mkdir() has succeeded, so from here the
            // directory is ours and every failure below has to take it back
            // down again — see the finally.
            $claimedDir = $videoDir;

            $metadata = [
                'id' => $id,
                'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                'description' => '',
                'creators' => [VideoCreators::DEFAULT_CREATOR],
                'length_seconds' => (int) round($duration),
                // To the millisecond, for Trim: length_seconds is rounded.
                'duration_ms' => (int) floor($duration * 1000),
                'views' => 0,
                'format' => $ext,
                'codec' => $codec,
                'height' => $height,
                'quality' => $quality,
                'not_converted' => $notConverted,
                'uploaded_at' => date('c'),
                'categories' => $assignments,
                // null when the picture came from the user: it no longer
                // corresponds to any point in the video.
                'preview_capture_seconds' => $customPreview ? null : $previewAt,
            ];
            $metadataPath = "{$workDir}/{$id}.json";
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->crypto->encrypt($originalPath, "{$videoDir}/{$id}.mp4.epc", $key);
            $this->crypto->encrypt($previewImagePath, "{$videoDir}/{$id}.jpg.preview.epc", $key);
            $this->crypto->encrypt($metadataPath, "{$videoDir}/{$id}.json.epc", $key);

            // Everything is on disk; the caller adds it to the index next.
            $claimedDir = null;

            return [
                'id' => $id,
                'title' => $metadata['title'],
                'description' => '',
                'creators' => $metadata['creators'],
                'length_seconds' => $metadata['length_seconds'],
                'duration_ms' => $metadata['duration_ms'],
                'views' => 0,
                'format' => $ext,
                'codec' => $codec,
                'height' => $height,
                'not_converted' => $notConverted,
                'uploaded_at' => $metadata['uploaded_at'],
                'categories' => $categories,
                'quality' => $quality,
                'tile_gradient' => $this->randomTileGradient(),
            ];
        } finally {
            Datastore::wipe($workDir);

            // A directory claimed but never completed is unreachable by
            // definition — the index is only written by the caller once this
            // returns — so anything left here is invisible to every screen in
            // the app, cannot be deleted from the UI, and still holds megabytes
            // of encrypted video while permanently owning an id the claim loop
            // then has to skip. Take it back down (7.2.1).
            if ($claimedDir !== null) {
                Datastore::wipe($claimedDir);
            }
        }
    }

    /**
     * The upload's file extension, reduced to something safe to put in a path.
     *
     * The name comes from the browser and is entirely attacker-controlled.
     * `pathinfo()` takes the basename first, so `..` and embedded slashes
     * cannot escape — but the result still lands in a filesystem path and in
     * the stored metadata, and an extension of 300 characters made the staged
     * filename longer than the 255 bytes the filesystem allows, which failed
     * the upload outright *after* the whole video had been transferred. Letters
     * and digits only, and short (7.2.1).
     */
    private static function safeExtension(string $originalFilename): string
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        return $ext !== '' ? substr($ext, 0, 12) : 'mp4';
    }

    private function randomTileGradient(): array
    {
        $palettes = [
            ['#3a3a3a', '#161616'],
            ['#4a3a2a', '#1a1410'],
            ['#2a3a3a', '#101a1a'],
            ['#3a2a3a', '#1a101a'],
            ['#3a3a2a', '#181810'],
            ['#2a2a3a', '#10101a'],
        ];

        return $palettes[array_rand($palettes)];
    }
}
