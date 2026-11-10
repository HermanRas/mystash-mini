<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/CreatorStore.php';
require_once __DIR__ . '/../src/CreatorQuery.php';
require_once __DIR__ . '/../src/VideoCreators.php';

use MyStash\CreatorQuery;
use MyStash\CreatorStore;
use MyStash\Session;
use MyStash\VideoCreators;

Session::requireLogin();

$index = Session::refreshIndex();
$allCreators = $index['creators'] ?? [];
$categories = $index['categories'] ?? [];
$videos = $index['videos'] ?? [];

$query = CreatorQuery::fromRequest($_GET);
$creators = $query->apply($allCreators, $videos);
$totalCreators = count($allCreators);

$editing = $_GET['edit'] ?? null;
// Full details come from the creator's own encrypted record; the index copy is
// only the denormalized summary the grid renders from.
$editCreator = ($editing !== null && $editing !== '')
    ? (new CreatorStore())->load(Session::user(), Session::key(), $index, $editing)
    : null;

function videoCount(array $videos, string $creatorName): int
{
    return VideoCreators::videoCount(['videos' => $videos], $creatorName);
}

function viewCount(array $videos, string $creatorName): int
{
    return VideoCreators::viewCount(['videos' => $videos], $creatorName);
}

function avatarUrl(array $creator): ?string
{
    $id = (string) ($creator['id'] ?? '');

    return CreatorStore::hasProfileImage(Session::user(), $id)
        ? 'media.php?type=avatar&creator=' . urlencode($id)
        : null;
}

// A→Z / Z→A for the name sort, 0→9 / 9→0 for the numeric ones — same mapping
// the wall's sort menu uses.
// Opening a creator shouldn't throw away the search or sort you got there with.
$keepQuery = http_build_query(array_filter([
    'q' => $query->search,
    'sort' => $query->sort === CreatorQuery::DEFAULT_SORT ? '' : $query->sort,
]));

$sortIcon = match ($query->sort) {
    'name_asc' => 'sort-az',
    'name_desc' => 'sort-za',
    default => str_ends_with($query->sort, '_asc') ? 'sort-09' : 'sort-90',
};

// The site's one search box points at this page while you are on it, so it
// finds a creator rather than videos. It keeps the chosen sort.
$searchTerm = $query->search;
$searchAction = 'creator.php';
$searchPlaceholder = 'Search creators by name, bio or gender…';
$searchClearHref = 'creator.php' . ($query->sort === CreatorQuery::DEFAULT_SORT ? '' : '?sort=' . urlencode($query->sort));
$searchHidden = ['sort' => $query->sort];

/**
 * The Creators screen with one different sort, keeping the search term.
 */
$sortHref = static function (string $sort) use ($query): string {
    return 'creator.php?' . http_build_query(array_filter([
        'q' => $query->search,
        'sort' => $sort,
    ], static fn(string $value) => $value !== ''));
};

$navActive = 'creators';
$headerActions = '<a class="icon-btn" href="creator.php?edit="><img class="btn-icon" src="assets/img/icons/creator.png" alt="">Add Creator</a>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyStash — Creators</title>
<link rel="icon" href="assets/img/icon.png" type="image/png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php require __DIR__ . '/../views/header.php'; ?>

