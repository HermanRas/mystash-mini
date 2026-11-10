<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Crypto7z.php';
require_once __DIR__ . '/../src/StashCrypto.php';
require_once __DIR__ . '/../src/StashKey.php';
require_once __DIR__ . '/../src/MigrateV2.php';
require_once __DIR__ . '/../src/VideoEncoder.php';
require_once __DIR__ . '/../src/VideoQuality.php';
require_once __DIR__ . '/../src/User.php';
require_once __DIR__ . '/../src/VideoQuery.php';
require_once __DIR__ . '/../src/CreatorQuery.php';
require_once __DIR__ . '/../src/Rekey.php';
require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/LoginThrottle.php';
require_once __DIR__ . '/../src/VideoIngest.php';
require_once __DIR__ . '/../src/VideoPreview.php';
require_once __DIR__ . '/../src/Playlists.php';
require_once __DIR__ . '/../src/VideoStats.php';
require_once __DIR__ . '/../src/VideoTrim.php';
require_once __DIR__ . '/../src/VideoCategories.php';

use MyStash\CreatorQuery;
use MyStash\Datastore;
use MyStash\Rekey;
use MyStash\Crypto7z;
use MyStash\MigrateV2;
use MyStash\StashCrypto;
use MyStash\StashKey;
use MyStash\Jobs;
use MyStash\User;
use MyStash\VideoEncoder;
use MyStash\VideoIngest;
use MyStash\VideoPreview;
use MyStash\VideoCreators;
use MyStash\VideoQuality;
use MyStash\LoginThrottle;
use MyStash\Playlists;
use MyStash\VideoQuery;
use MyStash\VideoStats;
use MyStash\VideoTrim;
use MyStash\VideoCategories;

