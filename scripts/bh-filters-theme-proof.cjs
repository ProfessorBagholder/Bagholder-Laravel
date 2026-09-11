const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(1200);
  await p.evaluate(async()=>{
    document.documentElement.setAttribute('data-bh-theme','midnight');
    localStorage.setItem('bh2.theme','midnight');
    await Livewire.first().setTheme('midnight');
  });
  let t=await p.evaluate(()=>({attr:document.documentElement.getAttribute('data-bh-theme'), mat:getComputedStyle(document.documentElement).getPropertyValue('--bh-mat').trim()}));
  console.log('midnight', t);
  if(t.attr!=='midnight') process.exit(2);
  await p.evaluate(async()=>{
    document.documentElement.setAttribute('data-bh-theme','light');
    localStorage.setItem('bh2.theme','light');
    await Livewire.first().setTheme('light');
  });
  t=await p.evaluate(()=>({attr:document.documentElement.getAttribute('data-bh-theme'), mat:getComputedStyle(document.documentElement).getPropertyValue('--bh-mat').trim()}));
  console.log('light', t);
  if(t.attr!=='light') process.exit(3);

  await p.evaluate(async()=>{
    Alpine.$data(document.querySelector('.bh-mat')).bhView='trades';
    await Livewire.first().setView('trades');
    await Livewire.first().$set('tab','closed');
    await Livewire.first().setFilter('kind','Options');
  });
  await p.waitForTimeout(1500);
  const opts=await p.evaluate(()=>{
    const rows=[...document.querySelectorAll('[data-bh-page="trades-body"] tbody tr')].filter(tr=>tr.querySelectorAll('td').length>=10);
    return {n:rows.length, sample:rows.slice(0,4).map(tr=>tr.querySelector('td:nth-child(3)')?.innerText?.split('\n')[0]?.trim()), kind:Livewire.first().form.kind};
  });
  console.log('options', opts);
  await p.keyboard.press('Meta+k');
  await p.waitForTimeout(400);
  const labels=await p.evaluate(()=>[...document.querySelectorAll('dialog[open] label, dialog[open] [data-flux-label]')].map(e=>e.innerText.trim()));
  console.log('labels', labels.filter(Boolean));
  await b.close();
  if(opts.kind!=='Options'||opts.n<1) process.exit(4);
  if(!labels.some(l=>/Grade/i.test(l))||!labels.some(l=>/Result/i.test(l))) process.exit(5);
  console.log('PASS');
})().catch(e=>{console.error(e);process.exit(1);});
