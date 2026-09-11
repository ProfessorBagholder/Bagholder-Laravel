const {chromium}=require("playwright");
(async()=>{
  const cases=[
    ["cold-no-click", async p => {}],
    ["click-bh-mat", async p => { await p.locator(".bh-mat").click({position:{x:20,y:20}}); }],
    ["click-bh-panel", async p => { await p.locator(".bh-panel").click({position:{x:40,y:80}}); }],
    ["click-active-tab", async p => { await p.locator(".bh-tab.is-active").click(); }],
    ["escape-then-mat", async p => { await p.keyboard.press("Escape"); await p.locator(".bh-mat").click({position:{x:20,y:20}}); }],
  ];
  for (const [name, prep] of cases) {
    const b=await chromium.launch({headless:true});
    const p=await b.newPage({viewport:{width:1440,height:900}});
    let err=null; p.on("pageerror", e=>{err=e.message});
    await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
    await p.waitForTimeout(1500);
    await prep(p);
    await p.waitForTimeout(200);
    const before=await p.evaluate(()=>({view:Livewire.first().view, tab:document.querySelector(".bh-tab.is-active")?.innerText?.trim(), active:document.activeElement?.tagName+"."+(document.activeElement?.className||"").toString().slice(0,40), dialog:!!document.querySelector("dialog[open]")}));
    await p.keyboard.press("ArrowRight");
    await p.waitForTimeout(900);
    await p.keyboard.press("ArrowRight");
    await p.waitForTimeout(900);
    await p.keyboard.press("ArrowLeft");
    await p.waitForTimeout(900);
    const after=await p.evaluate(()=>({view:Livewire.first().view, tab:document.querySelector(".bh-tab.is-active")?.innerText?.trim()}));
    // expect Dashboard→Trades→Positions→Trades
    const ok = after.view==="trades" && after.tab==="Trades";
    console.log(name, JSON.stringify({before, after, ok, err}));
    if (!ok) process.exitCode=2;
    await b.close();
  }
  // Meta+K once
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1200);
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(700);
  const open=await p.evaluate(()=>!!document.querySelector("dialog[open]"));
  console.log("metaK", open);
  if (!open) process.exitCode=3;
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
