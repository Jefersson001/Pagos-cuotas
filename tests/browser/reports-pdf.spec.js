import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
let credentials;
test.beforeAll(()=>{credentials=JSON.parse(execFileSync('php',['tests/browser/seed-reports.php'],{env:{...process.env,DB_CONNECTION:process.env.BROWSER_DB_CONNECTION||'mysql',DB_DATABASE:process.env.BROWSER_DB_DATABASE||'ventas_cuotas_browser'},encoding:'utf8',timeout:120000}));});
const types=['sales','active','completed','receipts','balances','overdue','debtors','payments','performance'];

test('todos los reportes producen PDF completos, sin columnas recortadas y según el rol',async({page})=>{
 test.setTimeout(240000);const errors=[];page.on('pageerror',error=>errors.push(error.message));await page.addInitScript(()=>{window.print=()=>{window.__printRequested=true;};});
 const login=async email=>{await page.goto('/login');await page.getByLabel('Correo electrónico').fill(email);await page.getByLabel('Contraseña',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'Ingresar al panel'}).click();await expect(page.getByRole('heading',{name:'Tu espacio de trabajo'})).toBeVisible();};
 const logout=async()=>{await page.goto('/');await page.locator('.workspace-user summary').click();await page.getByRole('button',{name:'Cerrar sesión',exact:true}).click();};
 async function capturePdf(path,name,count,multiple=false){
  await page.goto(path);await expect(page.locator('[data-print-report]')).toBeVisible();await page.waitForFunction(()=>window.__printRequested===true);await page.setViewportSize({width:1047,height:794});await page.emulateMedia({media:'print'});await expect(page.locator('.print-toolbar')).toBeHidden();const rows=page.locator('[data-report-table] tbody tr');await expect(rows).toHaveCount(count);
  expect(await page.evaluate(()=>{const paper=document.querySelector('[data-print-report]').getBoundingClientRect();return [...document.querySelectorAll('th,td')].every(cell=>{const box=cell.getBoundingClientRect();return box.left>=paper.left-1&&box.right<=paper.right+1&&cell.scrollWidth<=cell.clientWidth+1;});})).toBe(true);
  const pdf=await page.pdf({path:`test-results/report-${name}.pdf`,preferCSSPageSize:true,printBackground:true});const raw=pdf.toString('latin1');expect(raw.startsWith('%PDF-')).toBe(true);expect(raw).toContain('%%EOF');expect(pdf.length).toBeGreaterThan(5000);const pageCount=(raw.match(/\/Type\s*\/Page\b/g)||[]).length;expect(pageCount).toBeGreaterThanOrEqual(multiple?2:1);
  if(['admin-payments','head-office','events-admin'].includes(name))await page.screenshot({path:`test-results/report-${name}-print.png`,fullPage:true});await page.emulateMedia({media:'screen'});return pageCount;
 }
 await login(credentials.email);
 for(const type of types){const expected={sales:135,active:45,completed:45,receipts:45,balances:45,overdue:90,debtors:45,payments:135,performance:45}[type];await capturePdf(`/reports?type=${type}&dealership_id=${credentials.company_id}&from=2020-01-01&to=2030-12-31&print=1&page=2`,'admin-'+type,expected,true);}
 await capturePdf(`/sales-events?dealership_id=${credentials.company_id}&from=2020-01-01&to=2030-12-31&print=1&page=2`,'events-admin',46,true);await expect(page.locator('tfoot')).toHaveCount(1);await expect(page.locator('tfoot')).toContainText('6750.00');
 await page.goto(`/reports?type=payments&dealership_id=${credentials.company_id}`);await page.getByRole('textbox',{name:'Cliente',exact:true}).fill('Cliente PDF 001');await expect(page).toHaveURL(/client=Cliente/);const printLink=page.getByRole('link',{name:'Imprimir / PDF',exact:true});await expect(printLink).toHaveAttribute('href',/client=Cliente/);await expect(printLink).toHaveAttribute('href',/type=payments/);await expect(printLink).toHaveAttribute('href',/print=1/);expect(await printLink.getAttribute('href')).not.toContain('export=');
 await logout();await login(credentials.dealer_email);
 for(const type of types){const expected={sales:3,active:1,completed:1,receipts:1,balances:1,overdue:2,debtors:1,payments:3,performance:1}[type];await capturePdf(`/reports?type=${type}&dealership_id=${credentials.company_id}&from=2020-01-01&to=2030-12-31&print=1`,'dealer-'+type,expected);await expect(page.locator('[data-print-report]')).not.toContainText('Cliente PDF 002');}
 await capturePdf('/reports?type=sales&client=Sin-coincidencias-PDF&print=1','empty',1);await expect(page.locator('tbody')).toContainText('Sin resultados para estos filtros.');
 await capturePdf('/sales-events?from=2020-01-01&to=2030-12-31&print=1','events-dealer',46);await expect(page.locator('tfoot')).toContainText('150.00');
 await logout();await login(credentials.head_email);await capturePdf('/reports?from=2020-01-01&to=2030-12-31&print=1&page=2','head-office',45,true);await expect(page.locator('[data-print-report]')).not.toContainText('Cliente PDF');await expect(page.locator('tfoot')).toContainText('6750.00');
 await capturePdf('/sales-events?from=2020-01-01&to=2030-12-31&print=1','events-head',46,true);await expect(page.locator('tfoot')).toContainText('6750.00');expect(errors).toEqual([]);
});