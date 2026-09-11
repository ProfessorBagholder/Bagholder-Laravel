const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  const sizes=[];
  p.on("response", async res=>{
    if(res.url().includes("livewire")||res.url().includes("43123")){
      try{
        const ct=res.headers()["content-type"]||"";
        if(ct.includes("json")||res.url().includes("/livewire")){
          const buf=await res.body();
          sizes.push({url:res.url().slice(0,80), status:res.status(), bytes:buf.length, ms: res.request().timing()?.responseEnd});
        }
      }catch{}
    }
  });
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1500);
  sizes.length=0;
  const t0=Date.now();
  await p.evaluate(()=>Livewire.first().setView("trades"));
  await p.waitForTimeout(1000);
  console.log("wall_ms", Date.now()-t0);
  console.log("responses", JSON.stringify(sizes.slice(0,10),null,2));
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