function step(string $label, bool $ok): void
{
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

// The suite needs one real video to encrypt, probe, convert and ingest. It
// used to require a file checked in under a user's stash directory, which meant
// the whole suite failed on a fresh clone — and stopped working the moment that
// stash was cleared. It is synthesised instead, in tmpfs, and thrown away with
// the rest of the work directory.
//
// 20 seconds and 640x360 on purpose: long enough for the 15s preview frame the
// suite asks for, and small enough that generating it costs about a second.
$fixture = '/dev/shm/mystash-smoke-fixture.mp4';

if (!file_exists($fixture)) {
    exec(sprintf(
        'ffmpeg -v error -y -f lavfi -i testsrc=size=640x360:rate=25:duration=20 '
        . '-f lavfi -i sine=frequency=440:duration=20 '
        . '-c:v libx264 -preset veryfast -c:a aac -pix_fmt yuv420p -shortest %s',
        escapeshellarg($fixture),
    ), $output, $status);

    if ($status !== 0 || !file_exists($fixture)) {
        fwrite(STDERR, "Could not synthesise the test fixture — is ffmpeg present?\n");
        exit(1);
    }
}

$workDir = sys_get_temp_dir() . '/mystash_smoke_' . uniqid();
mkdir($workDir, 0700, true);

$archive = $workDir . '/1.mp4.epc';
$extractDir = $workDir . '/extracted';
$password = 'smoke-test-password-' . bin2hex(random_bytes(4));

// --- StashKey: Argon2id, salted, carried as a token (Docs/v2_migration.md) ---
$key = StashKey::create($password);
$parsedKey = StashKey::parse($key);
step('a new key parses back into cost, salt and a 32-byte key',
    $parsedKey !== null && strlen($parsedKey['key']) === 32 && strlen($parsedKey['salt']) === 16
    && $parsedKey['ops'] === StashKey::OPSLIMIT && $parsedKey['mem'] === StashKey::MEMLIMIT);
step('the token does not contain the password', !str_contains($key, $password));
step('the same password matches its key', StashKey::matches($key, $password));
step('a different password does not', !StashKey::matches($key, $password . 'x'));
$sameAgain = StashKey::create($password);
step('the same password twice gives two different keys (fresh salt each time)',
    StashKey::parse($sameAgain)['key'] !== $parsedKey['key']);
step('a token that is not one is refused', StashKey::parse('not-a-token') === null);

$crypto = new StashCrypto();

step('encrypt fixture into an .epc archive', $crypto->encrypt($fixture, $archive, $key));
step('archive file exists', file_exists($archive));

$header = StashCrypto::readHeader($archive);
step('the archive header records the salt and cost the key was made with',
    $header !== null && $header['salt'] === $parsedKey['salt']
    && $header['ops'] === $parsedKey['ops'] && $header['mem'] === $parsedKey['mem']);
step('the header re-derives the same key from the password (what decode.php does)',
    $header !== null && StashKey::forHeader($header, $password) === $key);
step('the original file name is not visible in the archive (it is encrypted)',
    !str_contains((string) file_get_contents($archive), basename($fixture)));

$wrongPasswordExtractDir = $workDir . '/wrong';
step(
    'extraction fails with a key from the wrong password (same salt)',
    $crypto->extract($archive, $wrongPasswordExtractDir, StashKey::forHeader($header, 'not-the-right-password')) === false,
);
step('...and leaves no partial plaintext behind', glob("{$wrongPasswordExtractDir}/*") === []);
step(
    'extraction fails with the right password under a different salt',
    $crypto->extract($archive, $wrongPasswordExtractDir, $sameAgain) === false,
);

step('extract archive with correct key', $crypto->extract($archive, $extractDir, $key));

$extractedFile = $extractDir . '/' . basename($fixture);
step('extracted file exists, under its original name', file_exists($extractedFile));
step(
    'extracted file matches original (byte-for-byte)',
    file_exists($extractedFile) && hash_file('sha256', $fixture) === hash_file('sha256', $extractedFile),
);

// --- tampering: every way of changing an archive must be refused ---
$tamper = static function (string $label, callable $mutate) use ($archive, $crypto, $key, $workDir): void {
    $copy = $workDir . '/tampered.epc';
    copy($archive, $copy);
    $bytes = (string) file_get_contents($copy);
    file_put_contents($copy, $mutate($bytes));
    $out = $workDir . '/tampered-out';
    $opened = $crypto->extract($copy, $out, $key);
    step("a tampered archive is refused: {$label}", $opened === false);
    step('...and leaves no partial plaintext behind', glob("{$out}/*") === []);
    @unlink($copy);
};
$tamper('one byte flipped in the middle of the video', static function (string $b): string {
    $at = intdiv(strlen($b), 2);
    $b[$at] = chr(ord($b[$at]) ^ 0x01);

    return $b;
});
$tamper('one byte flipped in the encrypted file name', static function (string $b): string {
    $b[66] = chr(ord($b[66]) ^ 0x01);

    return $b;
});
$tamper('the last chunk cut off (truncated)', static fn(string $b): string => substr($b, 0, strlen($b) - 100));
$tamper('bytes appended after the end', static fn(string $b): string => $b . 'extra');
$tamper('the Argon2id cost edited in the header', static function (string $b): string {
    $b[10] = chr(ord($b[10]) ^ 0x02);

    return $b;
});
$tamper('not an .epc at all', static fn(string $b): string => 'PK' . substr($b, 2));

// Sizes at the edges of the 1 MiB chunking.
foreach (['empty' => 0, 'one byte' => 1, 'exactly 1 MiB' => 1048576, '1 MiB + 1' => 1048577, '3 MiB' => 3145728] as $label => $size) {
    $plain = $workDir . '/edge.bin';
    file_put_contents($plain, $size > 0 ? random_bytes($size) : '');
    $edgeArchive = $workDir . '/edge.epc';
    $edgeOut = $workDir . '/edge-out';
    $ok = $crypto->encrypt($plain, $edgeArchive, $key) && $crypto->extract($edgeArchive, $edgeOut, $key);
    step("round-trips a file of {$label}", $ok && hash_file('sha256', "{$edgeOut}/edge.bin") === hash_file('sha256', $plain));
    @unlink("{$edgeOut}/edge.bin");
}

// StashCrypto::replace() — the one that must never lose the file it is
// replacing (Docs/PLAN.md 4.29). encrypt() deletes its destination before
// writing the replacement; these check that replace() never leaves that hole.
$live = $workDir . '/live.epc';
$other = $workDir . '/other.txt';
file_put_contents($other, 'the replacement contents');

step('replace() creates an archive that did not exist yet',
    $crypto->replace($fixture, $live, $key) && file_exists($live));

step('replace() swaps in the new contents',
    $crypto->replace($other, $live, $key));

$swapped = $workDir . '/swapped';
$crypto->extract($live, $swapped, $key);
step('the swapped archive holds the new file, not the old one',
    file_exists("{$swapped}/other.txt") && !file_exists("{$swapped}/1.mp4"));

$litter = static fn(string $path): array => glob("{$path}.new*") ?: [];
step('a successful replace leaves no .new or .old litter',
    $litter($live) === [] && !file_exists("{$live}.old"));

// The invariant the whole item exists for: a failed replace must leave the
// original archive exactly as it was, still readable.
$before = hash_file('sha256', $live);

step('replace() refuses a source that is not a file',
    $crypto->replace($workDir . '/no-such-file', $live, $key) === false);
step('...and the original archive is untouched',
    hash_file('sha256', $live) === $before);

// The failure that actually loses videos: the encrypt itself fails after the
// destination has been cleared. A key that is not a key reproduces it without
// having to fill a disk.
$brokenKey = 'epck1.not-a-real-key';
step('replace() reports a failed encrypt instead of ignoring it',
    $crypto->replace($other, $live, $brokenKey) === false);
step('...and the original archive is still there, byte for byte',
    file_exists($live) && hash_file('sha256', $live) === $before);

step('a failed replace leaves no .new or .old litter',
    $litter($live) === [] && !file_exists("{$live}.old"));

step('the original still opens after the failures',
    $crypto->extract($live, $workDir . '/still', $key));

// encrypt() is the unsafe one by design — this is what replace() exists to
// avoid, and stating it here keeps the difference from being forgotten.
$doomed = $workDir . '/doomed.epc';
$crypto->encrypt($other, $doomed, $key);
$crypto->encrypt($other, $doomed, $brokenKey);
step('encrypt() destroys its destination when it fails (hence replace())',
    !file_exists($doomed));

$encoder = new VideoEncoder();

$codec = $encoder->videoCodec($fixture);
step("ffprobe reads a video codec from fixture (got: " . ($codec ?? 'null') . ")", $codec !== null);

$converted = $workDir . '/1_converted.mp4';
step('ffmpeg converts fixture to MP4/H.265', $encoder->convertToMp4Hevc($fixture, $converted));
step('converted file is now MP4/HEVC', $encoder->isAlreadyMp4Hevc($converted));

$previewImage = $workDir . '/1_preview.jpg';
step('ffmpeg extracts a preview frame at 15s', $encoder->extractFrame($fixture, $previewImage, 15.0));

$previewClip = $workDir . '/1_preview.mp4';
step('ffmpeg builds the timelapse preview clip', $encoder->buildPreviewClip($fixture, $previewClip));

$fixtureDuration = $encoder->durationSeconds($fixture) ?? 0.0;
$clipDuration = $encoder->durationSeconds($previewClip) ?? 0.0;
step(
    sprintf(
        'preview clip skims the whole video (source %.1fs -> preview %.1fs)',
        $fixtureDuration,
        $clipDuration,
    ),
    $clipDuration > 0 && $clipDuration < $fixtureDuration,
);

// convertImage() caps the *longest* edge (creator avatars, and VideoPreview's
// MAX_EDGE). The cap used to be written as `min($maxEdge,iw)`, which only ever
// constrained the width: a 600x1800 upload came back 512 wide and ~1536 high —
// over the cap, and not the file size the cap is there to bound. A landscape
// case passes either way, so all three orientations are here.
$imageCases = [
    // label, source size, cap, the edge the cap binds
    ['a landscape image', '900x300', 128, 'width'],
    ['a portrait image', '300x900', 128, 'height'],
    // Already inside the cap: min() leaves it alone rather than upscaling it.
    ['an image smaller than the cap', '100x80', 128, 'neither'],
];

foreach ($imageCases as [$label, $size, $cap, $binds]) {
    [$srcW, $srcH] = array_map('intval', explode('x', $size));
    $src = $workDir . "/image_{$size}.png";
    $dst = $workDir . "/image_{$size}_capped.png";

    exec(sprintf(
        'ffmpeg -v error -y -f lavfi -i color=c=red:s=%s -frames:v 1 %s',
        escapeshellarg($size),
        escapeshellarg($src),
    ));

    step("synthesised a {$size} source image", file_exists($src));
    step("{$label} converts", $encoder->convertImage($src, $dst, $cap));

    [$outW, $outH] = getimagesize($dst);

    if ($binds === 'neither') {
        step(
            sprintf('%s is left at its own size (%dx%d)', $label, $outW, $outH),
            $outW === $srcW && $outH === $srcH,
        );
        continue;
    }

    step(
        sprintf('%s fits inside the %d cap on both edges (%dx%d)', $label, $cap, $outW, $outH),
        $outW <= $cap && $outH <= $cap,
    );
    step(
        sprintf('%s is capped on its %s (%dx%d)', $label, $binds, $outW, $outH),
        ($binds === 'width' ? $outW : $outH) === $cap,
    );
    // -2 rounds the free edge to something even, so allow a pixel of drift.
    step(
        sprintf('%s keeps its aspect ratio (%dx%d from %s)', $label, $outW, $outH, $size),
        abs($outW / $outH - $srcW / $srcH) < 0.05,
    );
}

// Derived tags (Docs/SPECIFICATIONS.md §2.3) — pure logic, no ffmpeg needed.
$qualityCases = [
    [4320, '8K'], [2160, '4K'], [1440, '2K'], [1080, 'Full HD'],
    [720, 'HD'],
    // 360p and 480p are both just standard definition — no per-height badge.
    [480, 'SD'], [360, 'SD'], [240, 'SD'],
    // Between tiers a video takes the lower one.
    [1439, 'Full HD'], [719, 'SD'], [null, null],
];

foreach ($qualityCases as [$height, $expected]) {
    step(
        sprintf('quality tag for height %s is %s', $height ?? 'unknown', $expected ?? 'none'),
        VideoQuality::tagForHeight($height) === $expected,
    );
}

step('mp4/hevc counts as converted', VideoQuality::isNotConverted('mp4', 'hevc') === false);
step('mp4/h264 counts as not converted', VideoQuality::isNotConverted('mp4', 'h264') === true);
step('mov/hevc counts as not converted', VideoQuality::isNotConverted('mov', 'hevc') === true);

$fileHeight = $encoder->videoHeight($fixture);
step(
    sprintf('ffprobe reads the fixture height (%s) and it tags as %s', $fileHeight ?? 'null', VideoQuality::tagForHeight($fileHeight) ?? 'none'),
    $fileHeight !== null,
);

step(
    'a 23-character password is rejected, 24 accepted',
    !User::isValidPassword(str_repeat('a', 23)) && User::isValidPassword(str_repeat('a', 24)),
);

// Search (Docs/PLAN.md 5.1) — pure logic over index entries, no stash needed.
// Lengths are all in the Videos band, so the wall's default (shorts hidden)
// does not get in the way of what these cases are about.
$library = [
    ['id' => '1', 'title' => 'Skateboarding at dusk', 'creators' => ['Jamie K.'],
     'categories' => ['Sport', 'Outdoors'], 'length_seconds' => 600, 'views' => 0, 'uploaded_at' => '2026-01-01'],
    ['id' => '2', 'title' => 'Office slip and fall', 'creators' => ['default', 'Alex R.'],
     'categories' => ['Personal'], 'length_seconds' => 620, 'views' => 3, 'uploaded_at' => '2026-02-01'],
    ['id' => '3', 'title' => 'Beach day', 'creators' => ['Alex R.'],
     'categories' => ['Outdoors'], 'length_seconds' => 1200, 'views' => 9, 'uploaded_at' => '2026-03-01'],
];

$search = static function (string $term) use ($library): array {
    $found = VideoQuery::fromRequest(['q' => $term])->apply($library);

    return array_column($found, 'id');
};

$searchCases = [
    // field it should match on   => term, expected ids
    'title' => ['skateboarding', ['1']],
    'title, case-insensitively' => ['SKATEBOARDING', ['1']],
    'a partial word in a title' => ['skat', ['1']],
    'a creator name' => ['Alex', ['3', '2']],
    'a creator who is not the first credited' => ['Alex R.', ['3', '2']],
    'a category tag' => ['outdoors', ['3', '1']],
    'nothing when there is no match' => ['zzz', []],
];

foreach ($searchCases as $label => [$term, $expected]) {
    $got = $search($term);
    step(
        sprintf('search matches on %s ("%s" -> %s)', $label, $term, implode(',', $got) ?: 'none'),
        $got === $expected,
    );
}

// Several words must all match, but each may match a different field — this is
// what makes "jamie sport" a useful query rather than an impossible one.
step(
    'every term must match, across different fields ("jamie sport")',
    $search('jamie sport') === ['1'],
);
step(
    'a term that matches nothing rules the video out ("jamie beach")',
    $search('jamie beach') === [],
);

step('an empty search returns everything', count($search('')) === 3);
step('a whitespace-only search returns everything', count($search('   ')) === 3);

step(
    'search composes with the other filters',
    array_column(
        VideoQuery::fromRequest(['q' => 'alex', 'category' => ['Outdoors']])->apply($library),
        'id',
    ) === ['3'],
);

step(
    'search composes with sort (views min->max)',
    array_column(
        VideoQuery::fromRequest(['q' => 'alex', 'sort' => 'views_asc'])->apply($library),
        'id',
    ) === ['2', '3'],
);

$parsed = VideoQuery::fromRequest(['q' => "  Jamie   K.  \n "]);
step(
    sprintf('the term is trimmed and its whitespace collapsed ("%s")', $parsed->search),
    $parsed->search === 'Jamie K.' && $parsed->searchTerms() === ['Jamie', 'K.'],
);

step(
    'an over-long term is truncated, not rejected',
    mb_strlen(VideoQuery::fromRequest(['q' => str_repeat('x', 500)])->search) === VideoQuery::MAX_SEARCH_LENGTH,
);

// `?q[]=x` would otherwise reach the matcher as the string "Array".
step(
    'an array q is ignored rather than searched for "Array"',
    VideoQuery::fromRequest(['q' => ['x']])->search === '',
);

step('a search counts as a filter', VideoQuery::fromRequest(['q' => 'x'])->isFiltered());
step('a bare wall counts as filtered, since it hides shorts', VideoQuery::fromRequest([])->isFiltered());
step('every type ticked is not filtered', !VideoQuery::fromRequest(['type' => ['', 'short', 'video', 'movie']])->isFiltered());

// Type (shorts / videos / movies by running time).
step(
    'type boundaries: 3:59 short, 4:00 video, 29:59 video, 30:00 movie',
    VideoQuery::typeOf(239) === 'short' && VideoQuery::typeOf(240) === 'video'
        && VideoQuery::typeOf(1799) === 'video' && VideoQuery::typeOf(1800) === 'movie',
);

$typed = [
    ['id' => 's', 'title' => 'S', 'categories' => [], 'length_seconds' => 30, 'views' => 0, 'uploaded_at' => '2026-03-01'],
    ['id' => 'v', 'title' => 'V', 'categories' => [], 'length_seconds' => 600, 'views' => 0, 'uploaded_at' => '2026-02-01'],
    ['id' => 'm', 'title' => 'M', 'categories' => [], 'length_seconds' => 5400, 'views' => 0, 'uploaded_at' => '2026-01-01'],
];
$typeIds = static fn(array $request): array => array_column(VideoQuery::fromRequest($request)->apply($typed), 'id');

step('a bare wall shows videos and movies, not shorts', $typeIds([]) === ['v', 'm']);
step('ticking only Shorts shows only shorts', $typeIds(['type' => ['', 'short']]) === ['s']);
step('unticking every type shows everything', $typeIds(['type' => ['']]) === ['s', 'v', 'm']);
step('an unknown type is ignored', $typeIds(['type' => ['', 'bogus', 'movie']]) === ['m']);

// Legacy entries store a single `creator` string rather than a `creators` list.
step(
    'search finds a legacy single-creator entry',
    array_column(
        VideoQuery::fromRequest(['q' => 'morgan'])->apply([
            ['id' => '9', 'title' => 'Old entry', 'creator' => 'Morgan P.',
             'categories' => [], 'length_seconds' => 600, 'views' => 0],
        ]),
        'id',
    ) === ['9'],
);

// Creator search and sort (Docs/PLAN.md 5.8, 5.9) — again pure logic.
$people = [
    'default' => ['id' => '1', 'bio' => 'booping'],
    'Alex R.' => ['id' => '2', 'bio' => 'skate filmer', 'age' => 31, 'gender' => 'Non-binary'],
    'Jamie K.' => ['id' => '3', 'bio' => '', 'age' => 24, 'gender' => 'Female'],
    'Morgan P.' => ['id' => '4', 'bio' => 'drone pilot', 'gender' => 'Male'],
];

// default is on both videos, Alex on one, Jamie on one, Morgan on none.
$clips = [
    ['id' => '1', 'creators' => ['default', 'Alex R.']],
    ['id' => '2', 'creators' => ['default', 'Jamie K.']],
];

$creatorSearch = static fn(string $term): array => array_keys(
    CreatorQuery::fromRequest(['q' => $term])->apply($people, $clips),
);

step('creator search matches a name', $creatorSearch('jamie') === ['Jamie K.']);
step('creator search matches a bio', $creatorSearch('drone') === ['Morgan P.']);
step('creator search matches a gender', $creatorSearch('female') === ['Jamie K.']);
step('creator search is case-insensitive', $creatorSearch('ALEX') === ['Alex R.']);
step('creator search matches a partial word', $creatorSearch('skat') === ['Alex R.']);
step('creator search returns nothing when nothing matches', $creatorSearch('zzz') === []);
step('an empty creator search returns everyone', count($creatorSearch('')) === 4);
step(
    'every creator term must match ("skate filmer" vs "skate drone")',
    $creatorSearch('skate filmer') === ['Alex R.'] && $creatorSearch('skate drone') === [],
);

$creatorSort = static fn(string $sort): array => array_keys(
    CreatorQuery::fromRequest(['sort' => $sort])->apply($people, $clips),
);

step(
    'creators sort by name A→Z by default',
    $creatorSort('name_asc') === ['Alex R.', 'default', 'Jamie K.', 'Morgan P.'],
);
step(
    'name Z→A is the exact reverse',
    $creatorSort('name_desc') === array_reverse($creatorSort('name_asc')),
);
step(
    'creators sort by video count, max→min',
    $creatorSort('videos_desc') === ['default', 'Alex R.', 'Jamie K.', 'Morgan P.'],
);
step(
    'creators sort by video count, min→max',
    $creatorSort('videos_asc') === ['Morgan P.', 'Alex R.', 'Jamie K.', 'default'],
);
step(
    'creators sort by age, young→old, with unknown ages last',
    $creatorSort('age_asc') === ['Jamie K.', 'Alex R.', 'default', 'Morgan P.'],
);
step(
    'creators sort by age, old→young, with unknown ages still last',
    $creatorSort('age_desc') === ['Alex R.', 'Jamie K.', 'default', 'Morgan P.'],
);

// "o" matches default (bio "booping"), Alex (gender "Non-binary") and Morgan
// (name), but not Jamie — so this checks the sort really is applied to a
// filtered set rather than to everyone.
step(
    'creator search and sort compose',
    array_keys(CreatorQuery::fromRequest(['q' => 'o', 'sort' => 'videos_desc'])->apply($people, $clips))
        === ['default', 'Alex R.', 'Morgan P.'],
);

step('an unknown creator sort falls back to the default',
    CreatorQuery::fromRequest(['sort' => 'nonsense'])->sort === CreatorQuery::DEFAULT_SORT);
step('an array q is ignored on the creator search',
    CreatorQuery::fromRequest(['q' => ['x']])->search === '');
step('a creator search counts as filtered',
    CreatorQuery::fromRequest(['q' => 'x'])->isFiltered() && !CreatorQuery::fromRequest([])->isFiltered());

// Creator view counts (Docs/PLAN.md 4.11). $library credits Jamie on video 1
// (0 views), Alex on videos 2 and 3 (3 + 9), and default on video 2 (3).
$creatorViews = static fn(string $name): int => VideoCreators::viewCount(['videos' => $library], $name);

step('a creator\'s views are the sum of their videos\' views', $creatorViews('Alex R.') === 12);
step('a co-credited video counts in full for each creator', $creatorViews('default') === 3);
step('an unwatched creator has no views', $creatorViews('Jamie K.') === 0);
step('a creator with no videos has no views', $creatorViews('Morgan P.') === 0);
step('view counts read legacy single-creator entries',
    VideoCreators::viewCount(['videos' => [['creator' => 'Morgan P.', 'views' => 4]]], 'Morgan P.') === 4);

// The same view counts drive the Creators screen's views sort.
$viewSort = static fn(string $sort): array => array_keys(
    CreatorQuery::fromRequest(['sort' => $sort])->apply($people, $library),
);
step('creators sort by views, max→min',
    $viewSort('views_desc') === ['Alex R.', 'default', 'Jamie K.', 'Morgan P.']);
step('creators sort by views, min→max, ties broken by name',
    $viewSort('views_asc') === ['Jamie K.', 'Morgan P.', 'default', 'Alex R.']);

// Creator age/gender filters on the wall (Docs/PLAN.md 5.2). These are
// attributes of the *creator*, so the video list alone cannot answer them —
// $people is passed alongside.
$byCreator = static fn(array $params): array => array_column(
    VideoQuery::fromRequest($params)->apply($library, $people),
    'id',
);

step('an untouched age slider filters nothing', count($byCreator([])) === 3);
step('a minimum age keeps only videos credited to someone that old',
    $byCreator(['age_min' => 30]) === ['3', '2']);
step('a maximum age keeps only videos credited to someone that young',
    $byCreator(['age_max' => 25]) === ['1']);
step('an age range excludes everyone outside it',
    $byCreator(['age_min' => 26, 'age_max' => 29]) === []);
step('a gender filter matches a creator\'s gender',
    $byCreator(['gender' => 'Female']) === ['1']);
step('a gender filter is case-insensitive',
    $byCreator(['gender' => 'non-binary']) === ['3', '2']);
step('a gender nobody\'s videos carry matches nothing',
    $byCreator(['gender' => 'Male']) === []);

// A video credited to several people matches if *any* of them does: video 2 is
// Alex (31) and default (no age), and an age filter must not rule it out on
// default's account.
step('one matching creator is enough on a co-credited video',
    in_array('2', $byCreator(['age_min' => 30]), true));

// ...but an unrecorded age is not evidence of being in the range asked for.
step('a creator with no age set fails an active age filter',
    array_column(VideoQuery::fromRequest(['age_min' => 19])->apply(
        [['id' => '9', 'title' => 'x', 'creators' => ['default'], 'categories' => [], 'length_seconds' => 5, 'views' => 0]],
        $people,
    ), 'id') === []);

step('age and gender compose with the other filters',
    $byCreator(['age_min' => 30, 'category' => ['Outdoors']]) === ['3']);

step('an age filter counts as a filter',
    VideoQuery::fromRequest(['age_min' => 25])->isFiltered()
        && VideoQuery::fromRequest(['gender' => 'Female'])->isFiltered()
        && !VideoQuery::fromRequest(['age_min' => VideoQuery::MIN_AGE, 'age_max' => VideoQuery::MAX_AGE, 'type' => array_keys(VideoQuery::TYPES)])->isFiltered());

$ages = VideoQuery::fromRequest(['age_min' => 40, 'age_max' => 20]);
step('an upside-down age range is corrected rather than matching nothing',
    $ages->minAge === 40 && $ages->maxAge === 40);
step('an out-of-range age is clamped to the slider',
    VideoQuery::fromRequest(['age_min' => 3, 'age_max' => 900])->minAge === VideoQuery::MIN_AGE
        && VideoQuery::fromRequest(['age_min' => 3, 'age_max' => 900])->maxAge === VideoQuery::MAX_AGE);
step('an array gender is ignored rather than filtered for "Array"',
    VideoQuery::fromRequest(['gender' => ['x']])->gender === '');

// Password change / re-keying (Docs/PLAN.md 6.1). Runs against a throwaway
// stash of its own — never the caller's — and removes it again at the end.
$probe = 'RekeyProbe' . bin2hex(random_bytes(3));
$oldPassword = 'probe-old-password-aaaaaaaa';
$newPassword = 'probe-new-password-bbbbbbbb';

$users = new User();
$oldKey = $users->create($probe, $oldPassword);
step("create a throwaway stash ({$probe})", $oldKey !== null);
step('...and its index opens with the key derived from the password again',
    StashKey::unlock($probe, $oldPassword) === $oldKey);

$store = new Datastore();
$store->saveVideoMetadata($probe, $oldKey, '1', ['id' => '1', 'title' => 'Probe clip']);

$rekey = new Rekey();

step(
    'a too-short new password is refused',
    $rekey->run($probe, $oldKey, 'short')['ok'] === false,
);
step(
    'reusing the current password is refused',
    $rekey->run($probe, $oldKey, $oldPassword)['ok'] === false,
);
step(
    'a key from a wrong current password is refused',
    $rekey->run($probe, StashKey::create('not-the-current-password-x'), $newPassword)['ok'] === false,
);
step(
    'the stash still opens with the old password after those refusals',
    $store->loadIndex($probe, $oldKey) !== null,
);

$result = $rekey->run($probe, $oldKey, $newPassword);
step("re-key succeeds ({$result['message']})", $result['ok']);
step('it rewrote more than just the index', $result['rewritten'] >= 2);

$newKey = StashKey::unlock($probe, $newPassword);
step('the stash opens with the new password', $newKey !== null && $store->loadIndex($probe, $newKey) !== null);
step('the re-key gave the stash a fresh salt',
    StashKey::parse($newKey)['salt'] !== StashKey::parse($oldKey)['salt']);
step('the old password no longer derives a key that opens it',
    $store->loadIndex($probe, (string) StashKey::unlock($probe, $oldPassword)) === null);
step('the stash no longer opens with the old one', $store->loadIndex($probe, $oldKey) === null);
step(
    'per-video metadata came across too, not just the index',
    ($store->loadVideoMetadata($probe, $newKey, '1')['title'] ?? null) === 'Probe clip',
);

$leftovers = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(Datastore::userDir($probe), FilesystemIterator::SKIP_DOTS),
);
foreach ($walk as $file) {
    if ($file->isFile() && str_ends_with($file->getPathname(), '.epc.old')) {
        $leftovers[] = $file->getFilename();
    }
}
step(
    'no .epc.old safety copies are left behind (' . (implode(', ', $leftovers) ?: 'none') . ')',
    $leftovers === [],
);

