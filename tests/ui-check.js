/**
 * Браузерна перевірка інтерфейсу (Playwright) на мок-даних.
 *   1) php -S 127.0.0.1:9001 tests/mock-freeserp.php
 *   2) AI_RADAR_API_URL=http://127.0.0.1:9001/ php -S 127.0.0.1:8000 -t public public/index.php
 *   3) npm i -D playwright && npx playwright install chromium && node tests/ui-check.js
 */
const { chromium } = require('playwright');
const assert = require('assert');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('dialog', (d) => { errors.push('dialog: ' + d.message()); d.dismiss(); });

  await page.goto(BASE + '/');
  await page.waitForSelector('.air-card');
  assert.strictEqual(await page.$$eval('.air-card', (n) => n.length), 24, 'перша сторінка — 24 картки');
  assert.ok(await page.$eval('.air-card__summary', (e) => e.textContent.includes('<script>')), 'HTML із даних показується як текст');

  await page.click('#air-more');
  await page.waitForFunction(() => document.querySelectorAll('.air-card').length > 24);

  await page.click('.air-chip[data-niche="Video Generation"]');
  await page.waitForFunction(() => location.search.includes('niche='));
  assert.strictEqual(await page.$eval('#air-niche', (s) => s.value), 'Video Generation', 'чип ніші виставляє фільтр');

  const boxes = await page.$$('.air-card__compare input');
  await boxes[0].check();
  await boxes[1].check();
  await page.click('#air-compare-open');
  await page.waitForSelector('.air-compare');
  assert.strictEqual(await page.$$eval('.air-compare thead th', (n) => n.length - 1), 2, 'порівняння двох сайтів');
  await page.keyboard.press('Escape');

  await page.goto(BASE + '/?stack=lovable&sort=dr');
  await page.waitForSelector('.air-card');
  assert.strictEqual(await page.$eval('#air-stack', (s) => s.value), 'lovable', 'фільтри відновлюються з URL');

  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await mobile.goto(BASE + '/');
  await mobile.waitForSelector('.air-card');
  assert.strictEqual(await mobile.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 0, 'без горизонтального скролу на телефоні');

  assert.deepStrictEqual(errors, [], 'без JS-помилок і діалогів');
  console.log('OK: усі перевірки пройдено');
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
