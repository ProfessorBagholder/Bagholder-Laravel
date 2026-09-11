const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  page.on('console', msg => console.log('CONSOLE', msg.type(), msg.text()));
  await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  const info = await page.evaluate(() => {
    const scripts = [...document.querySelectorAll('script')].map(s => (s.textContent||'').slice(0,80));
    const hasBh = scripts.some(s => s.includes('bhDialogOpen') || s.includes('setView'));
    let wireView = null, wireId = null;
    try {
      const w = window.Livewire && window.Livewire.first && window.Livewire.first();
      wireId = w && w.$id;
      wireView = w && w.view;
    } catch (e) { wireView = String(e); }
    return { hasBh, wireId, wireView, scriptHits: scripts.filter(s => s.includes('Arrow') || s.includes('bhDialog') || s.includes('setView')).length };
  });
  console.log(JSON.stringify(info, null, 2));
  await page.locator('.bh-wordmark').click();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(1000);
  const after = await page.locator('.bh-tab.is-active').innerText();
  console.log('after', after.trim());
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