Datastore::wipe(Datastore::userDir($probe));
step('the throwaway stash is gone', !is_dir(Datastore::userDir($probe)));


// ---------------------------------------------------------------------------
// Background jobs (Docs/PLAN.md 4.28, 6.6)
//
// The worker is spawned for real here — a deliberately unknown job kind, so it
// exercises the whole detach-and-report path (spawn, lock, claim the secrets,
// write an outcome) without needing ffmpeg or a stash to work on.
// ---------------------------------------------------------------------------

echo PHP_EOL . "-- background jobs --" . PHP_EOL;

step(
    'a job id is derived from the user and the target, not random',
    Jobs::id('convert', 'Ann', '7') === Jobs::id('convert', 'Ann', '7'),
);
step(
    '...so two users never collide, and neither do two videos',
    Jobs::id('convert', 'Ann', '7') !== Jobs::id('convert', 'Bob', '7')
        && Jobs::id('convert', 'Ann', '7') !== Jobs::id('convert', 'Ann', '8'),
);

$jobUser = 'JobProbe' . bin2hex(random_bytes(3));
$jobId = Jobs::id('probe', $jobUser, '');

step('nothing is alive before a job is started', !Jobs::isAlive($jobId));
step('...and there is no record to read', Jobs::read($jobId) === null);