<main class="manage-layout">
  <h1 class="page-title">Creators</h1>

  <?php /* Sort lives here (5.9); the search (5.8) is the site's single box up
           in the header, pointed at this page. */ ?>
  <div class="wall-toolbar">
    <span class="wall-count">
      <?= count($creators) ?> of <?= $totalCreators ?> creator<?= $totalCreators === 1 ? '' : 's' ?>
      <?php if ($query->search !== ''): ?>
        matching <strong class="wall-term"><?= htmlspecialchars($query->search, ENT_QUOTES) ?></strong>
      <?php endif; ?>
    </span>

    <?php /* The same menu the wall uses — 5.9 deliberately made these two
             controls identical, so they change together. */ ?>
    <div class="sort-menu" tabindex="0">
      <button type="button" class="icon-btn square sort-trigger"
              title="Sort: <?= htmlspecialchars(CreatorQuery::SORTS[$query->sort], ENT_QUOTES) ?>"
              aria-label="Sort: <?= htmlspecialchars(CreatorQuery::SORTS[$query->sort], ENT_QUOTES) ?>">
        <img class="btn-icon" src="assets/img/icons/<?= $sortIcon ?>.png" alt="">
      </button>
      <div class="sort-menu-dropdown">
        <?php foreach (CreatorQuery::SORTS as $value => $label): ?>
          <a class="<?= $value === $query->sort ? 'active' : '' ?>"
             href="<?= htmlspecialchars($sortHref($value), ENT_QUOTES) ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="creator-grid">
    <?php foreach ($creators as $name => $creator): ?>
      <a class="creator-card" href="creator.php?edit=<?= urlencode($name) ?><?= $keepQuery !== '' ? '&amp;' . $keepQuery : '' ?>">
        <?php $avatar = avatarUrl($creator); ?>
        <div class="creator-avatar">
          <?php if ($avatar !== null): ?>
            <img src="<?= htmlspecialchars($avatar, ENT_QUOTES) ?>" alt="">
          <?php endif; ?>
        </div>
        <div class="creator-name">
          <?= htmlspecialchars($name, ENT_QUOTES) ?>
        </div>
        <?php $tally = videoCount($videos, $name); $watched = viewCount($videos, $name); ?>
        <div class="creator-meta">
          <?= $tally ?> video<?= $tally === 1 ? '' : 's' ?>
          • <?= $watched ?> view<?= $watched === 1 ? '' : 's' ?>
          <?php if (!empty($creator['age'])): ?> • Age <?= (int) $creator['age'] ?><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($creators === [] && $query->search !== ''): ?>
    <p class="hint">
      No creator matches <strong class="wall-term"><?= htmlspecialchars($query->search, ENT_QUOTES) ?></strong>.
      <a href="creator.php" style="color:var(--accent);">Show every creator</a>
    </p>
  <?php endif; ?>

  <?php if ($editing !== null): ?>
    <div class="section-title"><?= $editCreator ? 'Edit Creator' : 'Add Creator' ?></div>
    <div class="card">
      <?php if ($editCreator): ?>
        <?php $tally = videoCount($videos, $editing); $watched = viewCount($videos, $editing); ?>
        <p class="hint" style="margin:0 0 14px;">
          <?= $tally ?> video<?= $tally === 1 ? '' : 's' ?>,
          watched <?= $watched ?> time<?= $watched === 1 ? '' : 's' ?> in total.
        </p>
      <?php endif; ?>
      <form id="creator-form" action="creator_save.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="original_name" value="<?= htmlspecialchars($editing, ENT_QUOTES) ?>">
        <div class="field">
          <label for="c-name">Display name</label>
          <input type="text" id="c-name" name="name" value="<?= htmlspecialchars($editCreator['name'] ?? '', ENT_QUOTES) ?>" required>
        </div>
        <div class="field">
          <label for="c-age">Age</label>
          <input type="number" id="c-age" name="age" min="18" max="120" value="<?= htmlspecialchars((string) ($editCreator['age'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="c-gender">Gender</label>
          <input type="text" id="c-gender" name="gender" value="<?= htmlspecialchars($editCreator['gender'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="c-bio">Short bio</label>
          <input type="text" id="c-bio" name="bio" value="<?= htmlspecialchars($editCreator['bio'] ?? '', ENT_QUOTES) ?>" placeholder="Short bio…">
        </div>
        <div class="field">
          <label for="c-profile">Profile picture</label>
          <?php $editAvatar = $editCreator !== null ? avatarUrl($editCreator) : null; ?>
          <?php if ($editAvatar !== null): ?>
            <div class="creator-avatar" style="width:72px; height:72px; margin:0 0 8px;">
              <img src="<?= htmlspecialchars($editAvatar, ENT_QUOTES) ?>" alt="">
            </div>
          <?php endif; ?>
          <input type="file" id="c-profile" name="profile" accept="image/*">
          <p class="hint" style="margin:6px 0 0;">Stored encrypted alongside the creator's record. Leave empty to keep the current picture.</p>
        </div>
      </form>

      <?php if ($editCreator && $editing !== 'default'): ?>
        <form id="creator-delete-form" action="creator_delete.php" method="post"
              onsubmit="return confirm('Delete this creator? Their videos will be reassigned to default.');">
          <input type="hidden" name="name" value="<?= htmlspecialchars($editing, ENT_QUOTES) ?>">
        </form>
      <?php endif; ?>

      <?php /* Both buttons sit outside their forms and target them by id, so
               they can share one row (forms cannot be nested). */ ?>
      <div class="form-actions">
        <button type="submit" form="creator-form" class="btn"><img class="btn-icon" src="assets/img/icons/creator-save.png" alt="">Save Changes</button>
        <?php if ($editCreator && $editing !== 'default'): ?>
          <button type="submit" form="creator-delete-form" class="btn secondary"><img class="btn-icon" src="assets/img/icons/creator-delete.png" alt="">Delete Creator</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</main>

</body>
</html>
