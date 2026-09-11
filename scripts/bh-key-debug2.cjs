const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  page.on('console', msg => console.log('CONSOLE', msg.text()));
  page.on('pageerror', err => console.log('PAGEERROR', err.message));
  await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);
  const r1 = await page.evaluate(async () => {
    const w = Livewire.first();
    const before = w.view;
    await w.setView('trades');
    await new Promise(r => setTimeout(r, 800));
    return { before, after: w.view, active: document.querySelector('.bh-tab.is-active')?.textContent?.trim() };
  });
  console.log('setView call', JSON.stringify(r1));
  await page.evaluate(() => {
    // install probe
    window.__arrows = 0;
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') window.__arrows++;
    });
  });
  await page.locator('.bh-wordmark').click();
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(300);
  const arrows = await page.evaluate(() => window.__arrows);
  console.log('raw keydown received', arrows);
  // Check if Livewire component scripts registered listeners by calling from $wire scope dump
  const scriptRan = await page.evaluate(() => {
    // Livewire stores effects; look for our comment string in any livewire snapshot effects
    return JSON.stringify(Livewire.all().map(w => ({ name: w.__instance?.()?.name, id: w.$id })).slice(0,5));
  });
  console.log('components', scriptRan);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