$started = Jobs::start('probe', $jobUser, '', ['password' => 'the-secret-key-nobody-may-see'], []);
step('starting a job reports success', $started['ok'] && $started['id'] === $jobId);

// The request that started it must not have waited for it. A worker that took
// the request's thread with it is the whole bug 4.28 is about.
$record = Jobs::read($jobId);
step('the record exists immediately, without waiting for the work', $record !== null);

// The redirect straight after pressing Convert lands here, before the worker
// has had time to take its lock. Reporting "the job died" at that moment would
// be wrong every single time.
step(
    'a job that has only just been spawned is not reported as dead',
    ($record['state'] ?? '') === Jobs::RUNNING,
);

// The password went to the worker, never into the record the browser polls.
step(
    'the job record carries no password',
    !str_contains((string) json_encode($record), 'the-secret-key-nobody-may-see'),
);

// Give the worker its moment to run and report. `waitFor` rather than a fixed
// sleep: this machine may be busy, and a test that depends on a spawned
// process being quick is a test that fails for no reason.
$waitFor = static function (callable $done, float $seconds = 30.0): void {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline && !$done()) {
        usleep(50_000);
    }
};

$waitFor(static fn(): bool => (Jobs::read($jobId)['state'] ?? '') !== Jobs::RUNNING);
$record = Jobs::read($jobId);

step('the worker ran and reported an outcome (' . ($record['state'] ?? '?') . ')',
    ($record['state'] ?? '') === Jobs::FAILED);
step('...and said why', str_contains((string) ($record['message'] ?? ''), 'Unknown job type'));

step(
    'the key file is destroyed once the worker has read it',
    !file_exists(Jobs::ROOT . '/' . $jobId . '.key'),
);
// Writing the outcome and exiting are two separate moments: the record is
// written from inside the worker, which is still holding its lock for the
// instant it takes to return. Waiting for the lock is the assertion — that it
// is released *when the worker exits*, not that it has already gone the
// microsecond the record appeared.
$waitFor(static fn(): bool => !Jobs::isAlive($jobId));
step('the lock is released when the worker exits', !Jobs::isAlive($jobId));

// A worker killed outright leaves a record that still says "running" forever.
// The lock is the only thing that actually knows, and read() must trust it
// rather than the record — otherwise the page shows a bar that never moves.
// Backdated past the spawn grace window: this is a job that started a minute
// ago and whose worker is gone, not one that is still getting off the ground.
Jobs::update($jobId, [
    'state' => Jobs::RUNNING,
    'message' => 'pretending',
    'started_at' => time() - 60,
]);
$abandoned = Jobs::read($jobId);
step(
    'a record claiming to run with no worker behind it is reported as failed',
    ($abandoned['state'] ?? '') === Jobs::FAILED && $abandoned['alive'] === false,
);

// aliveFor() is what stops a stash being deleted out from under a running
// worker (7.0.2), and job ids are hashes, so it has to find a job by reading
// the records rather than by computing an id. Holding the lock here stands in
// for a worker holding it.
$held = Jobs::hold($jobId);
step('aliveFor finds a job that is holding its lock', Jobs::aliveFor($jobUser) === 'probe');
step('...and does not report it against a different user', Jobs::aliveFor('SomeoneElse') === null);

if ($held !== false) {
    fclose($held);
}

step('...and stops finding it once the lock is gone', Jobs::aliveFor($jobUser) === null);

Jobs::forget($jobId);
step('forgetting a job removes its record', Jobs::read($jobId) === null);

step(
    'a job id that did not come from id() is refused rather than used as a path',
    (static function (): bool {
        try {
            Jobs::read('../../etc/passwd');
        } catch (\InvalidArgumentException) {
            return true;
        }

        return false;
    })(),
);


// ---------------------------------------------------------------------------
// Deleting a stash (Docs/PLAN.md 7.0.2)
//
// Every refusal below has to leave the stash completely intact — this is the
// one operation in the app with nothing to restore from.
// ---------------------------------------------------------------------------

echo PHP_EOL . "-- deleting a stash --" . PHP_EOL;

$users = new User();
$doomed = 'DelProbe' . bin2hex(random_bytes(3));
$doomedPassword = 'delete-probe-password-aaaaaaaa';

// The stash the traversal below tries to reach past the data directory and
// delete. It is created here rather than being assumed to exist: this check
// used to name TestUser, which meant it failed on a fresh clone with an empty
// datastore and — worse — would have passed vacuously on any machine where
// that stash happened to be missing, because a directory that was never there
// cannot be observed to survive.
$bystander = 'DelBystander' . bin2hex(random_bytes(3));
step("create a bystander stash ({$bystander})", $users->create($bystander, $doomedPassword) !== null);

step("create a throwaway stash ({$doomed})", $users->create($doomed, $doomedPassword) !== null);

$openable = static fn(): bool => ($k = StashKey::unlock($doomed, $doomedPassword)) !== null
    && (new Datastore())->loadIndex($doomed, $k) !== null;
step('...and it opens', $openable());

$wrong = $users->delete($doomed, 'this-is-not-the-password-aaaa');
step('a wrong password is refused', $wrong['ok'] === false);
step('...and says so without naming what it protects (' . $wrong['message'] . ')',
    str_contains($wrong['message'], 'nothing was deleted'));
step('...and the stash is still there, still opening', $openable());

// The name is about to be the last segment of a recursive delete. It comes
// from the session in practice, but a traversal must not be able to reach
// past the data directory even if it ever did not.
foreach (["../{$bystander}", 'Del/Probe', '..', '', 'Has Space'] as $bad) {
    $refused = $users->delete($bad, $doomedPassword);
    step("a name that is not letters-and-digits is refused (" . var_export($bad, true) . ")",
        $refused['ok'] === false);
}
step("{$bystander} survived every one of those",
    is_dir(Datastore::userDir($bystander)));

$gone = $users->delete($doomed, $doomedPassword);
step('the right password deletes it (' . $gone['message'] . ')', $gone['ok'] === true);
step('the directory is actually gone', !is_dir(Datastore::userDir($doomed)));
step('...and it no longer opens', !$openable());
step('deleting it twice is refused rather than pretending',
    $users->delete($doomed, $doomedPassword)['ok'] === false);
step('the name is free to use again', $users->create($doomed, $doomedPassword) !== null);

Datastore::wipe(Datastore::userDir($doomed));
Datastore::wipe(Datastore::userDir($bystander));
step('cleaned up', !is_dir(Datastore::userDir($doomed))
    && !is_dir(Datastore::userDir($bystander)));

// ---------------------------------------------------------------------------
// Changing a video's preview image (Docs/PLAN.md 3.9)
//
// The preview archive is the only copy of that image, so the invariant that
// matters most here is 4.29's: a re-preview that fails must leave the previous
// picture byte-for-byte intact rather than leaving the video with none.
// ---------------------------------------------------------------------------

echo PHP_EOL . "-- changing a video's preview --" . PHP_EOL;

$previewUser = 'PrevProbe' . bin2hex(random_bytes(3));
$users = new User();
$previewKey = $users->create($previewUser, 'preview-probe-password-aaaaaaaa');

step("create a throwaway stash ({$previewUser})", $previewKey !== null);

