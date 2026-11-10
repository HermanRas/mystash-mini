<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/StashCrypto.php';
require_once __DIR__ . '/Datastore.php';
require_once __DIR__ . '/VideoEncoder.php';
require_once __DIR__ . '/VideoCreators.php';

/**
 * Creator records, stored the same way videos are: an encrypted per-creator
 * file holding the authoritative details, plus an encrypted profile picture.
 *
 *   App/Data/{user}/creators/Creator{ID}/{ID}.json.epc
 *   App/Data/{user}/creators/Creator{ID}/{ID}.profile.png.epc
 *
 * The index keeps a denormalized copy of each creator (name, age, gender,
 * id) so the wall and its filter panel can render without decrypting
 * every creator archive on each page load — exactly the arrangement used for
 * category names. The per-creator file is the source of truth.
 *
 * Creators are still keyed by display name in the index, because that is what a
 * video's `creator` field references; the ID only addresses the files.
 */
final class CreatorStore
{
    public function __construct(
        private Datastore $datastore = new Datastore(),
        private StashCrypto $crypto = new StashCrypto(),
        private VideoEncoder $encoder = new VideoEncoder(),
    ) {
    }

    /**
     * Full details for a creator, read from their own archive and falling back
     * to the index copy for records written before creator files existed.
     */
    public function load(string $user, string $key, array $index, string $name): ?array
    {
        $summary = $index['creators'][$name] ?? null;
        if ($summary === null) {
            return null;
        }

        $id = (string) ($summary['id'] ?? '');
        $record = $id !== '' ? $this->datastore->loadCreatorMetadata($user, $key, $id) : null;

        return $record ?? $summary;
    }

    /**
     * Writes a creator record, assigning an ID on first save, and mirrors the
     * summary into $index (by reference). Returns the saved record.
     *
     * $originalName renames: the index key moves and every video referencing
     * the old name is repointed, but the ID and its directory stay put.
     */
    public function save(string $user, string $key, array &$index, array $record, string $originalName = ''): array
    {
        $name = $record['name'];
        $existing = $index['creators'][$originalName !== '' ? $originalName : $name] ?? [];

        $id = (string) ($existing['id'] ?? '');
        if ($id === '') {
            $id = Datastore::nextCreatorId($index);
        }

        $record = [
            ...$record,
            'id' => $id,
            'created_at' => $existing['created_at'] ?? date('c'),
            'updated_at' => date('c'),
        ];

        if ($originalName !== '' && $originalName !== $name) {
            unset($index['creators'][$originalName]);
            VideoCreators::rename($index, $originalName, $name);
        }

        $index['creators'][$name] = [
            'id' => $id,
            'name' => $name,
            'age' => $record['age'],
            'gender' => $record['gender'],
            'bio' => $record['bio'],
        ];

        $this->datastore->saveCreatorMetadata($user, $key, $id, $record);

        return $record;
    }

    /**
     * Stores an uploaded profile picture as the encrypted
     * Creator{ID}/{ID}.profile.png.epc. Whatever was uploaded is normalised to
     * PNG first, so the stored name always describes the actual bytes.
     */
    public function saveProfileImage(string $user, string $key, string $id, string $uploadedTmpPath): bool
    {
        $workDir = Datastore::tmpfsWorkDir('creator');

        try {
            $staged = "{$workDir}/upload";
            if (!move_uploaded_file($uploadedTmpPath, $staged) && !copy($uploadedTmpPath, $staged)) {
                return false;
            }

            $pngPath = "{$workDir}/{$id}.profile.png";
            if (!$this->encoder->convertImage($staged, $pngPath)) {
                return false;
            }

            $dir = Datastore::creatorDir($user, $id);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }

            return $this->crypto->encrypt($pngPath, "{$dir}/{$id}.profile.png.epc", $key);
        } finally {
            Datastore::wipe($workDir);
        }
    }

    public static function profileImagePath(string $user, string $id): string
    {
        return Datastore::creatorDir($user, $id) . "/{$id}.profile.png.epc";
    }

    public static function hasProfileImage(string $user, string $id): bool
    {
        return $id !== '' && file_exists(self::profileImagePath($user, $id));
    }

    /**
     * Removes a creator: their archive directory, their index entry, and the
     * reference from every video they were on. A video left with no creators
     * falls back to `default`.
     */
    public function delete(string $user, array &$index, string $name): void
    {
        $id = (string) ($index['creators'][$name]['id'] ?? '');

        unset($index['creators'][$name]);
        VideoCreators::remove($index, $name);

        if ($id !== '') {
            Datastore::wipe(Datastore::creatorDir($user, $id));
        }
    }
}
