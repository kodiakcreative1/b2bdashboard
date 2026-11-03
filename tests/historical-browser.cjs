const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
(async()=>{
 const root=process.env.CB2B_BASE_URL||'http://localhost:8088';
 assert.ok(['localhost','127.0.0.1'].includes(new URL(root).hostname));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 const page=await context.newPage(); const errors=[]; let n=0;
 page.on('pageerror',e=>errors.push(e.message));
 const check=(ok,label)=>{assert.ok(ok,label);n++;console.log('PASS: '+label)};
 for(const route of ['/dashboard/','/dashboard/transactions/','/dashboard/historical/']){
  const r=await page.goto(root+route); check(r.status()===200,'Route '+route+' returns HTTP 200');
  const nav=page.getByRole('navigation',{name:'Dashboard navigation'});
  check(JSON.stringify(await nav.locator('a').allTextContents())===JSON.stringify(['Overview','Transactions','Historical']),'Three-way navigation on '+route);
  await nav.getByRole('link',{name:'Historical',exact:true}).click();
  check(new URL(page.url()).pathname==='/dashboard/historical/','Historical navigation resolves from '+route);
 }
 check(await page.locator('.cb2b-metric').count()===4,'Exactly four historical summary cards');
 check(JSON.stringify(await page.locator('.cb2b-metric h2').allTextContents())===JSON.stringify(['Historical Closed Business','Through 2024 Total','2025 Closed Business','Group Established']),'Historical summary labels match release requirements');
 check(JSON.stringify(await page.locator('.cb2b-metric p').allTextContents())===JSON.stringify(['$2,798,073.90','$1,868,857.90','$929,216.00','2021']),'Monday historical dollar totals and starting year');
 check(JSON.stringify(await page.locator('.cb2b-month-card .cb2b-month-value').allTextContents())===JSON.stringify(['$1,868,857.90','$929,216.00','$2,798,073.90']),'Historical breakdown cards reconcile');
 check(await page.locator('.cb2b-transactions').count()===0 && await page.locator('.cb2b-year-form').count()===0,'No current transaction list or year filter on historical page');
 check(await page.getByRole('link',{name:'Historical',exact:true}).getAttribute('aria-current')==='page','Historical navigation active state');
 check(!(await page.content()).includes('COLUMN_D_PRIVATE_QA'),'No submitter email in historical HTML');
 fs.mkdirSync('artifacts',{recursive:true});await page.screenshot({path:'artifacts/historical-desktop.png',fullPage:true});
 for(const width of [768,390,320]){
  await page.setViewportSize({width,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Historical layout fits '+width+'px viewport');
  check(await page.locator('.cb2b-metric').count()===4,'Four historical summary cards retained at '+width+'px');
 }
 await page.screenshot({path:'artifacts/historical-mobile.png',fullPage:true});
 for(const route of ['/dashboard/','/dashboard/transactions/']){
  await page.goto(root+route);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Existing route fits 320px with three-link navigation: '+route);
 }
 await page.goto(root+'/dashboard/');
 check((await page.locator('.cb2b-metric p').first().innerText())==='$662,367.00','Historical amounts never inflate current transaction totals');
 let r=await context.request.post(root+'/wp-admin/admin-post.php',{form:{action:'cb2b_historical_refresh'}});
 check([400,403].includes(r.status()),'Logged-out historical refresh denied');
 await page.goto(root+'/wp-login.php');await page.locator('#user_login').fill('qa-admin');await page.locator('#user_pass').fill('Local-Only-QA-Password-2026!');await page.locator('#wp-submit').click();
 const admin=root+'/wp-admin/options-general.php?page=chamber-b2b';await page.goto(admin);
 await page.getByRole('heading',{name:'Historical sync diagnostics'}).waitFor();
 check(await page.getByRole('heading',{name:'Historical sync diagnostics'}).count()===1,'Independent historical admin diagnostics visible');
 r=await context.request.post(root+'/wp-admin/admin-post.php',{form:{action:'cb2b_historical_refresh'}});
 check(r.status()===403,'Historical refresh requires nonce even for administrator');
 const original=await page.locator('form:has(input[value="cb2b_save"]) input[name], form:has(input[value="cb2b_save"]) select[name]').evaluateAll(nodes=>Object.fromEntries(nodes.filter(n=>n.type!=='checkbox'||n.checked).map(n=>[n.name,n.value])));
 try {
  for(const [tab,gid,grand,start] of [['Tuesday','395825866','$15,381,459.69','2014'],['Thursday','125109628','$1,643,994.70','2016']]) {
   await page.goto(admin);
   const nonce=await page.locator('form:has(input[value="cb2b_save"]) input[name="_wpnonce"]').inputValue();
   r=await context.request.post(root+'/wp-admin/admin-post.php',{form:{...original,_wpnonce:nonce,'groups[0][tab]':tab,'groups[0][name]':tab+' B2B Group','groups[0][gid]':gid}});
   check(r.ok(),tab+' group configuration saved on local QA site');
   await page.goto(root+'/dashboard/historical/');
   const values=await page.locator('.cb2b-metric p').allTextContents();
   check(values[0]===grand&&values[3]===start,tab+' historical display maps to currently configured group');
  }
 } finally {
  await page.goto(admin);const nonce=await page.locator('form:has(input[value="cb2b_save"]) input[name="_wpnonce"]').inputValue();
  await context.request.post(root+'/wp-admin/admin-post.php',{form:{...original,_wpnonce:nonce}});
 }
 check(errors.length===0,'No historical JavaScript page errors');
 console.log('\n'+n+' historical browser assertions passed.');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
