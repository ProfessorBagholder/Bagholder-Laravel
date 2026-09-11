const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  p.on("pageerror", e=>console.log("PAGEERROR", e.message));
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(2000);
  await p.locator(".bh-mat").click({position:{x:20,y:20}});

  const measureArrow=async(expectView, expectTab)=>{
    const t0=await p.evaluate(()=>performance.now());
    await p.keyboard.press("ArrowRight");
    const res=await p.evaluate(async ({t0, expectView, expectTab})=>{
      for(let k=0;k<200;k++){
        const v=Alpine.$data(document.querySelector('.bh-mat')).bhView;
        const tab=document.querySelector('.bh-tab.is-active')?.innerText?.trim();
        const el=document.querySelector(`[data-bh-page="${expectView==='trades'?'trades-body':expectView}"]`);
        const visible=el && getComputedStyle(el).display!=='none';
        if(v===expectView && tab===expectTab && visible){
          return {ms:Math.round(performance.now()-t0), view:v, tab, visible:true};
        }
        await new Promise(r=>setTimeout(r,0));
      }
      return {ms:Math.round(performance.now()-t0), fail:true, view:Alpine.$data(document.querySelector('.bh-mat')).bhView};
    }, {t0, expectView, expectTab});
    return res;
  };

  const a1=await measureArrow('trades','Trades');
  const a2=await measureArrow('positions','Positions');
  const a3=await measureArrow('cashflow','Cashflow');
  console.log("arrows", JSON.stringify({a1,a2,a3}));

  // back to dashboard via Alpine+wire
  await p.evaluate(()=>{Alpine.$data(document.querySelector('.bh-mat')).bhView='dashboard'; return Livewire.first().setView('dashboard');});
  await p.waitForTimeout(200);

  await p.keyboard.press("Meta+k");
  await p.waitForFunction(()=>document.activeElement?.id==='bh-filter-search', null, {timeout:3000});
  const focus=await p.evaluate(()=>({open:!!document.querySelector('dialog[open]'), id:document.activeElement?.id}));
  console.log("metaK", focus);

  // activate a real filter via parent API
  await p.keyboard.press("Escape");
  await p.waitForTimeout(300);
  await p.evaluate(async()=>{ await Livewire.first().set('form.symbol','AAPL'); });
  await p.waitForTimeout(500);
  // ignore flux option errors — symbol string set
  await p.locator('.bh-mat').click({position:{x:20,y:20}});
  const before=await p.evaluate(()=>Livewire.first().form.symbol);
  await p.keyboard.press("Escape");
  await p.waitForFunction(()=>!Livewire.first().form.symbol, null, {timeout:5000}).catch(()=>{});
  const after=await p.evaluate(()=>({symbol:Livewire.first().form.symbol, open:!!document.querySelector('dialog[open]')}));
  console.log("esc_reset", {before, after});

  await b.close();
  const worst=Math.max(a1.ms,a2.ms,a3.ms);
  if(a1.fail||a2.fail||a3.fail) process.exit(2);
  if(focus.id!=='bh-filter-search') process.exit(3);
  if(after.symbol) process.exit(4);
  console.log("PASS", {worstArrowMs:worst});
})().catch(e=>{console.error(e);process.exit(1);});
