const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage();
 for(const path of ['/dashboard/','/dashboard/transactions/']) {
  const r=await page.goto('http://localhost:8088'+path);
  assert.equal(r.status(),200);
  assert.match(await page.locator('.cb2b-notice').textContent(),/not available yet/);
  assert.equal(await page.locator('.cb2b-transactions').count(),0);
  assert.ok(!(await page.content()).includes('QA Pagination'));
  console.log('PASS: Empty first-sync state with no fabricated records '+path);
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
