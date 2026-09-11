const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  const errors=[];
  p.on("pageerror", e=>errors.push(e.message));
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1800);
  await p.locator(".bh-mat").click({position:{x:20,y:20}});

  const arrow=[];
  for (const [view, tab, page] of [['trades','Trades','trades-body'],['positions','Positions','positions'],['cashflow','Cashflow','cashflow']]) {
    const t0=await p.evaluate(()=>performance.now());
    await p.keyboard.press("ArrowRight");
    const ms=await p.evaluate(async ({t0, view, tab, page})=>{
      for (let i=0;i<200;i++){
        const v=Alpine.$data(document.querySelector('.bh-mat')).bhView;
        const t=document.querySelector('.bh-tab.is-active')?.innerText?.trim();
        const el=document.querySelector(`[data-bh-page="${page}"]`);
        if (v===view && t===tab && el && getComputedStyle(el).display!=='none') return Math.round(performance.now()-t0);
        await new Promise(r=>setTimeout(r,0));
      }
      return -1;
    }, {t0, view, tab, page});
    arrow.push({view, ms});
  }
  console.log("arrow_ms", JSON.stringify(arrow));

  await p.evaluate(()=>{Alpine.$data(document.querySelector('.bh-mat')).bhView='dashboard'; Livewire.first().setView('dashboard');});
  await p.waitForTimeout(150);

  errors.length=0;
  await p.keyboard.press("Meta+k");
  await p.waitForFunction(()=>document.activeElement?.id==='bh-filter-search' && !!document.querySelector('dialog[open]'), null, {timeout:3000});
  console.log("metaK", await p.evaluate(()=>({id:document.activeElement.id, open:!!document.querySelector('dialog[open]')})));

  // Esc closes flyout
  await p.keyboard.press("Escape");
  await p.waitForFunction(()=>!document.querySelector('dialog[open]'), null, {timeout:3000});
  console.log("esc_closes_flyout", true);

  // Activate filter with real symbol via setFilter (in options)
  errors.length=0;
  await p.evaluate(async ()=>{ await Livewire.first().setFilter('symbol', 'AMD'); });
  await p.waitForTimeout(700);
  const before=await p.evaluate(()=>Livewire.first().form.symbol);
  const optErr=errors.filter(e=>/Could not find option/i.test(e));
  console.log("filter_active", {before, optErr});

  // Esc with flyout closed should clear
  await p.locator('.bh-mat').click({position:{x:20,y:20}});
  await p.keyboard.press("Escape");
  await p.waitForFunction(()=>Livewire.first().form.symbol==='', null, {timeout:5000});
  console.log("esc_resets_idle", await p.evaluate(()=>Livewire.first().form.symbol));

  // Open flyout, set filter, Esc closes + clears
  await p.evaluate(async ()=>{ await Livewire.first().setFilter('symbol', 'AMD'); });
  await p.waitForTimeout(500);
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(400);
  const mid=await p.evaluate(()=>({open:!!document.querySelector('dialog[open]'), symbol:Livewire.first().form.symbol}));
  await p.keyboard.press("Escape");
  await p.waitForTimeout(900);
  const after=await p.evaluate(()=>({open:!!document.querySelector('dialog[open]'), symbol:Livewire.first().form.symbol}));
  console.log("esc_open_clears", {mid, after, errors});

  await b.close();
  if (arrow.some(a=>a.ms<0 || a.ms>100)) process.exit(2);
  if (before!=='AMD' || optErr.length) process.exit(5);
  if (after.open || after.symbol) process.exit(4);
  console.log("SHIP_PASS", {arrow, before, after});
})().catch(e=>{console.error(e);process.exit(1);});
