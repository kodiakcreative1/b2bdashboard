const { chromium }=require('playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage();
 page.setDefaultNavigationTimeout(60000);
 await page.goto('http://localhost:8090/wp-login.php');
 await page.locator('#user_login').fill('qa-admin'); await page.locator('#user_pass').fill('Local-Only-QA-Password-2026!');
 await page.locator('#wp-submit').click();
 await page.goto('http://localhost:8090/wp-admin/options-general.php?page=chamber-b2b');
 const settings = () => page.locator('form:has(input[name="action"][value="cb2b_save"])').locator('input,select').evaluateAll(nodes=>nodes.filter(n=>n.name && !n.name.startsWith('_')).map(n=>[n.name,n.type==='checkbox'?n.checked:n.value]));
 const before = await settings();
 assert.ok(before.length > 10, 'Existing settings fields captured before upgrade');
 await page.goto('http://localhost:8090/wp-admin/plugin-install.php?tab=upload');
 await page.locator('input[name="pluginzip"]').setInputFiles(path.resolve('dist/chamber-b2b-dashboard-v0.1.2.zip'));
 await page.getByRole('button',{name:'Install Now',exact:true}).click();
 await page.waitForFunction(() => /Plugin installed successfully|Plugin updated successfully|Replace current with uploaded|Installation failed/.test(document.body?.innerText || ''), null, {timeout:60000});
 // WordPress offers this when revalidating an already-installed release ZIP.
 const replace=page.getByRole('link',{name:'Replace current with uploaded'});
 if(await replace.count()) { await replace.click(); await page.waitForFunction(() => /Plugin installed successfully|Plugin updated successfully|Installation failed/.test(document.body?.innerText || ''), null, {timeout:60000}); }
 assert.match(await page.locator('body').innerText(),/Plugin installed successfully|Plugin updated successfully/);
 console.log('PASS: Normal WordPress ZIP upload and extraction succeeded.');
 const activate=page.getByRole('link',{name:'Activate Plugin',exact:true});
 if(await activate.count()) await activate.click();
 await page.goto('http://localhost:8090/wp-admin/plugins.php');
 assert.ok(await page.locator('tr[data-slug="chamber-b2b-dashboard"].active').count());
 assert.match(await page.locator('tr[data-slug="chamber-b2b-dashboard"]').innerText(), /Version 0\.1\.2/);
 console.log('PASS: Uploaded v0.1.2 ZIP activated normally.');
 await page.goto('http://localhost:8090/wp-admin/options-general.php?page=chamber-b2b');
 assert.deepEqual(await settings(), before);
 console.log('PASS: All existing plugin setting fields preserved across ZIP upgrade.');
 for(const route of ['/dashboard/','/dashboard/transactions/','/dashboard/historical/']) {
  const response=await page.goto('http://localhost:8090'+route);
  assert.equal(response.status(),200);
  assert.match(await page.locator('h1').innerText(),/Monday B2B Group/);
  assert.match(await page.locator('.cb2b-notice').innerText(),/not available/);
  assert.ok(!(await page.content()).includes('QA Pagination'));
  if(route==='/dashboard/') {
   const cards=page.locator('.cb2b-month-card');
   assert.equal(await cards.count(),12);
   assert.deepEqual(await cards.locator('.cb2b-month-value').allTextContents(),Array(12).fill('$0.00'));
   assert.deepEqual(await cards.locator('.cb2b-month-count').allTextContents(),Array(12).fill('0 transactions'));
   console.log('PASS: Installed ZIP renders twelve zero-dollar/zero-count cards before first sync.');
  }
  console.log('PASS: Installed ZIP serves '+route+' with real source defaults and no fixture records.');
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
