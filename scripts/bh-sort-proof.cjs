const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1500);
  await p.evaluate(()=>{Alpine.$data(document.querySelector('.bh-mat')).bhView='positions'; Livewire.first().setView('positions');});
  await p.waitForTimeout(500);
  // click Symbol sort
  await p.locator('[data-bh-page="positions"] .bh-th-sort',{hasText:'Symbol'}).click();
  await p.waitForTimeout(900);
  const syms=await p.evaluate(()=>[...document.querySelectorAll('[data-bh-page="positions"] tbody tr td:first-child div')].slice(0,5).map(e=>e.innerText.trim()));
  console.log("after_symbol_sort", syms);
  await p.locator('[data-bh-page="positions"] .bh-th-sort',{hasText:'P&L'}).click();
  await p.waitForTimeout(900);
  const pnls=await p.evaluate(()=>[...document.querySelectorAll('[data-bh-page="positions"] tbody tr')].slice(0,5).map(tr=>{
    const tds=tr.querySelectorAll('td');
    return {sym:tds[0]?.innerText?.split('\n')[0]?.trim(), pnl:tds[7]?.innerText?.trim()};
  }));
  console.log("after_pnl_sort", pnls);
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
