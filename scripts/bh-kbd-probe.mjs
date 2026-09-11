import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

const livewireReqs = [];
page.on('request', (req) => {
  const u = req.url();
  if (u.includes('/livewire') || req.method() === 'POST' && u.includes('livewire')) {
    livewireReqs.push({ method: req.method(), url: u.slice(0, 120) });
  }
});

await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle', timeout: 60000 });
await page.waitForTimeout(1500);

// Click body so focus is not in an input
await page.locator('body').click({ position: { x: 10, y: 10 } });
await page.waitForTimeout(200);

const probe = await page.evaluate(() => {
  const q = (s) => document.querySelectorAll(s).length;
  const counts = {
    'dialog[open]': q('dialog[open]'),
    '[data-flux-modal][open]': q('[data-flux-modal][open]'),
    '[aria-modal="true"]': q('[aria-modal="true"]'),
    '[aria-modal="true"]:not([aria-hidden="true"])': q('[aria-modal="true"]:not([aria-hidden="true"])'),
    '[role=dialog]': q('[role=dialog]'),
  };

  const ariaModals = [...document.querySelectorAll('[aria-modal="true"]')].map((el) => {
    const style = getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const visible = style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0 && style.opacity !== '0';
    return {
      tagName: el.tagName,
      open: el.hasAttribute('open') ? el.getAttribute('open') : null,
      hasOpenAttr: el.hasAttribute('open'),
      ariaHidden: el.getAttribute('aria-hidden'),
      dataFluxModal: el.hasAttribute('data-flux-modal') || el.closest('[data-flux-modal]')?.tagName || null,
      dataModal: el.getAttribute('data-modal'),
      classSnippet: (el.className || '').toString().slice(0, 120),
      visible,
      role: el.getAttribute('role'),
    };
  });

  // Check if bhOnKey style listener exists by wrapping addEventListener history — instead flag via probe marker
  const active = document.activeElement ? {
    tag: document.activeElement.tagName,
    id: document.activeElement.id,
    type: document.activeElement.getAttribute?.('type'),
  } : null;

  // Does Livewire @script appear loaded?
  const hasWire = !!document.querySelector('[wire\\:id], [wire\\:snapshot]');
  const wireIds = [...document.querySelectorAll('[wire\\:id]')].map(e => e.getAttribute('wire:id')).slice(0, 10);

  // Simulate what bhModalOpen does
  const bhModalOpen = !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])');

  return { counts, ariaModals, active, hasWire, wireIds, bhModalOpen };
});

console.log('=== INITIAL PROBE ===');
console.log(JSON.stringify(probe, null, 2));

// Instrument: wrap to see if keydown reaches our handler path
await page.evaluate(() => {
  window.__bhKeys = [];
  window.__bhLw = [];
  document.addEventListener('keydown', (e) => {
    window.__bhKeys.push({ key: e.key, meta: e.metaKey, ctrl: e.ctrlKey, defaultPrevented: e.defaultPrevented, target: e.target?.tagName });
  }, true);
  // Hook Livewire message bus if present
  if (window.Livewire) {
    window.Livewire.hook('request', ({ options }) => {
      window.__bhLw.push({ type: 'request', uri: options?.uri });
    });
  }
});

const activeBefore = await page.evaluate(() => ({
  viewActive: document.querySelector('.bh-tab.is-active')?.textContent?.trim(),
  viewTabs: [...document.querySelectorAll('.bh-tab')].map(t => ({ text: t.textContent.trim(), active: t.classList.contains('is-active') })),
}));
console.log('=== TABS BEFORE ArrowRight ===');
console.log(JSON.stringify(activeBefore, null, 2));

livewireReqs.length = 0;
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1500);

const afterArrow = await page.evaluate(() => ({
  viewActive: document.querySelector('.bh-tab.is-active')?.textContent?.trim(),
  viewTabs: [...document.querySelectorAll('.bh-tab')].map(t => ({ text: t.textContent.trim(), active: t.classList.contains('is-active') })),
  keys: window.__bhKeys,
  bhModalOpen: !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])'),
  dialogOpen: !!document.querySelector('dialog[open]'),
  lw: window.__bhLw,
}));
console.log('=== AFTER ArrowRight ===');
console.log(JSON.stringify({ afterArrow, livewireReqs }, null, 2));

