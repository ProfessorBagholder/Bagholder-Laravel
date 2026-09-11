import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle', timeout: 60000 });
await page.waitForTimeout(1000);
await page.locator('body').click({ position: { x: 5, y: 5 } });

const dumpDialogs = async (label) => {
  const d = await page.evaluate(() => {
    const all = [...document.querySelectorAll('dialog, [data-flux-modal], [role=dialog], [aria-modal]')];
    return all.map(el => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return {
        tag: el.tagName,
        dataModal: el.getAttribute('data-modal'),
        open: el.hasAttribute('open'),
        openProp: el.open ?? null,
        ariaModal: el.getAttribute('aria-modal'),
        ariaHidden: el.getAttribute('aria-hidden'),
        role: el.getAttribute('role'),
        dataFluxModal: el.hasAttribute('data-flux-modal'),
        classSnippet: (el.className||'').toString().slice(0,100),
        display: cs.display,
        visibility: cs.visibility,
        w: r.width, h: r.height,
      };
    });
  });
  console.log('===', label, '===');
  console.log(JSON.stringify(d, null, 2));
};

await dumpDialogs('initial');

// Open filters via trigger click
await page.locator('[data-flux-modal-trigger] button[aria-label="Filters"]').click();
await page.waitForTimeout(500);
await dumpDialogs('filters open via trigger');

// Close with Escape
await page.keyboard.press('Escape');
await page.waitForTimeout(500);
await dumpDialogs('after Escape close');

// Check bhModalOpen and counts
const afterClose = await page.evaluate(() => {
  const q = (s) => document.querySelectorAll(s).length;
  return {
    counts: {
      'dialog': q('dialog'),
      'dialog[open]': q('dialog[open]'),
      '[data-flux-modal]': q('[data-flux-modal]'),
      '[data-flux-modal][open]': q('[data-flux-modal][open]'),
      '[aria-modal="true"]': q('[aria-modal="true"]'),
      '[aria-modal="true"]:not([aria-hidden="true"])': q('[aria-modal="true"]:not([aria-hidden="true"])'),
      '[role=dialog]': q('[role=dialog]'),
      'ui-modal': q('ui-modal'),
    },
    bhModalOpen: !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])'),
    active: { tag: document.activeElement?.tagName, id: document.activeElement?.id },
  };
});
console.log('=== AFTER CLOSE COUNTS ===');
console.log(JSON.stringify(afterClose, null, 2));

// Now ArrowRight should work
await page.locator('body').click({ position: { x: 5, y: 5 } });
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1000);
const tab = await page.evaluate(() => document.querySelector('.bh-tab.is-active')?.textContent?.trim());
console.log('active tab after ArrowRight:', tab);

// Inspect fluxModal handleShow and event name in page scripts / alpine
const fluxInfo = await page.evaluate(() => {
  // Look at dialog alpine listeners
  const dialogs = [...document.querySelectorAll('dialog')];
  return dialogs.map(d => ({
    dataModal: d.getAttribute('data-modal'),
    attrs: [...d.attributes].map(a => a.name + '=' + a.value.slice(0,80)),
  }));
});
console.log('=== DIALOG ATTRS ===');
console.log(JSON.stringify(fluxInfo, null, 2));

// Check if @script registered: count document keydown listeners via getEventListeners if available (Chrome only)
const listenerInfo = await page.evaluate(() => {
  // Monkey: dispatch and see if $wire methods get called by temporarily patching
  return { livewireVersion: window.Livewire?.version ?? '?', alpine: !!window.Alpine };
});
console.log('=== RUNTIME ===', JSON.stringify(listenerInfo));

// Open menu dropdown — does it leave aria-modal?
await page.locator('button[aria-label="Menu"]').click();
await page.waitForTimeout(300);
const menuState = await page.evaluate(() => {
  const q = (s) => document.querySelectorAll(s).length;
  return {
    counts: {
      '[aria-modal="true"]': q('[aria-modal="true"]'),
      '[role=menu]': q('[role=menu]'),
      '[role=dialog]': q('[role=dialog]'),
      'dialog[open]': q('dialog[open]'),
    },
    bhModalOpen: !!document.querySelector('dialog[open], [data-flux-modal][open], [aria-modal="true"]:not([aria-hidden="true"])'),
  };
});
console.log('=== MENU OPEN ===', JSON.stringify(menuState, null, 2));
await page.keyboard.press('Escape');
await page.waitForTimeout(200);

// Check connectOpen modal wire:model
const connect = await page.evaluate(() => {
  let dash = null;
  for (const c of window.Livewire.all()) {
    try {
      if (c.get('connectOpen') !== undefined && c.get('view') !== undefined) { dash = c; break; }
    } catch {}
  }
  if (!dash) {
    // dump props
    return { err: 'no dash', comps: window.Livewire.all().map(c => {
      try { return { id: c.id, keys: Object.keys(c.__instance?.ephemeral ?? c.canonical ?? {}).slice(0,20) }; } catch(e) { return { id: c.id, e: String(e) }; }
    })};
  }
  return {
    connectOpen: dash.get('connectOpen'),
    capturing: dash.get('capturing'),
    view: dash.get('view'),
    filtersOpen: dash.get('filtersOpen'),
  };
});
console.log('=== DASH STATE ===', JSON.stringify(connect, null, 2));

await browser.close();
