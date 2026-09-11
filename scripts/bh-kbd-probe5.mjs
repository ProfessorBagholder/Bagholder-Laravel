import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

page.on('request', async (req) => {
  if (req.method() === 'POST' && req.url().includes('livewire')) {
    const post = req.postData() || '';
    // extract setView args
    const m = post.match(/setView[\s\S]{0,80}/g);
    const m2 = post.match(/"method":"setView"[^,]*"params":\[[^\]]*\]/);
    console.log('LW POST setView bits:', m2 || m || post.includes('setView'));
    if (post.includes('setView')) {
      // find params near setView
      const i = post.indexOf('setView');
      console.log('context:', post.slice(Math.max(0,i-40), i+120));
    }
  }
});

await page.goto('http://127.0.0.1:43123/', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);

// Patch: wrap document keydown to log what $wire.view looks like by hijacking after scripts load
// Inject evaluation of Livewire component $wire-like API
const api = await page.evaluate(() => {
  const comps = window.Livewire.all();
  return comps.map((c) => {
    const out = { id: c.id, keys: Object.keys(c).slice(0, 30) };
    try { out.entangle = typeof c.entangle; } catch {}
    try { out.getType = typeof c.get; } catch {}
    try { out.$get = typeof c.$get; } catch {}
    try { out.__instance = !!c.__instance; } catch {}
    // Livewire 4 $wire proxy
    try {
      const w = c.$wire || c;
      out.viewViaDot = w.view;
      out.viewType = typeof w.view;
    } catch (e) { out.viewErr = String(e); }
    try { out.snapshot = c.snapshot?.data || c.canonical || null; } catch {}
    try {
      // jsondump ephemeral
      out.ephemeral = c.__instance?.ephemeral;
      out.reactive = c.__instance?.reactive;
    } catch {}
    return out;
  });
});
console.log('API', JSON.stringify(api, null, 2));

// Install logger inside page that runs on keydown and reads $wire from Livewire.find of dashboard
await page.evaluate(() => {
  window.__vlog = [];
  document.addEventListener('keydown', () => {
    // try several APIs
    const rows = [];
    for (const c of window.Livewire.all()) {
      const row = { id: c.id };
      try { row['c.view'] = c.view; } catch (e) { row['c.view'] = String(e); }
      try { row['c.get'] = typeof c.get === 'function' ? c.get('view') : 'no get'; } catch (e) { row['c.get'] = String(e); }
      try { row['$wire.view'] = c.$wire?.view; } catch (e) { row['$wire.view'] = String(e); }
      try {
        const snap = JSON.parse(c.snapshot);
        row.snapView = snap?.data?.view;
      } catch (e) {
        try { row.snapRaw = typeof c.snapshot; row.snapView2 = c.snapshot?.data?.view; } catch {}
      }
      rows.push(row);
    }
    window.__vlog.push(rows);
  }, true);
});

await page.locator('.bh-mat').click({ position: { x: 20, y: 90 } });
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1500);

const vlog = await page.evaluate(() => window.__vlog);
console.log('vlog', JSON.stringify(vlog, null, 2));
console.log('active tab', await page.evaluate(() => document.querySelector('.bh-tab.is-active')?.textContent?.trim()));

// Check is-active classes and wire snapshot in DOM
const dom = await page.evaluate(() => {
  const root = document.querySelector('.bh-mat');
  const wid = root?.closest('[wire\\:id]')?.getAttribute('wire:id') || root?.getAttribute('wire:id');
  const el = document.querySelector(`[wire\\:id="${wid}"]`) || document.querySelector('[wire\\:id]');
  return {
    wid,
    tabs: [...document.querySelectorAll('.bh-tab')].map(t => ({ t: t.textContent.trim(), a: t.classList.contains('is-active'), aria: t.getAttribute('aria-current') })),
    snapshotAttr: el?.getAttribute('wire:snapshot')?.slice(0, 300),
  };
});
console.log('dom', JSON.stringify(dom, null, 2));

await browser.close();
