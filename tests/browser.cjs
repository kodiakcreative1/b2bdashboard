/* Uses only the isolated local WordPress site and synthetic QA data. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
 const browser = await chromium.launch({headless:true, executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args:['--no-sandbox']});
 const context = await browser.newContext({viewport:{width:1440,height:1100}});
 const page = await context.newPage(); const errors = [];
 page.on('pageerror', e=>errors.push(e.message));
 const root = process.env.CB2B_BASE_URL || 'http://localhost:8088';
 assert.ok(['localhost','127.0.0.1'].includes(new URL(root).hostname), 'This runner is restricted to local QA hosts');
 let passed = 0;
 function check(condition, label) { assert.ok(condition, label); console.log('PASS: '+label); passed++; }
 async function monthlyCards(year) {
   const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
   const cards = page.locator('.cb2b-month-card');
   check(await cards.count() === 12, year+' has exactly twelve month cards');
   for (let i=0;i<12;i++) {
     const dollars = year===2026 && i===9 ? '$662,367.00' : year===2025 && i===0 ? '$1.25' : '$0.00';
     const count = year===2026 && i===9 ? '28 transactions' : year===2025 && i===0 ? '1 transaction' : '0 transactions';
     const card=cards.nth(i);
     check(await card.locator('h3').innerText()===months[i] && await card.locator('.cb2b-month-value').innerText()===dollars && await card.locator('.cb2b-month-count').innerText()===count, year+' '+months[i]+' correct total and count');
   }
 }
 async function lastRowBorders(label) {
   const cells = await page.locator('.cb2b-transactions tbody tr:last-child td').evaluateAll(nodes => nodes.map(node => {
     const s=getComputedStyle(node);
     return ['Top','Right','Bottom','Left'].every(side=>s['border'+side+'Style']==='solid' && parseFloat(s['border'+side+'Width'])>=1);
   }));
   check(cells.length===6 && cells.every(Boolean), label+' final row keeps all four borders on all six cells');
 }
 async function visit(path) { const r = await page.goto(root+path); check(r.status() === 200, 'HTTP 200 '+path); assert.ok(!(await page.content()).includes('COLUMN_D_PRIVATE_QA'), 'Column D absent from '+path); }
 await visit('/dashboard/');
 check(await page.locator('.cb2b-metric').count() === 4, 'Exactly four headline cards');
 check(await page.locator('.cb2b-month-card').count() === 12, 'Twelve monthly cards');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 5, 'Five recent transactions');
 check(!(await page.content()).includes('COLUMN_D_PRIVATE_QA'), 'No private column D in logged-out HTML');
 check(await page.locator('header').count() === 1 && await page.locator('footer').count() === 1, 'Standalone document with one plugin header/footer');
 check(await page.locator('title').textContent() === 'Closed Business Dashboard · Monday B2B Group', 'Page title');
 await monthlyCards(2026);
 await lastRowBorders('recent transactions');
 fs.mkdirSync('artifacts',{recursive:true});
 await page.screenshot({path:'artifacts/qa-desktop-synthetic.png',fullPage:true});
 await page.getByRole('link',{name:'View All Transactions'}).click();
 check(new URL(page.url()).pathname === '/dashboard/transactions/', 'View all stays on plugin transaction route');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 20, 'Twenty rows on first list page');
 await lastRowBorders('full transactions page 1');
 await page.getByRole('link',{name:'Next →'}).click();
 check(await page.locator('.cb2b-transactions tbody tr').count() === 8, 'Pagination remainder and matching year count');
 await lastRowBorders('full transactions last page');
 await visit('/dashboard/transactions/?report_year=2026&search=needle');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 20, 'Search matching details paginates');
 await visit('/dashboard/transactions/?report_year=2026&search=QA%20Gamma');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 1, 'Search filters names');
 await visit('/dashboard/transactions/?report_year=2026&business=QA%20Business%202');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 2, 'Business filter');
 await visit('/dashboard/transactions/?report_year=2026&sort=amount&order=asc');
 check((await page.locator('.cb2b-amount').first().textContent()).trim() === '$7.00', 'Amount sort is numeric');
 await visit('/dashboard/?report_year=2025');
 check((await page.locator('.cb2b-metric p').first().textContent()).trim() === '$1.25', 'Overview metrics respect reporting year');
 check(await page.locator('.cb2b-transactions tbody tr').count() === 1, 'Recent list respects reporting year');
 await monthlyCards(2025);
 await visit('/dashboard/?report_year=2024');
 await monthlyCards(2024);
 await visit('/dashboard/transactions/?report_year=2026&from=2026-10-27&to=2026-10-26');
 check(await page.getByRole('alert').count() === 1 && await page.locator('.cb2b-transactions').count() === 0, 'Invalid range gets useful error');
 await visit('/dashboard/transactions/?report_year[]=2026&search[]=x&pg[]=2');
 check(await page.locator('h1').count() === 1, 'Array query parameters cannot crash page');
 await visit('/dashboard/transactions/?search=%3Cscript%3Ewindow.QA_XSS%3D1%3C%2Fscript%3E');
 check(await page.evaluate(()=>!window.QA_XSS), 'Search input is escaped');
 await visit('/dashboard/transactions/?search=Quoted');
 await page.locator('.cb2b-notes summary').first().click();
 check(await page.locator('.cb2b-notes details[open] p').first().isVisible(), 'Full long notes expand accessibly');
 await page.setViewportSize({width:390,height:844});
 await visit('/dashboard/');
 check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), 'Mobile overview has no viewport overflow');
 await lastRowBorders('mobile recent transactions');
 await page.screenshot({path:'artifacts/qa-mobile-synthetic.png',fullPage:true});
 await page.getByRole('link',{name:'View All Transactions'}).click();
 check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), 'Mobile table scroll is contained');
 await lastRowBorders('mobile full transactions');
 await page.screenshot({path:'artifacts/qa-mobile-list-synthetic.png',fullPage:true});
 // WordPress action security: no external service is contacted by these denied requests.
 let r = await context.request.post(root+'/wp-admin/admin-post.php', {form:{action:'cb2b_refresh'}});
 check([400,403].includes(r.status()), 'Logged-out refresh denied');
 await page.goto(root+'/wp-login.php');
 await page.locator('#user_login').fill('qa-admin'); await page.locator('#user_pass').fill('Local-Only-QA-Password-2026!');
 await page.locator('#wp-submit').click();
 await page.goto(root+'/wp-admin/options-general.php?page=chamber-b2b');
 check(await page.getByRole('heading',{name:'Chamber B2B Dashboard',exact:true}).count() === 1, 'Admin settings page loads');
 await page.getByRole('button',{name:'Choose image',exact:true}).click();
 check(await page.locator('.media-modal').isVisible(), 'WordPress logo media picker opens');
 await page.locator('.media-modal-close').click();
 r = await context.request.post(root+'/wp-admin/admin-post.php', {form:{action:'cb2b_refresh'}});
 check(r.status() === 403, 'Authenticated refresh without nonce denied');
 r = await context.request.post(root+'/wp-admin/admin-post.php', {form:{action:'cb2b_save',spreadsheet:'invalid'}});
 check(r.status() === 403, 'Authenticated save without nonce denied');
 await page.locator('#interval').selectOption('15');
 await page.getByRole('button',{name:'Save settings',exact:true}).click();
 check(await page.locator('#interval').inputValue() === '15', 'Nonce-protected admin settings persist');
 await page.locator('#interval').selectOption('1'); await page.getByRole('button',{name:'Save settings',exact:true}).click();
 check(await page.locator('#interval').inputValue() === '1', 'Default frequency restored');
 await page.locator('#primary').fill('#231f20');
 await page.locator('#accent').fill('#006266');
 await page.getByRole('button',{name:'Save settings',exact:true}).click();
 await page.goto(root+'/dashboard/');
 check(await page.locator('.cb2b-header').evaluate(el => getComputedStyle(el).backgroundColor) === 'rgb(35, 31, 32)', 'Configured header color renders');
 check(await page.locator('.cb2b-year-form button').evaluate(el => getComputedStyle(el).backgroundColor) === 'rgb(0, 98, 102)', 'Configured accent color renders');
 await page.goto(root+'/wp-admin/options-general.php?page=chamber-b2b');
 await page.locator('#primary').fill('#153c55');
 await page.locator('#accent').fill('#007f82');
 await page.getByRole('button',{name:'Save settings',exact:true}).click();
 const originalSheet = await page.locator('#spreadsheet').inputValue();
 await page.locator('#spreadsheet').fill('https://example.invalid/private');
 await page.getByRole('button',{name:'Save settings',exact:true}).click();
 check(await page.locator('#spreadsheet').inputValue() === originalSheet && await page.locator('.notice-error').count() === 1, 'Arbitrary source URL rejected without changing settings');
 check(errors.length === 0, 'No JavaScript page errors'+(errors.length ? ': '+errors.join(', ') : ''));
 console.log(`\n${passed} browser assertions passed.`);
 await browser.close();
})().catch(e=>{ console.error(e); process.exit(1); });
