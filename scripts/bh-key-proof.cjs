const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  await page.locator('.bh-panel').click({ position: { x: 40, y: 80 } });
  const before = (await page.locator('.bh-tab.is-active').innerText()).trim();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(800);
  const mid = (await page.locator('.bh-tab.is-active').innerText()).trim();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(800);
  const mid2 = (await page.locator('.bh-tab.is-active').innerText()).trim();
  await page.keyboard.press('Meta+k');
  await page.waitForTimeout(1000);
  const focusInfo = await page.evaluate(() => {
    const el = document.activeElement;
    return {
      filterOpen: !!document.querySelector('dialog[open]'),
      tag: el && el.tagName,
      id: el && el.id,
      hasSearch: !!document.querySelector('#bh-filter-search'),
    };
  });
  console.log(JSON.stringify({ before, mid, mid2, ...focusInfo }, null, 2));
  if (before !== 'Dashboard' || mid !== 'Trades' || mid2 !== 'Positions' || !focusInfo.filterOpen) {
    process.exit(2);
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