$store = new Datastore();
$previewIndex = $store->loadIndex($previewUser, $previewKey);
$ingested = (new VideoIngest())->ingest(
    $previewUser, $previewKey, $fixture, 'preview-probe.mp4', $previewIndex,
);
$previewIndex['videos'][] = $ingested;
$store->saveIndex($previewUser, $previewKey, $previewIndex);

$videoId = (string) $ingested['id'];
$previewArchive = Datastore::videoDir($previewUser, $videoId) . "/{$videoId}.jpg.preview.epc";

step('the upload produced a preview archive', is_file($previewArchive));

// The hover clip is a background job now (upload.php starts it), so ingestion
// must *not* make one — that is the work that held the upload request open
// past the proxy's timeout. The job's body is VideoPreview::buildClip().
$clipArchive = Datastore::videoDir($previewUser, $videoId) . "/{$videoId}.mp4.preview.epc";
step('ingestion leaves the hover clip to the background job', !is_file($clipArchive));

$clip = (new VideoPreview())->buildClip($previewUser, $previewKey, $videoId);
step('the preview job builds the hover clip (' . $clip['message'] . ')', $clip['ok'] && is_file($clipArchive));

$clipOut = Datastore::tmpfsWorkDir('smokeclip');
step('...and it decrypts to a playable clip',
    (new StashCrypto())->extract($clipArchive, $clipOut, $previewKey)
    && ((new VideoEncoder())->durationSeconds(glob("{$clipOut}/*")[0] ?? '') ?? 0) > 0);
Datastore::wipe($clipOut);

$gone = (new VideoPreview())->buildClip($previewUser, $previewKey, '9999');
step('a preview job for a video that no longer exists fails cleanly', $gone['ok'] === false
    && !is_dir(Datastore::videoDir($previewUser, '9999')));

// The bytes to compare every later assertion against.
$archiveHash = static fn(): string => (string) hash_file('sha256', $previewArchive);
$originalHash = $archiveHash();

$preview = new VideoPreview();

// --- a frame from a different point in the video ---
$moved = $preview->fromTimestamp($previewUser, $previewKey, $videoId, 3.0);
step('a frame can be taken from a different timestamp (' . $moved['message'] . ')', $moved['ok'] === true);
step('...and the stored preview actually changed', $archiveHash() !== $originalHash);

$afterTimestamp = $archiveHash();

$movedMeta = $store->loadVideoMetadata($previewUser, $previewKey, $videoId);
step('...and the metadata records where it came from',
    (int) round((float) $movedMeta['preview_capture_seconds']) === 3);

// The archive must still open and hold a real JPEG, not merely be a different
// size — replace() verifies its own work, but this proves it end to end.
$previewOut = Datastore::tmpfsWorkDir('smokeprev');
step('the new preview archive still decrypts',
    (new StashCrypto())->extract($previewArchive, $previewOut, $previewKey));
$extractedPreview = glob("{$previewOut}/*")[0] ?? null;
step('...to something that is really an image',
    $extractedPreview !== null && @getimagesize($extractedPreview) !== false);
Datastore::wipe($previewOut);

// --- a picture of the user's own ---
//
// Built here rather than read from dev/playwright: that directory is not
// mounted into the container, so a fixture path there silently skips the whole
// block — which is exactly what it did the first time this was written. A PNG
// is made on the spot so the "stored as JPEG whatever was uploaded" assertion
// below is testing a real format conversion.
$ownDir = Datastore::tmpfsWorkDir('smokeprev');
$ownPicture = "{$ownDir}/supplied.png";
step('built a PNG to stand in for a user-supplied picture',
    (new VideoEncoder())->extractFrame($fixture, $ownPicture, 1.0) && is_file($ownPicture));
step('...and it really is a PNG', (@getimagesize($ownPicture)[2] ?? 0) === IMAGETYPE_PNG);

{
    $supplied = $preview->fromUpload($previewUser, $previewKey, $videoId, $ownPicture);
    step('a supplied picture can replace the preview (' . $supplied['message'] . ')', $supplied['ok'] === true);
    step('...and the stored preview changed again', $archiveHash() !== $afterTimestamp);

    // A PNG went in; a JPEG must come out, because the archive is named
    // .jpg.preview.epc and media.php serves it as image/jpeg.
    $suppliedOut = Datastore::tmpfsWorkDir('smokeprev');
    (new StashCrypto())->extract($previewArchive, $suppliedOut, $previewKey);
    $suppliedFile = glob("{$suppliedOut}/*")[0] ?? null;
    $info = $suppliedFile !== null ? @getimagesize($suppliedFile) : false;
    step('...stored as a JPEG whatever was uploaded (' . ($info[2] ?? 0) . ' = IMAGETYPE_JPEG)',
        $info !== false && $info[2] === IMAGETYPE_JPEG);
    step('...capped at ' . VideoPreview::MAX_EDGE . 'px on the longest edge',
        $info !== false && max($info[0], $info[1]) <= VideoPreview::MAX_EDGE);
    Datastore::wipe($suppliedOut);

    $suppliedMeta = $store->loadVideoMetadata($previewUser, $previewKey, $videoId);
    step('...and the capture point is cleared, not left lying about the source',
        $suppliedMeta['preview_capture_seconds'] === null);
}
Datastore::wipe($ownDir);

$beforeRefusals = $archiveHash();

// --- every refusal must leave the picture exactly as it was ---
$notAnImage = Datastore::tmpfsWorkDir('smokeprev') . '/notanimage.png';
file_put_contents($notAnImage, 'this is definitely not a PNG');
$refusedUpload = $preview->fromUpload($previewUser, $previewKey, $videoId, $notAnImage);
step('a file that is not an image is refused (' . $refusedUpload['message'] . ')',
    $refusedUpload['ok'] === false);
step('...and the previous preview is byte-for-byte intact', $archiveHash() === $beforeRefusals);
Datastore::wipe(dirname($notAnImage));

$pastEnd = $preview->fromTimestamp($previewUser, $previewKey, $videoId, 99999.0);
step('a timestamp past the end of the video is refused (' . $pastEnd['message'] . ')',
    $pastEnd['ok'] === false);
step('...and the previous preview is still intact', $archiveHash() === $beforeRefusals);

$negative = $preview->fromTimestamp($previewUser, $previewKey, $videoId, -5.0);
step('a negative timestamp is refused', $negative['ok'] === false);
step('...and the previous preview is still intact', $archiveHash() === $beforeRefusals);

$wrongKey = $preview->fromTimestamp($previewUser, StashKey::create('not-the-password-aaaaaaaaaaaa'), $videoId, 1.0);
step('a wrong password cannot re-preview', $wrongKey['ok'] === false);
step('...and the previous preview is still intact', $archiveHash() === $beforeRefusals);

$missing = $preview->fromTimestamp($previewUser, $previewKey, '9999', 1.0);
step('a video that does not exist is refused (' . $missing['message'] . ')', $missing['ok'] === false);

// --- a picture supplied at upload time (the other half of 3.9) ---
//
// Ingestion has its own path for this, separate from VideoPreview's, so it
// needs its own proof rather than inheriting the assertions above.
$atUploadDir = Datastore::tmpfsWorkDir('smokeprev');
$atUploadPng = "{$atUploadDir}/supplied.png";
(new VideoEncoder())->extractFrame($fixture, $atUploadPng, 2.0);

$previewIndex = $store->loadIndex($previewUser, $previewKey);
$withOwn = (new VideoIngest())->ingest(
    $previewUser, $previewKey, $fixture, 'own-preview.mp4', $previewIndex,
    30.0, $atUploadPng,
);
$ownId = (string) $withOwn['id'];
$ownMeta = $store->loadVideoMetadata($previewUser, $previewKey, $ownId);
step('a picture supplied at upload time is used instead of a frame',
    $ownMeta['preview_capture_seconds'] === null);

// The two ingests above deliberately do not save the index between them, which
// makes nextVideoId() hand out the same id twice. The second must take the
// next free one rather than encrypting over the first video's files.
step('a second upload before the index is saved does not clobber the first',
    $ownId !== $videoId && is_file(Datastore::videoDir($previewUser, $videoId) . "/{$videoId}.mp4.epc"));

// An unreadable one must not fail the whole upload — the video has already
// been transferred, and losing it over a thumbnail would be a poor trade.
$junk = "{$atUploadDir}/junk.png";
file_put_contents($junk, 'not an image');
$previewIndex = $store->loadIndex($previewUser, $previewKey);
$withJunk = (new VideoIngest())->ingest(
    $previewUser, $previewKey, $fixture, 'junk-preview.mp4', $previewIndex,
    5.0, $junk,
);
$junkId = (string) $withJunk['id'];
$junkMeta = $store->loadVideoMetadata($previewUser, $previewKey, $junkId);
step('an unreadable supplied picture falls back to the capture time, not a failed upload',
    (int) round((float) $junkMeta['preview_capture_seconds']) === 5);
step('...and that video still has a preview',
    is_file(Datastore::videoDir($previewUser, $junkId) . "/{$junkId}.jpg.preview.epc"));
Datastore::wipe($atUploadDir);

// No litter from replace()'s write-verify-rotate.
$dir = Datastore::videoDir($previewUser, $videoId);
$litter = array_merge(glob("{$dir}/*.old") ?: [], glob("{$dir}/*.new") ?: []);
step('no .old or .new copies left behind', $litter === []);

Datastore::wipe(Datastore::userDir($previewUser));
step('cleaned up', !is_dir(Datastore::userDir($previewUser)));

// -------------------------------------------------------------------------
echo PHP_EOL . "-- trimming a video (Video Stats) --" . PHP_EOL;
// -------------------------------------------------------------------------
//
// A trim writes a second archive beside the video and never touches the video
// until the user keeps it. The ranges are the part to *remove*.

// --- the rules, before any ffmpeg ---
$v = static fn(string $mode, int $from, ?int $to, ?int $length = 20000): ?string
    => VideoTrim::validate(VideoTrim::spec($mode, $from, $to), $length);

