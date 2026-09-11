import { chromium } from 'playwright';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

page.on('request', (req) => {
  if (req.method()==='POST' && req.url().includes('livewire')) {
    const p = req.postData()||'';
    const methods = [...p.matchAll(/"method":"([^"]+)"/g)].map(m=>m[1]);
    const params = [...p.matchAll(/"method":"([^"]+)","params":(\[[^\]]*?\])/g)].map(m=>`${m[1]}${m[2]}`);
    console.log('POST', params.length ? params : methods);
  }
});

await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await page.locator('.bh-mat').click({ position:{x:20,y:90} });

const tab = async () => page.evaluate(() => document.querySelector('.bh-tab.is-active')?.textContent?.trim());
const state = async () => page.evaluate(() => {
  const q = s => document.querySelectorAll(s).length;
  return {
    tab: document.querySelector('.bh-tab.is-active')?.textContent?.trim(),
    dialogOpen: q('dialog[open]'),
    bhModalOpen: !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])'),
    oldModalOpen: !!document.querySelector("[data-flux-modal]:not([style*='display: none'])") || !!document.querySelector('[role=dialog]'),
    dataFluxModal: q('[data-flux-modal]'),
    roleDialog: q('[role=dialog]'),
    ariaModalTrue: q('[aria-modal="true"]'),
    ariaModalVisible: q('[aria-modal="true"]:not([aria-hidden="true"])'),
    focus: { tag: document.activeElement?.tagName, id: document.activeElement?.id||'' },
    wireView: (()=>{ for (const c of Livewire.all()) { try { if (typeof c.$wire?.view === 'string') return c.$wire.view; } catch{} } return null; })(),
  };
});

console.log('T0', await state());
await page.keyboard.press('Meta+k');
await page.waitForTimeout(1000);
console.log('T1 Meta+k', await state());
await page.keyboard.press('Escape');
await page.waitForTimeout(800);
console.log('T2 Escape', await state());
await page.locator('.bh-mat').click({ position:{x:20,y:90} });
await page.waitForTimeout(200);
console.log('T3 refocus', await state());
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1200);
console.log('T4 ArrowRight', await state());

// Also: simulate OLD selector effect
console.log('OLD would permanently block arrows because data-flux-modal ui-modal is display:inline:', 
  await page.evaluate(() => {
    return [...document.querySelectorAll('[data-flux-modal]')].map(el => ({
      tag: el.tagName,
      display: getComputedStyle(el).display,
      styleAttr: el.getAttribute('style'),
      matchesOld: el.matches("[data-flux-modal]:not([style*='display: none'])"),
    }));
  })
);

await browser.close();
