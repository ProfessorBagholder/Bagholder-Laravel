const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1500);
  await p.evaluate(()=>{Alpine.$data(document.querySelector('.bh-mat')).bhView='positions'; Livewire.first().setView('positions');});
  await p.waitForTimeout(800);
  const stats=await p.evaluate(()=>{
    const rows=[...document.querySelectorAll('[data-bh-page="positions"] tbody tr')];
    let dashPrice=0, numPrice=0;
    for(const tr of rows){
      const tds=tr.querySelectorAll('td');
      if(tds.length<8) continue;
      const price=(tds[3]?.innerText||'').trim(); // Price col
      if(price==='—'||price==='-') dashPrice++; else if(price) numPrice++;
    }
    return {rows:rows.length, dashPrice, numPrice};
  });
  console.log("positions_table", stats);
  await b.close();
  if(stats.numPrice<1) process.exit(2);
  console.log("PASS");
})().catch(e=>{console.error(e);process.exit(1);});