// Reset key log
await page.evaluate(() => { window.__bhKeys = []; window.__bhLw = []; });
livewireReqs.length = 0;

// Meta+k on Mac; Playwright uses Meta+k
await page.keyboard.press('Meta+k');
await page.waitForTimeout(1500);

const afterMetaK = await page.evaluate(() => {
  const q = (s) => document.querySelectorAll(s).length;
  return {
    keys: window.__bhKeys,
    lw: window.__bhLw,
    counts: {
      'dialog[open]': q('dialog[open]'),
      '[data-flux-modal][open]': q('[data-flux-modal][open]'),
      '[aria-modal="true"]': q('[aria-modal="true"]'),
      '[aria-modal="true"]:not([aria-hidden="true"])': q('[aria-modal="true"]:not([aria-hidden="true"])'),
      '[role=dialog]': q('[role=dialog]'),
    },
    dialogOpen: [...document.querySelectorAll('dialog[open]')].map(d => ({
      dataModal: d.getAttribute('data-modal'),
      classSnippet: (d.className||'').toString().slice(0,80),
    })),
    filterSearchVisible: (() => {
      const el = document.getElementById('bh-filter-search');
      if (!el) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    })(),
    filterSearchFocused: document.activeElement?.id === 'bh-filter-search',
  };
});
console.log('=== AFTER Meta+k ===');
console.log(JSON.stringify({ afterMetaK, livewireReqs }, null, 2));

// Also try Control+k
await page.evaluate(() => { window.__bhKeys = []; });
livewireReqs.length = 0;
await page.keyboard.press('Control+k');
await page.waitForTimeout(1500);
const afterCtrlK = await page.evaluate(() => ({
  keys: window.__bhKeys,
  dialogOpenCount: document.querySelectorAll('dialog[open]').length,
  filterSearchVisible: (() => {
    const el = document.getElementById('bh-filter-search');
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  })(),
}));
console.log('=== AFTER Control+k ===');
console.log(JSON.stringify({ afterCtrlK, livewireReqs }, null, 2));

// Check if @script listener is actually registered by seeing preventDefault on ArrowRight when bhModalOpen is false
// Also dump whether calling openFilters / setView via Livewire works
const wireCall = await page.evaluate(async () => {
  const root = document.querySelector('[wire\\:id]');
  if (!root || !window.Livewire) return { err: 'no livewire' };
  const id = root.getAttribute('wire:id');
  const comp = window.Livewire.find(id);
  const before = comp?.get?.('view') ?? comp?.__instance?.view;
  // Try find dashboard component by checking view property
  let dash = null;
  for (const c of window.Livewire.all()) {
    try {
      const v = c.get('view');
      if (typeof v === 'string') { dash = c; break; }
    } catch {}
  }
  if (!dash) return { err: 'no dashboard component', ids: window.Livewire.all().map(c => c.id) };
  const viewBefore = dash.get('view');
  await dash.call('setView', 'trades');
  await new Promise(r => setTimeout(r, 800));
  const viewAfterCall = dash.get('view');
  await dash.call('openFilters');
  await new Promise(r => setTimeout(r, 800));
  const dialogOpen = !!document.querySelector('dialog[open]');
  const dialogNames = [...document.querySelectorAll('dialog[open]')].map(d => d.getAttribute('data-modal'));
  return { viewBefore, viewAfterCall, dialogOpen, dialogNames, dashId: dash.id };
});
console.log('=== DIRECT $wire CALLS ===');
console.log(JSON.stringify(wireCall, null, 2));

// Dispatch synthetic events like the task asked
await page.evaluate(() => { window.__bhKeys = []; });
const synth = await page.evaluate(async () => {
  const beforeView = document.querySelector('.bh-tab.is-active')?.textContent?.trim();
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
  await new Promise(r => setTimeout(r, 1000));
  const afterView = document.querySelector('.bh-tab.is-active')?.textContent?.trim();
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true }));
  await new Promise(r => setTimeout(r, 1000));
  return {
    beforeView,
    afterView,
    dialogOpen: !!document.querySelector('dialog[open]'),
    keysSeen: window.__bhKeys,
  };
});
console.log('=== SYNTHETIC KeyboardEvent ===');
console.log(JSON.stringify(synth, null, 2));

await browser.close();
