import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.setDefaultTimeout(15000);

const posts = [];
page.on('request', (req) => {
  if (req.method() === 'POST' && req.url().includes('livewire')) {
    const post = req.postData() || '';
    let calls = [];
    try {
      const j = JSON.parse(post);
      // Livewire 3/4 payload shapes
      const comps = j.components || [];
      for (const c of comps) {
        const calls2 = c.calls || c.update?.calls || [];
        if (Array.isArray(c.calls)) calls.push(...c.calls.map(x => x.method || x));
        // also scan string
      }
      if (post.includes('setView')) calls.push('setView(in-body)');
      if (post.includes('openFilters')) calls.push('openFilters(in-body)');
    } catch {}
    posts.push({ url: req.url().slice(-40), calls, snippet: post.slice(0, 200) });
  }
});

await page.goto('http://127.0.0.1:43123/', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.bh-tab.is-active');
await page.waitForTimeout(1000);

// Instrument bhOnKey by wrapping document listeners after load
await page.evaluate(() => {
  window.__log = [];
  const orig = document.addEventListener;
  // Can't easily wrap existing. Instead monkeypatch $wire via Livewire find
});

async function tab() {
  return page.evaluate(() => document.querySelector('.bh-tab.is-active')?.textContent?.trim());
}

async function wireView() {
  return page.evaluate(() => {
    for (const c of window.Livewire.all()) {
      try {
        const v = c.get('view');
        if (typeof v === 'string' && ['dashboard','trades','positions','cashflow'].includes(v)) {
          return { id: c.id, view: v, connectOpen: c.get('connectOpen') };
        }
      } catch {}
    }
    // fallback: dump
    return { err: true, n: window.Livewire.all().length };
  });
}

await page.locator('.bh-mat').click({ position: { x: 30, y: 100 } });
console.log('start', await tab(), await wireView());

posts.length = 0;
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1200);
console.log('after ArrowRight#1', await tab(), await wireView(), 'posts', posts.length, posts.map(p => p.calls));

posts.length = 0;
await page.keyboard.press('Meta+k');
await page.waitForTimeout(1200);
const modal = await page.evaluate(() => !!document.querySelector('dialog[open]'));
console.log('after Meta+k', { modal, tab: await tab(), wire: await wireView(), posts: posts.map(p => p.calls) });

posts.length = 0;
await page.keyboard.press('Escape');
await page.waitForTimeout(800);
console.log('after Escape', { modal: await page.evaluate(() => !!document.querySelector('dialog[open]')), tab: await tab(), wire: await wireView(), focus: await page.evaluate(() => ({tag: document.activeElement?.tagName, id: document.activeElement?.id})), posts: posts.map(p => p.calls) });

await page.locator('.bh-mat').click({ position: { x: 30, y: 100 } });
await page.waitForTimeout(200);
posts.length = 0;
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1500);
console.log('after ArrowRight#2', await tab(), await wireView(), 'posts', posts.length, posts.map(p => p.calls));

// Check if listener still present by seeing preventDefault / calling path
// Inject a marker into the existing listener chain:
const hook = await page.evaluate(async () => {
  let saw = [];
  document.addEventListener('keydown', (e) => {
    saw.push({ key: e.key, meta: e.metaKey, phase: 'bubble-late', prevented: e.defaultPrevented });
  });
  // Also try direct setView
  let dash = null;
  for (const c of window.Livewire.all()) {
    try {
      if (typeof c.get('view') === 'string' && ['dashboard','trades','positions','cashflow'].includes(c.get('view'))) {
        dash = c; break;
      }
    } catch {}
  }
  const before = dash?.get('view');
  if (dash) await dash.call('setView', 'positions');
  await new Promise(r => setTimeout(r, 1000));
  const after = dash?.get('view');
  return { before, after, dashId: dash?.id, saw };
});
console.log('direct setView', hook);
console.log('tab after direct', await tab());

// Re-test ArrowRight from positions -> cashflow via keyboard
await page.locator('.bh-mat').click({ position: { x: 30, y: 100 } });
posts.length = 0;
await page.keyboard.press('ArrowRight');
await page.waitForTimeout(1200);
console.log('ArrowRight#3 from positions', await tab(), posts.map(p => p.calls));

// Check $wire.view inside a fresh @script-like read
const viewRead = await page.evaluate(() => {
  // Simulate what bhOnKey does - find component and read view
  const pages = ['dashboard', 'trades', 'positions', 'cashflow'];
  let views = [];
  for (const c of window.Livewire.all()) {
    try {
      const v = c.get('view');
      views.push({ id: c.id, v, idx: pages.indexOf(v) });
    } catch (e) {
      views.push({ id: c.id, err: String(e) });
    }
  }
  return views;
});
console.log('all component views', viewRead);

await browser.close();