step('Start 0–5000ms of a 20s video is fine', $v('start', 0, 5000) === null);
step('Start to 0ms is refused', $v('start', 0, 0) !== null);
step('Start past the end is refused', $v('start', 0, 20000) !== null);
step('End from 15000ms is fine', $v('end', 15000, null) === null);
step('End from 0ms (the whole video) is refused', $v('end', 0, null) !== null);
step('Cut 5000–10000ms is fine', $v('cut', 5000, 10000) === null);
step('a Cut from 0ms is refused, pointing at Start (' . $v('cut', 0, 4000) . ')', $v('cut', 0, 4000) !== null);
step('a Cut past the end is refused', $v('cut', 5000, 20000) !== null);
step('a Cut that ends before it starts is refused', $v('cut', 9000, 8000) !== null);
step('an unknown length leaves the upper bound to the worker', $v('cut', 5000, 999999, null) === null);
step('lengths read as the form shows them (723029 → 12m3s29ms)', VideoTrim::formatMs(723029) === '12m3s29ms');

$cut = VideoTrim::spec('cut', 5000, 10000);
step('a point before a cut stays put', VideoTrim::mapSeconds(3.0, $cut) === 3.0);
step('a point inside a cut moves to its edge', VideoTrim::mapSeconds(7.0, $cut) === 5.0);
step('a point after a cut moves back by its length', VideoTrim::mapSeconds(12.0, $cut) === 7.0);

// --- the real thing, on a throwaway stash ---
$trimUser = 'TrimProbe' . bin2hex(random_bytes(3));
$trimKey = (new User())->create($trimUser, 'trim-probe-password-aaaaaaaaaa');
step("create a throwaway stash ({$trimUser})", $trimKey !== null);

$trimIndex = $store->loadIndex($trimUser, $trimKey);
$trimEntry = (new VideoIngest())->ingest($trimUser, $trimKey, $fixture, 'trim-probe.mp4', $trimIndex, 12.0);
$trimIndex['videos'][] = $trimEntry;
$store->saveIndex($trimUser, $trimKey, $trimIndex);
$trimId = (string) $trimEntry['id'];
step('ingest records the length to the millisecond (' . $trimEntry['duration_ms'] . 'ms)',
    abs($trimEntry['duration_ms'] - 20000) < 100);

// A category at 12s, inside the cut-to-be's far side, to watch it move.
$trimIndex = $store->loadIndex($trimUser, $trimKey);
(new VideoCategories())->save($trimUser, $trimKey, $trimId,
    [['name' => 'Probe', 'timestamp_seconds' => 12]], $trimIndex);
$store->saveIndex($trimUser, $trimKey, $trimIndex);

$videoArchive = Datastore::videoDir($trimUser, $trimId) . "/{$trimId}.mp4.epc";
$videoHash = hash_file('sha256', $videoArchive);
$trimmer = new VideoTrim();
$encoder = new VideoEncoder();

$trimmedLength = static function () use ($trimUser, $trimKey, $trimId, $encoder): float {
    $out = Datastore::tmpfsWorkDir('smoketrim');
    (new StashCrypto())->extract(VideoTrim::archivePath($trimUser, $trimId), $out, $trimKey);
    $length = $encoder->durationSeconds(glob("{$out}/*")[0] ?? '') ?? 0.0;
    Datastore::wipe($out);

    return $length;
};

foreach ([['start', 0, 5000], ['end', 15000, null], ['cut', 5000, 10000]] as [$mode, $from, $to]) {
    $made = $trimmer->make($trimUser, $trimKey, $trimId, VideoTrim::spec($mode, $from, $to));
    $length = $made['ok'] ? $trimmedLength() : 0.0;
    step(sprintf('%s trim makes a 15s copy (%s, got %.2fs)', ucfirst($mode), $made['message'], $length),
        $made['ok'] && abs($length - 15.0) < 0.2);
    step('...and leaves the video byte-for-byte alone', hash_file('sha256', $videoArchive) === $videoHash);

    if ($mode !== 'cut') {
        $trimmer->discard($trimUser, $trimKey, $trimId);
    }
}

$again = $trimmer->make($trimUser, $trimKey, $trimId, VideoTrim::spec('start', 0, 1000));
step('a second trim is refused while a copy is waiting', $again['ok'] === false);

$trimmer->discard($trimUser, $trimKey, $trimId);
$late = $trimmer->make($trimUser, $trimKey, $trimId, VideoTrim::spec('cut', 5000, 25000));
step('the worker refuses a cut past the real end (' . $late['message'] . ')',
    $late['ok'] === false && !VideoTrim::exists($trimUser, $trimId));

$discarded = $trimmer->discard($trimUser, $trimKey, $trimId);
step('Delete removes the copy and leaves no trim record',
    $discarded['ok'] && !VideoTrim::exists($trimUser, $trimId)
    && !isset($store->loadVideoMetadata($trimUser, $trimKey, $trimId)['trim']));

// --- Keep a cut of 5–10s: the video becomes the 15s copy ---
$trimmer->make($trimUser, $trimKey, $trimId, VideoTrim::spec('cut', 5000, 10000));
$kept = $trimmer->keep($trimUser, $trimKey, $trimId);
$keptMeta = $store->loadVideoMetadata($trimUser, $trimKey, $trimId);
$keptEntry = array_values(array_filter(
    $store->loadIndex($trimUser, $trimKey)['videos'],
    static fn($video) => $video['id'] === $trimId,
))[0] ?? [];

step('Keep swaps the trimmed copy in (' . $kept['message'] . ')',
    $kept['ok'] && !VideoTrim::exists($trimUser, $trimId) && hash_file('sha256', $videoArchive) !== $videoHash);
step('...the index has the new length', ($keptEntry['length_seconds'] ?? 0) === 15
    && abs(($keptEntry['duration_ms'] ?? 0) - 15000) < 200);
step('...the category at 12s moved to 7s', ($keptMeta['categories'][0]['timestamp_seconds'] ?? null) === 7);
step('...the preview capture at 12s moved to 7s', (int) round((float) $keptMeta['preview_capture_seconds']) === 7);
step('...it was not taken from the cut, so no retake is asked for', $kept['frame_at'] === null);
step('...and the trim record is gone', !isset($keptMeta['trim']));

// A thumbnail from inside the removed part is retaken where that point lands.
$trimmer->make($trimUser, $trimKey, $trimId, VideoTrim::spec('start', 0, 9000));
$retake = $trimmer->keep($trimUser, $trimKey, $trimId);
step('a thumbnail from the trimmed-off part asks for a retake at 0s', $retake['frame_at'] === 0.0);
$clip = (new VideoPreview())->buildClip($trimUser, $trimKey, $trimId, null, $retake['frame_at']);
step('...which the preview job does along with the clip', $clip['ok']
    && (float) $store->loadVideoMetadata($trimUser, $trimKey, $trimId)['preview_capture_seconds'] === 0.0);

// --- a video with no sound: the graph must not name [0:a] ---
$silentDir = Datastore::tmpfsWorkDir('smoketrim');
$silent = "{$silentDir}/silent.mp4";
exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=size=320x180:rate=25:duration=6 -pix_fmt yuv420p %s', escapeshellarg($silent)));
step('a silent test video exists', is_file($silent) && !$encoder->hasAudio($silent));
$silentOut = "{$silentDir}/silent_trim.mp4";
step('a cut out of a silent video encodes',
    $encoder->convertToMp4Hevc($silent, $silentOut,
        filterGraph: VideoTrim::filterGraph(VideoTrim::spec('cut', 2000, 4000), false))
    && abs(($encoder->durationSeconds($silentOut) ?? 0) - 4.0) < 0.2);
Datastore::wipe($silentDir);

Datastore::wipe(Datastore::userDir($trimUser));
step('cleaned up', !is_dir(Datastore::userDir($trimUser)));

// -------------------------------------------------------------------------
echo PHP_EOL . "-- hostile input at the command boundary (7.2) --" . PHP_EOL;
// -------------------------------------------------------------------------
//
// Every external program this app runs is started with an argv array through
// proc_open(), never a shell string, so nothing a user types can become a
// command. These assert that rather than trusting it — and they assert it
// through the values a user genuinely controls: the encryption password and
// the uploaded filename.

$injUser = 'InjProbe' . bin2hex(random_bytes(3));

// Every shell metacharacter that matters, inside the password. v1 passed it
// to 7z as `-p<password>` on every encrypt and extract; v2 never puts it on a
// command line at all, but the v1 upgrade still hands it to 7z, so it stays.
$injPassword = 'a$(touch /tmp/mystash-pwned-pw)`touch /tmp/mystash-pwned-bt`;|&<>"\' --zz';

foreach (['/tmp/mystash-pwned-pw', '/tmp/mystash-pwned-bt', '/tmp/mystash-pwned-fn'] as $canary) {
    @unlink($canary);
}

$injKey = (new User())->create($injUser, $injPassword);
step("create a stash whose password is nothing but shell metacharacters ({$injUser})",
    $injKey !== null);

$injStore = new Datastore();
step('...and the index reopens with it, so it was derived from verbatim',
    ($reKey = StashKey::unlock($injUser, $injPassword)) !== null && $injStore->loadIndex($injUser, $reKey) !== null);

// The same treatment for the filename, which comes from the browser.
$injIndex = $injStore->loadIndex($injUser, $injKey);
$injEntry = (new VideoIngest())->ingest(
    $injUser, $injKey, $fixture, 'evil$(touch /tmp/mystash-pwned-fn)`id`;rm -rf /.mp4', $injIndex,
);
step('a filename full of shell metacharacters ingests normally', isset($injEntry['id']));

foreach (['/tmp/mystash-pwned-pw', '/tmp/mystash-pwned-bt', '/tmp/mystash-pwned-fn'] as $canary) {
    step("nothing executed: {$canary}", !file_exists($canary));
}

