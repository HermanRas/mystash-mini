<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/VideoCreators.php';

/**
 * Category assignments for a video: a reference to a global category (by name)
 * plus the timestamp it points at. The same category may be assigned more than
 * once at different timestamps.
 *
 * The assignments live in the per-video metadata (Video{ID}/{ID}.json.epc);
 * the index keeps only the de-duplicated names so the wall can render and
 * filter without decrypting every video's metadata.
 */
final class VideoCategories
{
    public function __construct(private Datastore $datastore = new Datastore())
    {
    }

    /**
     * Assignments for a video, sorted by timestamp.
     */
    public function load(string $user, string $key, string $id): array
    {
        $metadata = $this->datastore->loadVideoMetadata($user, $key, $id);
        $assignments = $metadata['categories'] ?? [];

        usort($assignments, static fn($a, $b) => $a['timestamp_seconds'] <=> $b['timestamp_seconds']);

        return $assignments;
    }

    /**
     * Writes assignments to the video's metadata and mirrors the unique names
     * into the given index (by reference) so both stay consistent.
     */
    public function save(string $user, string $key, string $id, array $assignments, array &$index): bool
    {
        usort($assignments, static fn($a, $b) => $a['timestamp_seconds'] <=> $b['timestamp_seconds']);

        $metadata = $this->datastore->loadVideoMetadata($user, $key, $id)
            ?? $this->metadataFromIndex($index, $id);

        $metadata['categories'] = $assignments;

        $names = Datastore::categoryNames($assignments);
        foreach ($index['videos'] as &$video) {
            if ($video['id'] === $id) {
                $video['categories'] = $names;
                break;
            }
        }
        unset($video);

        return $this->datastore->saveVideoMetadata($user, $key, $id, $metadata);
    }

    /**
     * Seeds per-video metadata from the index entry, for videos whose metadata
     * archive doesn't exist yet (e.g. entries created before metadata files
     * were written, or demo data).
     */
    private function metadataFromIndex(array $index, string $id): array
    {
        foreach ($index['videos'] as $video) {
            if ($video['id'] === $id) {
                return [
                    'id' => $video['id'],
                    'title' => $video['title'],
                    'description' => $video['description'] ?? '',
                    'creators' => VideoCreators::of($video),
                    'length_seconds' => $video['length_seconds'],
                    'views' => $video['views'],
                    'format' => $video['format'],
                    'codec' => $video['codec'],
                    'not_converted' => $video['not_converted'],
                    'uploaded_at' => null,
                    'categories' => [],
                    'preview_capture_seconds' => null,
                ];
            }
        }

        return ['id' => $id, 'categories' => []];
    }

    public static function formatTimestamp(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Parses "hh:mm:ss" (or "mm:ss", or a plain second count) into seconds.
     */
    public static function parseTimestamp(string $value): int
    {
        $parts = array_reverse(explode(':', trim($value)));
        $seconds = 0;
        $multiplier = 1;

        foreach (array_slice($parts, 0, 3) as $part) {
            $seconds += ((int) $part) * $multiplier;
            $multiplier *= 60;
        }

        return max(0, $seconds);
    }
}
