import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.setDefaultTimeout(10000);
await page.goto('http://127.0.0.1:43123/', { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForSelector('.bh-tab.is-active', { timeout: 30000 });
await page.waitForTimeout(800);
await page.locator('.bh-mat').click({ position: { x: 20, y: 80 } });

const snap = async (label) => {
  const data = await page.evaluate(() => {
    const q = (s) => [...document.querySelectorAll(s)];
    const info = (el) => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return {
        tag: el.tagName,
        dataModal: el.getAttribute('data-modal'),
        openAttr: el.hasAttribute('open'),
        openProp: el.open ?? null,
        ariaModal: el.getAttribute('aria-modal'),
        ariaHidden: el.getAttribute('aria-hidden'),
        role: el.getAttribute('role'),
        hidden: el.hasAttribute('hidden'),
        dataFluxModal: el.hasAttribute('data-flux-modal'),
        classSnippet: String(el.className||'').slice(0,90),
        display: cs.display,
        visibility: cs.visibility,
        w: Math.round(r.width), h: Math.round(r.height),
      };
    };
    return {
      counts: {
        'dialog': q('dialog').length,
        'dialog[open]': q('dialog[open]').length,
        '[data-flux-modal]': q('[data-flux-modal]').length,
        '[data-flux-modal][open]': q('[data-flux-modal][open]').length,
        '[aria-modal="true"]': q('[aria-modal="true"]').length,
        '[aria-modal="true"]:not([aria-hidden="true"])': q('[aria-modal="true"]:not([aria-hidden="true"])').length,
        '[role=dialog]': q('[role=dialog]').length,
        'ui-modal': q('ui-modal').length,
      },
      dialogs: q('dialog').map(info),
      dataFluxModals: q('[data-flux-modal]').map(info),
      roleDialogs: q('[role=dialog]').map(info),
      ariaModals: q('[aria-modal="true"]').map(info),
      bhModalOpen: !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])'),
      oldModalOpen: !!document.querySelector("[data-flux-modal]:not([style*='display: none'])") || !!document.querySelector('[role=dialog]'),
      activeTab: document.querySelector('.bh-tab.is-active')?.textContent?.trim(),
      activeEl: { tag: document.activeElement?.tagName, id: document.activeElement?.id || '' },
    };
  });
  console.log('===', label, '===');
  console.log(JSON.stringify(data, null, 2));
  return data;
};

await snap('initial');

// Meta+k
await page.keyboard.press('Meta+k');
await page.waitForTimeout(1000);
await snap('after Meta+k');

// Escape close
await page.keyboard.press('Escape');
await page.waitForTimeout(600);
await snap('after Escape');

// body focus + ArrowRight
await page.locator('.bh-mat').click({ position: { x: 20, y: 80 } });
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1000);
await snap('after ArrowRight');

// Compare: would OLD selector block?
const verdict = await page.evaluate(() => {
  const old = !!document.querySelector("[data-flux-modal]:not([style*='display: none'])") || !!document.querySelector('[role=dialog]');
  const neu = !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])');
  return { oldWouldBlock: old, newBlocks: neu, roleDialogCount: document.querySelectorAll('[role=dialog]').length, dataFluxModalCount: document.querySelectorAll('[data-flux-modal]').length };
});
console.log('=== VERDICT ===', JSON.stringify(verdict, null, 2));

// Confirm @script listener identity: patch and see
const listenerFires = await page.evaluate(() => {
  return new Promise((resolve) => {
    let hit = false;
    const wrap = (e) => { if (e.key === 'ArrowLeft') hit = true; };
    document.addEventListener('keydown', wrap);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true, cancelable: true }));
    setTimeout(() => {
      document.removeEventListener('keydown', wrap);
      // Check if view changed via Livewire despite synthetic (may not)
      resolve({ captureHit: hit });
    }, 50);
  });
});
console.log('=== listener probe ===', listenerFires);

await browser.close();