// pathinfo() takes the basename first, so neither `..` nor an embedded slash
// can walk out of the work directory — and safeExtension() then strips the
// result down to letters and digits.
$injIndex = $injStore->loadIndex($injUser, $injKey);
$traversal = (new VideoIngest())->ingest(
    $injUser, $injKey, $fixture, 'a.mp4/../../../../app/public/shell.php', $injIndex,
);
step('a traversing filename cannot choose the stored extension (' . $traversal['format'] . ')',
    preg_match('/^[a-z0-9]{1,12}$/', $traversal['format']) === 1);
step('...and nothing was written outside App/Data', !file_exists(__DIR__ . '/../public/shell.php'));

// 7.2.1 — what the audit turned up. Each of these used to fail the upload
// *after* the whole video had been transferred, and leave an encrypted
// directory behind that no screen could reach and no button could delete.
$orphans = static fn(): array => array_map('basename', glob(Datastore::userDir($injUser) . '/videos/Video*') ?: []);
$before = $orphans();

$injIndex = $injStore->loadIndex($injUser, $injKey);
$past = (new VideoIngest())->ingest($injUser, $injKey, $fixture, 'past.mp4', $injIndex, 999999.0);
$pastMeta = $injStore->loadVideoMetadata($injUser, $injKey, (string) $past['id']);
step('a preview timestamp past the end of the video no longer fails the upload', isset($past['id']));
step('...and is clamped to somewhere inside it (' . (float) $pastMeta['preview_capture_seconds'] . 's)',
    (float) $pastMeta['preview_capture_seconds'] <= (float) $past['length_seconds']);

$injIndex = $injStore->loadIndex($injUser, $injKey);
$long = (new VideoIngest())->ingest($injUser, $injKey, $fixture, 'x.' . str_repeat('A', 300), $injIndex);
step('a 300-character extension no longer fails the upload (stored as "' . $long['format'] . '")',
    strlen((string) $long['format']) <= 12);

// The one case that *should* still fail — and must fail cleanly.
$notVideo = Datastore::tmpfsWorkDir('smokeinj') . '/junk.mp4';
file_put_contents($notVideo, 'this is not a video');
$injIndex = $injStore->loadIndex($injUser, $injKey);
$beforeJunk = $orphans();

try {
    (new VideoIngest())->ingest($injUser, $injKey, $notVideo, 'junk.mp4', $injIndex);
    step('a file that is not a video is refused', false);
} catch (\Throwable $e) {
    step('a file that is not a video is refused, with a real message (' . $e->getMessage() . ')',
        $e->getMessage() !== '');
}

step('...and the failed upload left no orphan directory behind', $orphans() === $beforeJunk);

Datastore::wipe(Datastore::userDir($injUser));
step('cleaned up', !is_dir(Datastore::userDir($injUser)));

// -------------------------------------------------------------------------
echo PHP_EOL . "-- login throttling (7.3) --" . PHP_EOL;
// -------------------------------------------------------------------------

// Cleared before and after: these counters are shared with the running app,
// and a test that left one behind would lock a real name out for real.
$clearThrottle = static function (): void {
    foreach (glob('/dev/shm/mystash-login/*.json') ?: [] as $f) {
        @unlink($f);
    }
};
$clearThrottle();

$tUser = 'ThrUnit' . bin2hex(random_bytes(3));
$tAddr = '203.0.113.' . random_int(1, 254);

step('a name with no history is not throttled',
    LoginThrottle::retryAfter($tUser, $tAddr) === 0);

for ($i = 0; $i < LoginThrottle::MAX_PER_USER - 1; $i++) {
    LoginThrottle::recordFailure($tUser, $tAddr);
}

step('it is still allowed one attempt below the limit',
    LoginThrottle::retryAfter($tUser, $tAddr) === 0);

LoginThrottle::recordFailure($tUser, $tAddr);
$wait = LoginThrottle::retryAfter($tUser, $tAddr);

step("the limit locks the name out ({$wait}s)", $wait > 0);
step('...and the wait never exceeds the window, so it always expires',
    $wait <= LoginThrottle::WINDOW_SECONDS);

// A different name must be unaffected: the counter is per name, not global.
step('another name at the same address is unaffected',
    LoginThrottle::retryAfter('ThrOther' . bin2hex(random_bytes(3)), $tAddr) === 0);

LoginThrottle::clear($tUser);
step('a successful login clears that name', LoginThrottle::retryAfter($tUser, $tAddr) === 0);

// clear() must not wipe the address counter too: on a shared address that
// would hand an attacker a reset button for every name behind it.
$tAddr2 = '203.0.113.' . random_int(1, 254);
$victims = [];
for ($i = 0; $i < LoginThrottle::MAX_PER_ADDRESS; $i++) {
    $victims[$i] = 'ThrSpray' . bin2hex(random_bytes(4));
    LoginThrottle::recordFailure($victims[$i], $tAddr2);
}
step('spraying many names from one address trips the address limit',
    LoginThrottle::addressRetryAfter($tAddr2) > 0);

LoginThrottle::clear($victims[0]);
step('...and clearing one of those names does not reset the address',
    LoginThrottle::addressRetryAfter($tAddr2) > 0);

// The floor is what closes the timing oracle, so it has to actually wait.
$began = microtime(true);
LoginThrottle::settle($began);
$slept = microtime(true) - $began;
step(sprintf('settle() holds a fast failure to the floor (%.0fms)', $slept * 1000),
    $slept >= LoginThrottle::FLOOR_SECONDS * 0.9);

// ...and must never add to a slow one, or it becomes a way to hold workers.
$began = microtime(true) - 5.0;
$before = microtime(true);
LoginThrottle::settle($began);
step('...and adds nothing to work that was already slower',
    microtime(true) - $before < 0.05);

$clearThrottle();
step('cleaned up', glob('/dev/shm/mystash-login/*.json') === []);

echo PHP_EOL . "-- playlists (5.4) --" . PHP_EOL;

// A bare index, the way a stash that predates playlists looks.
$plIndex = ['videos' => [
    ['id' => '1', 'title' => 'One'],
    ['id' => '2', 'title' => 'Two'],
    ['id' => '3', 'title' => 'Three'],
]];

step('a stash with no playlists key reads as no playlists', Playlists::all($plIndex) === []);

$plId = Playlists::create($plIndex, 'Watch Later');
step('creating a playlist returns its id', $plId === '1');
step('...and it is stored with an empty video list',
    Playlists::find($plIndex, '1')['videos'] === []);

step('a blank name is refused', Playlists::create($plIndex, '   ') === null);
step('a name over the limit is refused',
    Playlists::create($plIndex, str_repeat('x', Playlists::MAX_NAME_LENGTH + 1)) === null);
step('...and neither refusal created anything', count(Playlists::all($plIndex)) === 1);

// Names are a label, not a key: two lists may share one.
$dupId = Playlists::create($plIndex, 'Watch Later');
step('two playlists may carry the same name', $dupId === '2' && count(Playlists::all($plIndex)) === 2);

$known = ['1', '2', '3'];

Playlists::setVideos($plIndex, '1', ['3', '1'], $known);
step('setVideos stores exactly the order it was given',
    Playlists::find($plIndex, '1')['videos'] === ['3', '1']);

// The order IS the playlist, so a reorder is just another setVideos.
Playlists::setVideos($plIndex, '1', ['1', '3'], $known);
step('...and a reorder rewrites it', Playlists::find($plIndex, '1')['videos'] === ['1', '3']);

Playlists::setVideos($plIndex, '1', ['1', '9', '2'], $known);
step('an id the stash does not hold is dropped rather than stored',
    Playlists::find($plIndex, '1')['videos'] === ['1', '2']);

Playlists::setVideos($plIndex, '1', ['2', '1', '2'], $known);
step('a duplicate collapses to its first position',
    Playlists::find($plIndex, '1')['videos'] === ['2', '1']);

step('toggle adds a video that is absent', Playlists::toggle($plIndex, '1', '3') === true);
step('...on the end, leaving the order alone',
    Playlists::find($plIndex, '1')['videos'] === ['2', '1', '3']);
step('toggle removes a video that is present', Playlists::toggle($plIndex, '1', '1') === false);
step('...and closes the gap', Playlists::find($plIndex, '1')['videos'] === ['2', '3']);

Playlists::toggle($plIndex, '2', '3');
step('containing() finds every playlist holding a video',
    Playlists::containing($plIndex, '3') === ['1', '2']);
step('...and none for a video on no list', Playlists::containing($plIndex, '1') === []);

step('renaming changes the name', Playlists::rename($plIndex, '1', 'Later') === true
    && Playlists::find($plIndex, '1')['name'] === 'Later');
step('...and nothing else — the videos are untouched',
    Playlists::find($plIndex, '1')['videos'] === ['2', '3']);
step('renaming to a blank name is refused', Playlists::rename($plIndex, '1', '') === false);
step('renaming a playlist that does not exist is refused',
    Playlists::rename($plIndex, '99', 'Nope') === false);

// What video_delete.php calls: a playlist must never hold a video that is gone.
Playlists::forgetVideo($plIndex, '3');
step('deleting a video drops it from every playlist',
    Playlists::find($plIndex, '1')['videos'] === ['2']
    && Playlists::find($plIndex, '2')['videos'] === []);

$plBefore = count($plIndex['videos']);
Playlists::delete($plIndex, '2');
step('deleting a playlist removes it', Playlists::find($plIndex, '2') === null);
step('...and does not touch the videos', count($plIndex['videos']) === $plBefore);

// Ids appear in URLs, so a bookmark to a deleted playlist must not open a
// different one that happens to have been created since.
$reuse = Playlists::create($plIndex, 'Fresh');
step('a deleted playlist id is never handed out again', $reuse === '3');

// videosOf() is what both screens render from.
$rows = Playlists::videosOf($plIndex, Playlists::find($plIndex, '1'));
step('videosOf returns index entries in playlist order',
    array_column($rows, 'id') === ['2']);

