import { chromium } from 'playwright';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const reqs = [];
page.on('request', r => { if (r.url().includes('livewire')) reqs.push({ m:r.method(), u:r.url().split('/').pop(), d:(r.postData()||'').includes('openFilters')?'openFilters':(r.postData()||'').includes('setView')?'setView':'other' }); });
page.on('console', m => console.log('CONSOLE', m.type(), m.text()));
await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1000);
await page.evaluate(() => {
  document.addEventListener('keydown', (e) => {
    if (e.key.toLowerCase()==='k' && (e.metaKey||e.ctrlKey)) {
      console.log('keydown Meta+k seen, defaultPrevented=', e.defaultPrevented);
    }
  }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key.toLowerCase()==='k' && (e.metaKey||e.ctrlKey)) {
      console.log('keydown Meta+k bubble, defaultPrevented=', e.defaultPrevented);
    }
  });
  document.addEventListener('modal-show', (e) => console.log('modal-show event', JSON.stringify(e.detail)), true);
});
await page.locator('.bh-mat').click({ position:{x:20,y:90} });
reqs.length = 0;
await page.keyboard.press('Meta+k');
await page.waitForTimeout(1500);
console.log('reqs after Meta+k', reqs);
console.log('dialog', await page.evaluate(() => ({
  open: [...document.querySelectorAll('dialog[open]')].map(d=>d.getAttribute('data-modal')),
  filterVisible: !!(document.getElementById('bh-filter-search')?.offsetParent || (document.getElementById('bh-filter-search')?.getBoundingClientRect().width>0)),
})));

// Compare: call $wire.openFilters explicitly
reqs.length = 0;
await page.evaluate(async () => {
  const c = Livewire.all().find(c => typeof c.$wire?.view === 'string');
  console.log('calling openFilters on', c?.id);
  await c.$wire.openFilters();
});
await page.waitForTimeout(1000);
console.log('reqs after explicit openFilters', reqs);

await browser.close();
