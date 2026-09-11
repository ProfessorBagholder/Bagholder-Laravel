const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  p.on("pageerror", e=>console.log("PAGEERROR", e.message));
  p.on("console", m=>console.log("CONSOLE", m.type(), m.text()));
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1500);
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(400);
  console.log("before", await p.evaluate(()=>({open:!!document.querySelector('dialog[open]'), active:document.activeElement?.id})));
  await p.keyboard.press("Escape");
  await p.waitForTimeout(1000);
  console.log("afterEsc", await p.evaluate(()=>({open:!!document.querySelector('dialog[open]'), symbol:Livewire.first().form.symbol})));
  // try modal close via livewire
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(400);
  await p.evaluate(async()=>{ await Livewire.first().escapeIdle(); });
  await p.waitForTimeout(1000);
  console.log("afterEscapeIdle", await p.evaluate(()=>({open:!!document.querySelector('dialog[open]')})));
  // flux dispatch close
  await p.keyboard.press("Meta+k");
  await p.waitForTimeout(400);
  await p.evaluate(()=>{
    window.Livewire.dispatch('modal-close', { name: 'filters' });
  });
  await p.waitForTimeout(500);
  console.log("afterDispatch", await p.evaluate(()=>({open:!!document.querySelector('dialog[open]')})));
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