$stale = ['id' => '9', 'name' => 'Stale', 'videos' => ['2', '404', '1']];
step('...and skips an id with no video behind it rather than rendering a hole',
    array_column(Playlists::videosOf($plIndex, $stale), 'id') === ['2', '1']);

// A record written by an older build, or hand-edited, must read like any other.
$ragged = ['playlists' => [['name' => 'Ragged']]];
$norm = Playlists::all($ragged)[0];
step('a playlist record missing its fields is filled in, not fatal',
    $norm['videos'] === [] && $norm['name'] === 'Ragged' && $norm['id'] === '0');


// --- Stats: the reduce rules ---------------------------------------------
step('2560x1440 is over Full HD', VideoStats::exceedsFullHd(2560, 1440));
step('1920x1080 is not over Full HD', !VideoStats::exceedsFullHd(1920, 1080));
// A phone clip held upright is at Full HD, turned on its side — not over it.
step('portrait 1080x1920 is not over Full HD', !VideoStats::exceedsFullHd(1080, 1920));
step('portrait 1440x2560 is over Full HD', VideoStats::exceedsFullHd(1440, 2560));
step('an unmeasured size is never over', !VideoStats::exceedsFullHd(null, null));
step('60fps is over 30', VideoStats::exceedsTargetFps(60.0));
// NTSC: re-encoding 29.97 "down" to 30 would be a full transcode for nothing.
step('29.97fps and 30fps are not over', !VideoStats::exceedsTargetFps(29.97) && !VideoStats::exceedsTargetFps(30.0));
step('the landscape filter fits the 1920x1080 box',
    VideoStats::filterFor(true, true, 3840, 2160)
        === 'fps=30,scale=1920:1080:force_original_aspect_ratio=decrease:force_divisible_by=2');
step('the portrait filter turns the box upright',
    VideoStats::filterFor(false, true, 2160, 3840)
        === 'scale=1080:1920:force_original_aspect_ratio=decrease:force_divisible_by=2');
step('no options means no filter', VideoStats::filterFor(false, false, 3840, 2160) === null);
step('byte counts read as people say them',
    VideoStats::formatBytes(512) === '512 B' && VideoStats::formatBytes(1536) === '1.5 KB'
        && VideoStats::formatBytes(812 * 1024 * 1024) === '812 MB');

// ---------------------------------------------------------------------------
// The v1 → v2 upgrade (Docs/v2_migration.md)
//
// A v1 stash is built by hand with 7z, exactly as v1 wrote one, and upgraded
// — first directly, then through the background job login.php starts.
// ---------------------------------------------------------------------------

echo PHP_EOL . "-- upgrading a v1 stash to v2 --" . PHP_EOL;

$legacy = new Crypto7z();
$v1Password = 'v1-upgrade-probe-password-aaaa';

$makeV1Stash = static function (string $user) use ($legacy, $v1Password, $fixture): array {
    $index = [
        'categories' => ['Not Converted' => '#cc4444'],
        'creators' => ['default' => ['id' => '1', 'name' => 'default']],
        'videos' => [['id' => '1', 'title' => 'A v1 video']],
    ];
    $scratch = Datastore::tmpfsWorkDir('v1probe');
    $write = static function (string $name, string $contents, string $archive) use ($legacy, $v1Password, $scratch): void {
        if (!is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0700, true);
        }
        file_put_contents("{$scratch}/{$name}", $contents);
        $legacy->encrypt("{$scratch}/{$name}", $archive, $v1Password);
        unlink("{$scratch}/{$name}");
    };
    $write("{$user}.json", (string) json_encode($index), Datastore::legacyIndexArchivePath($user));
    $video = Datastore::userDir($user) . '/videos/Video1';
    $write('1.json', (string) json_encode(['id' => '1', 'title' => 'A v1 video']), "{$video}/1.json.enc");
    $write('1.mp4', (string) file_get_contents($fixture), "{$video}/1.mp4.enc");
    $write('1.json', (string) json_encode(['id' => '1', 'name' => 'default']),
        Datastore::userDir($user) . '/creators/Creator1/1.json.enc');
    Datastore::wipe($scratch);

    return $index;
};

$archivesEndingIn = static function (string $user, string $suffix): array {
    $found = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Datastore::userDir($user), FilesystemIterator::SKIP_DOTS),
    );
    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), $suffix)) {
            $found[] = $file->getPathname();
        }
    }
    sort($found);

    return $found;
};

$v1User = 'UpgradeProbe' . bin2hex(random_bytes(3));
$v1Index = $makeV1Stash($v1User);
$store = new Datastore();
$migrate = new MigrateV2();

step('a hand-built v1 stash is recognised as v1', $store->isV1Stash($v1User));
step('...and has no v2 key to unlock yet', StashKey::unlock($v1User, $v1Password) === null);
step('the v1 password check refuses a wrong password', !$migrate->opensV1($v1User, 'not-the-v1-password-aaaa'));
step('...and accepts the right one', $migrate->opensV1($v1User, $v1Password));

$v1Before = array_map('hash_file', array_fill(0, 4, 'sha256'), $archivesEndingIn($v1User, '.enc'));

$refused = $migrate->run($v1User, 'not-the-v1-password-aaaa');
step('an upgrade with the wrong password is refused (' . $refused['message'] . ')', $refused['ok'] === false);
step('...and changes nothing', $archivesEndingIn($v1User, '.epc') === []
    && array_map('hash_file', array_fill(0, 4, 'sha256'), $archivesEndingIn($v1User, '.enc')) === $v1Before);

// A v1 archive that will not open part way through: everything already
// written must be rolled back, and the v1 stash left exactly as it was.
$broken = Datastore::userDir($v1User) . '/videos/Video1/1.mp4.enc';
$brokenBytes = (string) file_get_contents($broken);
file_put_contents($broken, 'this is not a 7z archive');
$rolledBack = $migrate->run($v1User, $v1Password);
step('an upgrade that hits an unreadable archive fails (' . $rolledBack['message'] . ')', $rolledBack['ok'] === false);
step('...and rolls back every .epc it had written', $archivesEndingIn($v1User, '.epc') === []);
step('...and the stash is still v1', $store->isV1Stash($v1User));
file_put_contents($broken, $brokenBytes);

// A stale .epc from an interrupted earlier run must not get in the way.
file_put_contents(Datastore::userDir($v1User) . '/videos/Video1/1.json.epc', 'half-written by a crash');

$upgraded = $migrate->run($v1User, $v1Password);
step("the upgrade succeeds ({$upgraded['message']})", $upgraded['ok'] === true && $upgraded['converted'] === 4);
step('the stash is no longer v1', !$store->isV1Stash($v1User));
step('no .enc archive is left', $archivesEndingIn($v1User, '.enc') === []);
step('every archive has an .epc twin', count($archivesEndingIn($v1User, '.epc')) === 4);
step('no .new or .old litter is left',
    $archivesEndingIn($v1User, '.old') === [] && glob(Datastore::userDir($v1User) . '/videos/Video1/*.new.*') === []);

$v2Key = StashKey::unlock($v1User, $v1Password);
step('the same password now unlocks a v2 key', $v2Key !== null);
step('the index came across intact', $store->loadIndex($v1User, (string) $v2Key) == $v1Index);
step('the per-video metadata came across',
    ($store->loadVideoMetadata($v1User, (string) $v2Key, '1')['title'] ?? null) === 'A v1 video');
step('the creator record came across',
    ($store->loadCreatorMetadata($v1User, (string) $v2Key, '1')['name'] ?? null) === 'default');
$videoOut = $workDir . '/upgraded-video';
step('the video came across byte for byte',
    (new StashCrypto())->extract(Datastore::userDir($v1User) . '/videos/Video1/1.mp4.epc', $videoOut, (string) $v2Key)
    && hash_file('sha256', "{$videoOut}/1.mp4") === hash_file('sha256', $fixture));

$again = $migrate->run($v1User, $v1Password);
step('upgrading again is a harmless no-op (' . $again['message'] . ')', $again['ok'] === true && $again['converted'] === 0);

// A crash after the v2 index was written but before every .enc was deleted.
$legacy->encrypt($fixture, Datastore::userDir($v1User) . '/videos/Video1/1.mp4.enc', $v1Password);
$tidied = $migrate->run($v1User, $v1Password);
step('a re-run clears v1 leftovers from a finished upgrade (' . $tidied['message'] . ')',
    $tidied['ok'] === true && $archivesEndingIn($v1User, '.enc') === []);
step('...but only for the right password',
    $migrate->run($v1User, 'not-the-v1-password-aaaa')['ok'] === false);

Datastore::wipe(Datastore::userDir($v1User));

// The same upgrade as login.php runs it: a detached job, password in the key
// file, progress in the record.
$jobUpgradeUser = 'UpgradeJob' . bin2hex(random_bytes(3));
$makeV1Stash($jobUpgradeUser);
$upgradeJob = Jobs::id('upgrade', $jobUpgradeUser);
$startedUpgrade = Jobs::start('upgrade', $jobUpgradeUser, '', ['password' => $v1Password], []);
step('the upgrade job starts', $startedUpgrade['ok'] === true);
$waitFor(static fn(): bool => !in_array(Jobs::read($upgradeJob)['state'] ?? '', [Jobs::RUNNING, ''], true), 120.0);
$upgradeRecord = Jobs::read($upgradeJob);
step('the upgrade job finishes ok (' . ($upgradeRecord['message'] ?? 'no record') . ')',
    ($upgradeRecord['state'] ?? '') === Jobs::DONE);
step('...and leaves a v2 stash behind', !$store->isV1Stash($jobUpgradeUser)
    && StashKey::unlock($jobUpgradeUser, $v1Password) !== null);
step('the password never reached the job record',
    !str_contains((string) json_encode($upgradeRecord), $v1Password));
Jobs::forget($upgradeJob);
Datastore::wipe(Datastore::userDir($jobUpgradeUser));
step('cleaned up', !is_dir(Datastore::userDir($jobUpgradeUser)));

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
