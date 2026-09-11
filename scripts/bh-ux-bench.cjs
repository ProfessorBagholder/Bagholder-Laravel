const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(2000);
  await p.locator(".bh-mat").click({position:{x:20,y:20}});
  // measure setView via performance
  const ms=await p.evaluate(async()=>{
    const times=[];
    for(let i=0;i<3;i++){
      const t0=performance.now();
      await Livewire.first().setView(i%2===0?'trades':'dashboard');
      // wait for morph - livewire finishes when commit done
      await new Promise(r=>setTimeout(r,50));
      // wait until view property matches
      const target=i%2===0?'trades':'dashboard';
      for(let k=0;k<100;k++){
        if(Livewire.first().view===target && document.querySelector('.bh-tab.is-active')?.innerText?.trim()?.toLowerCase().includes(target==='trades'?'trades':'dashboard')) break;
        await new Promise(r=>setTimeout(r,20));
      }
      times.push(Math.round(performance.now()-t0));
      await new Promise(r=>setTimeout(r,200));
    }
    return times;
  });
  console.log("setView_ms", ms);
  // Meta+K focus
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(400);
  const focus=await p.evaluate(()=>{
    const el=document.getElementById("bh-filter-search");
    return {open:!!document.querySelector("dialog[open]"), activeId:document.activeElement?.id, isSearch:document.activeElement===el};
  });
  console.log("metaK_focus", focus);
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
