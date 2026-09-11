const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  p.on("pageerror", e=>console.log("PAGEERROR", e.message));
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(2000);
  await p.locator(".bh-mat").click({position:{x:24,y:24}});
  await p.waitForTimeout(200);
  const before=(await p.locator(".bh-tab.is-active").innerText()).trim();
  await p.keyboard.press("ArrowRight");
  await p.waitForTimeout(400);
  const after=(await p.locator(".bh-tab.is-active").innerText()).trim();
  const view=await p.evaluate(()=>Alpine.$data(document.querySelector(".bh-mat")).bhView);
  console.log("arrow", {before, after, view});
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(500);
  const k=await p.evaluate(()=>({
    open:!!document.querySelector("dialog[open]"),
    activeId:document.activeElement?.id||"",
    isSearch:document.activeElement?.id==="bh-filter-search",
  }));
  console.log("metaK", k);
  // also Ctrl+k
  await p.keyboard.press("Escape");
  await p.waitForTimeout(300);
  await p.keyboard.press("Control+k");
  await p.waitForTimeout(500);
  const ctrl=await p.evaluate(()=>({
    open:!!document.querySelector("dialog[open]"),
    activeId:document.activeElement?.id||"",
  }));
  console.log("ctrlK", ctrl);
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
