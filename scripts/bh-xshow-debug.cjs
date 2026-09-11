const {chromium}=require("playwright");
(async()=>{
  const b=await chromium.launch({headless:true});
  const p=await b.newPage({viewport:{width:1440,height:900}});
  await p.goto("http://127.0.0.1:43123/",{waitUntil:"networkidle"});
  await p.waitForTimeout(2000);
  const snap=async(label)=>{
    const s=await p.evaluate(()=>{
      const pages=[...document.querySelectorAll('[data-bh-page]')].map(el=>({
        page:el.dataset.bhPage,
        cloak:el.hasAttribute('x-cloak'),
        show:el.getAttribute('x-show'),
        display:getComputedStyle(el).display,
        style:el.getAttribute('style')?.slice(0,80),
      }));
      return {bhView:Alpine.$data(document.querySelector('.bh-mat')).bhView, pages};
    });
    console.log(label, JSON.stringify(s,null,2));
  };
  await snap('initial');
  await p.keyboard.press('ArrowRight');
  await p.waitForTimeout(50);
  await snap('after ArrowRight');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1);});
