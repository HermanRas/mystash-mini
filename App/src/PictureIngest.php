<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/Crypto7z.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/VideoEncoder.php';
require_once __DIR__ . '/VideoCreators.php';
require_once __DIR__ . '/VideoPreview.php';

/**
 * Picture upload (MyStashMini). A picture is a wall entry like a video, with
 * `kind: picture`, stored in the same Video{ID}/ directory so that delete,
 * re-key and playlists treat it exactly as they treat a video:
 *
 *   {ID}.img.enc          the original file, byte for byte
 *   {ID}.jpg.preview.enc  a JPEG scaled to fit 1280px, the wall tile
 *   {ID}.json.enc         metadata
 *
 * The original is kept as uploaded (an animated GIF stays animated) but only
 * ever served with one of the content types in TYPES, decided from the file's
 * own bytes, never from the browser's claim. SVG is deliberately absent: it is
 * a document that can carry script, not a picture.
 */
final class PictureIngest
{
    /** Detected MIME type => stored extension. */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/avif' => 'avif',
    ];

    /** Stored extension => the Content-Type media.php serves it with. */
    public const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
    ];

    public function __construct(
        private VideoEncoder $encoder = new VideoEncoder(),
        private Crypto7z $crypto = new Crypto7z(),
    ) {
    }

    /** The picture's stored extension, or null when the file is not a picture we accept. */
    public static function detect(string $path): ?string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return self::TYPES[$mime] ?? null;
    }

    /**
     * @return array the new entry's index summary
     */
    public function ingest(
        string $user,
        string $password,
        string $uploadedTmpPath,
        string $originalFilename,
        array $index,
    ): array {
        $ext = self::detect($uploadedTmpPath);
        if ($ext === null) {
            throw new \RuntimeException('That file is not a picture this app can store.');
        }

        $id = Datastore::nextVideoId($index);
        $workDir = Datastore::tmpfsWorkDir('ingest');
        $claimedDir = null;

        try {
            $originalPath = "{$workDir}/{$id}.{$ext}";
            if (!move_uploaded_file($uploadedTmpPath, $originalPath) && !copy($uploadedTmpPath, $originalPath)) {
                throw new \RuntimeException('Failed to stage uploaded file');
            }

            $thumbPath = "{$workDir}/preview.jpg";
            if (!$this->encoder->convertImage($originalPath, $thumbPath, VideoPreview::MAX_EDGE) || !is_file($thumbPath)) {
                throw new \RuntimeException('That picture could not be read.');
            }

            [$width, $height] = @getimagesize($originalPath) ?: [null, null];

            // Same atomic claim as VideoIngest: mkdir decides who owns the id.
            $dir = Datastore::videoDir($user, $id);
            while (!@mkdir($dir, 0700, true)) {
                if (!is_dir($dir)) {
                    throw new \RuntimeException("Could not create {$dir}");
                }
                $id = (string) ((int) $id + 1);
                $dir = Datastore::videoDir($user, $id);
            }
            $claimedDir = $dir;

            // The archive's inner name must carry the new id if the claim moved it.
            $finalOriginal = "{$workDir}/{$id}.{$ext}";
            if ($finalOriginal !== $originalPath) {
                rename($originalPath, $finalOriginal);
            }

            $metadata = [
                'id' => $id,
                'kind' => 'picture',
                'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                'description' => '',
                'creators' => [VideoCreators::DEFAULT_CREATOR],
                'length_seconds' => 0,
                'views' => 0,
                'format' => $ext,
                'width' => $width,
                'height' => $height,
                'uploaded_at' => date('c'),
                'categories' => [],
                'preview_capture_seconds' => null,
            ];
            $metadataPath = "{$workDir}/{$id}.json";
            file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->crypto->encrypt($finalOriginal, "{$dir}/{$id}.img.enc", $password);
            $this->crypto->encrypt($thumbPath, "{$dir}/{$id}.jpg.preview.enc", $password);
            $this->crypto->encrypt($metadataPath, "{$dir}/{$id}.json.enc", $password);

            $claimedDir = null;

            return [
                'id' => $id,
                'kind' => 'picture',
                'title' => $metadata['title'],
                'description' => '',
                'creators' => $metadata['creators'],
                'length_seconds' => 0,
                'views' => 0,
                'format' => $ext,
                'width' => $width,
                'height' => $height,
                'uploaded_at' => $metadata['uploaded_at'],
                'categories' => [],
                'quality' => null,
                'tile_gradient' => ['#3a3a3a', '#161616'],
            ];
        } finally {
            Datastore::wipe($workDir);
            if ($claimedDir !== null) {
                Datastore::wipe($claimedDir);
            }
        }
    }

    public static function isPicture(array $entry): bool
    {
        return ($entry['kind'] ?? 'video') === 'picture';
    }
}
