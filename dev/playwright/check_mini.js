// MyStashMini: the wall is three tiles wide with filters closed, bulk upload
// takes videos and pictures together, and the viewer pages with < > and the
// arrow keys. Registers its own throwaway stash; the media files are passed in
// as MEDIA (comma-separated paths inside this container).
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const MEDIA = (process.env.MEDIA || '').split(',').filter(Boolean);
const USER = 'MiniCheck' + Date.now();
const PASSWORD = 'MiniCheckPasswordMiniCheck';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  let failed = 0;
  const check = (label, ok) => { if (!ok) failed += 1; console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`); };
  const idOf = () => new URL(page.url()).searchParams.get('id');

  await page.goto(`${BASE}/register.php`);
  await page.fill('#username', USER);
  await page.fill('#password', PASSWORD);
  await page.fill('#confirm-password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  check('filter panel is closed on a bare wall', !(await page.isVisible('#filter-panel')));
  await page.click('#filters-toggle');
  check('the Filters button opens it', await page.isVisible('#filter-panel'));
  await page.click('#filters-toggle');
  check('no Creators link in the header', (await page.$$('a[href="creator.php"]')).length === 0);

  // Bulk upload through the real form and upload.js.
  await page.click('#upload-toggle');
  await page.setInputFiles('#media-files', MEDIA);
  await Promise.all([
    page.waitForURL(/wall\.php$/, { timeout: 120000 }),
    page.click('#upload-panel button[type="submit"]'),
  ]);
  await page.waitForSelector('.video-card');
  const tiles = await page.$$('.video-card');
  check(`bulk upload added all ${MEDIA.length} files (wall has ${tiles.length})`, tiles.length === MEDIA.length);
  check('pictures are tiles too', (await page.$$('.video-card.picture')).length >= 1);

  const columns = await page.$eval('.video-grid', (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
  check(`the wall is three tiles wide (${columns})`, columns === 3);
  await page.screenshot({ path: 'screenshots/mini_wall.png' });

  // Viewer: open the first tile and page through with the keyboard.
  const order = await page.$$eval('.video-card', (els) => els.map((a) => new URL(a.href).searchParams.get('id')));
  await page.click('.video-card >> nth=0');
  await page.waitForSelector('.viewer-stage');
  check('first item has no ‹ but has ›', !(await page.$('#viewer-prev')) && !!(await page.$('#viewer-next')));

  await page.keyboard.press('ArrowRight');
  await page.waitForURL((u) => u.searchParams.get('id') === order[1]);
  check('→ goes to the next item in wall order', idOf() === order[1]);

  await page.keyboard.press('ArrowRight');
  await page.waitForURL((u) => u.searchParams.get('id') === order[2]);
  await page.keyboard.press('ArrowLeft');
  await page.waitForURL((u) => u.searchParams.get('id') === order[1]);
  check('← goes back', idOf() === order[1]);

  await page.click('#viewer-next');
  await page.waitForURL((u) => u.searchParams.get('id') === order[2]);
  check('the › arrow button pages too', idOf() === order[2]);
  await page.screenshot({ path: 'screenshots/mini_viewer.png' });


  await page.keyboard.press('Escape');
  await page.waitForURL(/wall\.php/);
  check('Esc returns to the wall', page.url().includes('wall.php'));

  // A picture page shows the picture, not a play button.
  const pictureHref = await page.$eval('.video-card.picture', (a) => a.href);
  await page.goto(pictureHref);
  const natural = await page.$eval('.player.picture img', (img) => img.complete && img.naturalWidth);
  check(`a picture opens as the picture itself (${natural}px wide)`, natural > 0);
  check('a picture has no play button', !(await page.$('#player-play')));

  // Arrow keys typed into a field must not navigate.
  const before = idOf();
  await page.focus('.search-bar input[name="q"]');
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(500);
  check('arrows inside the search box do not page', idOf() === before);

  await browser.close();
  console.log(failed === 0 ? 'ALL PASS' : `${failed} FAILED`);
  process.exit(failed === 0 ? 0 : 1);
})();
