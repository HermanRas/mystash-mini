<?php

declare(strict_types=1);

/**
 * Shared site header and top navigation (Docs/SPECIFICATIONS.md §4.2).
 *
 * Callers set $navActive to 'videos', 'creators' or 'categories' before
 * including this, and may set $headerActions to extra HTML for the right-hand
 * button group. A page with no pill of its own (the playlist screens) sets
 * something that matches none of them, and no pill lights up.
 *
 * There is exactly one search box on the site, and it is this one. It searches
 * whatever the page you are on is a list of: videos everywhere (landing on the
 * wall), creators while you are on the Creators screen. A page steers it by
 * setting $searchTerm (so the box still shows what was searched for after the
 * results load), and optionally $searchAction, $searchPlaceholder,
 * $searchClearHref and $searchHidden.
 *
 * The nav carries exactly three pills, and sits in the header beside the
 * search rather than on a band of its own. Category names deliberately do NOT
 * appear here: they live in the wall's left filter panel, and having both was
 * two ways to do the same thing. "All Videos" links to the bare wall URL, so
 * it doubles as the reset for whatever filters are applied.
 */

// The search box is on every page, and it is a wall query — so the header
// needs VideoQuery whether or not the including page uses it.
require_once __DIR__ . '/../src/VideoQuery.php';

use MyStash\Session;
use MyStash\VideoQuery;

$navActive = $navActive ?? '';
$headerActions = $headerActions ?? '';
$searchTerm = $searchTerm ?? '';
$searchAction = $searchAction ?? 'wall.php';
$searchPlaceholder = $searchPlaceholder ?? 'Search titles and categories…';
$searchClearHref = $searchClearHref ?? $searchAction;
// Extra state the search must not throw away (the Creators screen's sort).
$searchHidden = $searchHidden ?? [];

// Icons are sliced from the generated sheet in Docs/Assets/site_icons.png.
$navPills = [
    // MyStashMini: no Creators screen — everything is credited to `default`.
    'videos' => ['All', 'wall.php', 'videos'],
    'categories' => ['Categories', 'category.php', 'tag'],
];
?>
<header class="site-header">
  <?php /* Brand and nav are one group so that it and the actions group can be
           given equal weight, which is what puts the search box on the page's
           centre line rather than merely in the middle of whatever space its
           two neighbours happen to leave. */ ?>
  <div class="header-left">
    <a class="brand" href="wall.php">
      <img src="assets/img/mark.png" alt="MyStash">
      <span class="brand-name">MyStash Mini</span>
    </a>

  <?php /* The nav lives in the header rather than on a row of its own: a
           whole band of chrome above the wall was a lot of vertical space for
           three links. */ ?>
    <nav class="category-bar">
      <?php foreach ($navPills as $key => [$label, $href, $icon]): ?>
        <?php /* The label is dropped on a narrow window (see style.css), so it
                 carries a title for the tooltip the icon alone would not give. */ ?>
        <a class="pill<?= $key === $navActive ? ' active' : '' ?>" href="<?= $href ?>"
           title="<?= $label ?>">
          <img class="pill-icon" src="assets/img/icons/<?= $icon ?>.png" alt=""><span class="pill-label"><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php /* The site's one search box. On the Creators screen it searches
           creators in place; everywhere else it searches videos and lands on
           the wall, carrying no filters with it — narrowing down afterwards is
           what the filter panel is for (which does carry the term through). */ ?>
  <form class="search-bar" method="get" action="<?= htmlspecialchars($searchAction, ENT_QUOTES) ?>" role="search">
    <?php foreach ($searchHidden as $name => $value): ?>
      <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES) ?>"
             value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>">
    <?php endforeach; ?>
    <input type="text" name="q" aria-label="Search"
           maxlength="<?= VideoQuery::MAX_SEARCH_LENGTH ?>"
           value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>"
           placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES) ?>">
    <?php if ($searchTerm !== ''): ?>
      <a class="search-clear" href="<?= htmlspecialchars($searchClearHref, ENT_QUOTES) ?>" aria-label="Clear search">&times;</a>
    <?php endif; ?>
  </form>

  <div class="header-actions">
    <?= $headerActions ?>
    <div class="user-menu" tabindex="0">
      <div class="user-menu-trigger">
        <div class="avatar"><img src="assets/img/icons/user.png" alt=""></div>
        <?= htmlspecialchars(Session::user(), ENT_QUOTES) ?>
      </div>
      <div class="user-menu-dropdown">
        <?php /* Playlists live here and not in the pill bar: §4.2 fixes that
                 bar at exactly three destinations, and a fourth would make it
                 the place category names were kept out of for the same
                 reason. */ ?>
        <a href="playlist.php"><img class="menu-icon" src="assets/img/icons/playlist.png" alt="">Playlists</a>
        <a href="category.php"><img class="menu-icon" src="assets/img/icons/tag.png" alt="">Manage Categories</a>
        <a href="user.php"><img class="menu-icon" src="assets/img/icons/user.png" alt="">Profile &amp; Password</a>
        <a href="stats.php"><img class="menu-icon" src="assets/img/icons/sort-90.png" alt="">Video Stats</a>
        <a href="logout.php"><img class="menu-icon" src="assets/img/icons/logout.png" alt="">Log Out</a>
      </div>
    </div>
  </div>
</header>
