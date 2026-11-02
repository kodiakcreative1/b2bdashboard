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
 await page.goto('http://localhost:8090/wp-admin/plugin-install.php?tab=upload');
 await page.locator('input[name="pluginzip"]').setInputFiles(path.resolve('dist/chamber-b2b-dashboard-v0.1.0.zip'));
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
 console.log('PASS: Uploaded ZIP activated normally.');
 for(const route of ['/dashboard/','/dashboard/transactions/']) {
  const response=await page.goto('http://localhost:8090'+route);
  assert.equal(response.status(),200);
  assert.match(await page.locator('h1').innerText(),/Monday B2B Group/);
  assert.match(await page.locator('.cb2b-notice').innerText(),/not available yet/);
  assert.ok(!(await page.content()).includes('QA Pagination'));
  console.log('PASS: Installed ZIP serves '+route+' with real source defaults and no fixture records.');
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
