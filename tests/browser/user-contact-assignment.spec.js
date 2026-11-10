import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';

let credentials;
test.beforeEach(()=>{credentials=JSON.parse(execFileSync('php',['tests/browser/seed.php'],{env:{...process.env,DB_CONNECTION:process.env.BROWSER_DB_CONNECTION||'mysql',DB_DATABASE:process.env.BROWSER_DB_DATABASE||'ventas_cuotas_browser'},encoding:'utf8'}));});

test('crear usuario genera su contacto y permite completar la ficha después',async({page})=>{
 test.setTimeout(120000);const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto('/login');await page.getByLabel('Correo electrónico').fill(credentials.email);await page.getByLabel('Contraseña',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'Ingresar al panel'}).click();await expect(page.getByRole('heading',{name:'Tu espacio de trabajo'})).toBeVisible();
 const create=async(role,name,username,email,parent)=>{
  await page.goto('/users/create');await page.getByRole('combobox',{name:'Rol',exact:true}).selectOption({label:role});await expect(page.getByRole('checkbox',{name:'Usar un contacto existente',exact:true})).not.toBeChecked();
  if(parent){await page.getByRole('combobox',{name:'Concesionario asignado',exact:true}).selectOption({label:parent});await expect(page.getByRole('combobox',{name:'Ficha del cliente',exact:true})).toHaveCount(0);}else{await expect(page.getByRole('combobox',{name:'Concesionario asignado',exact:true})).toHaveCount(0);await expect(page.getByRole('combobox',{name:'Empresa asignada',exact:true})).not.toHaveValue('');}
  await page.getByLabel('Nombre y apellido',{exact:true}).fill(name);await page.getByLabel('Nombre de usuario',{exact:true}).fill(username);await page.getByLabel('Correo de acceso',{exact:true}).fill(email);await page.getByLabel('Contraseña',{exact:true}).fill('Automatic-password-12345');await page.screenshot({path:'test-results/automatic-contact-'+username+'.png',fullPage:true});await page.getByRole('button',{name:'Guardar usuario',exact:true}).click();await expect(page.getByText('Usuario guardado.',{exact:true})).toBeVisible();
 };
 await create('Concesionario','Concesionario automático','automatic_dealer','automatic-dealer@example.test');
 const dealerId=await page.getByRole('combobox',{name:'Concesionario asignado',exact:true}).inputValue();await expect(page.getByRole('combobox',{name:'Concesionario asignado',exact:true}).locator('option:checked')).toHaveText('Concesionario automático');
 await page.goto('/clientes/'+dealerId+'/edit');await expect(page.locator('[name=email]')).toHaveValue('automatic-dealer@example.test');await expect(page.locator('[name=customer_kind]')).toHaveValue('dealership');await page.locator('[name=phone]').fill('04121234567');await page.getByRole('button',{name:/^Guardar (cliente|concesionario)$/,exact:true}).click();await expect(page.getByRole('heading',{name:'Concesionario automático',exact:true})).toBeVisible();
 await create('Cliente','Cliente automático','automatic_customer','automatic-customer@example.test','Concesionario automático');
 const clientId=await page.getByRole('combobox',{name:'Ficha del cliente',exact:true}).inputValue();await expect(page.getByRole('combobox',{name:'Concesionario asignado',exact:true})).toHaveValue(dealerId);await page.goto('/clientes/'+clientId+'/edit');await expect(page.locator('[name=email]')).toHaveValue('automatic-customer@example.test');await expect(page.locator('[name=customer_kind]')).toHaveValue('person');await expect(page.getByRole('combobox',{name:'Concesionario',exact:true})).toHaveValue(dealerId);expect(errors).toEqual([]);
});

test('usuario Cliente selecciona un concesionario creado en Clientes y su ficha relacionada',async({page})=>{
 test.setTimeout(120000);const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto('/login');await page.getByLabel('Correo electrónico').fill(credentials.email);await page.getByLabel('Contraseña',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'Ingresar al panel'}).click();await expect(page.getByRole('heading',{name:'Tu espacio de trabajo'})).toBeVisible();

 const contact=async(name,type,parent)=>{
  await page.goto('/clientes/create');await page.getByRole('combobox',{name:'Tipo',exact:true}).selectOption(type);
  await page.getByLabel(type==='dealership'?'Cliente / Razón social':'Nombre y apellido',{exact:true}).fill(name);
  if(parent)await page.getByRole('combobox',{name:'Concesionario',exact:true}).selectOption({label:parent});
  await page.getByRole('button',{name:/^Guardar (cliente|concesionario)$/,exact:true}).click();await expect(page.getByRole('heading',{name,exact:true})).toBeVisible();
 };
 await contact('Concesionario creado en Clientes','dealership');
 await contact('Otro concesionario de Clientes','dealership');
 await contact('Cliente vinculado','person','Concesionario creado en Clientes');
 await contact('Cliente del otro concesionario','person','Otro concesionario de Clientes');

 await page.goto('/users/create');await page.getByRole('combobox',{name:'Rol',exact:true}).selectOption({label:'Concesionario'});await page.getByRole('checkbox',{name:'Usar un contacto existente',exact:true}).check();
 const dealerContact=page.getByRole('combobox',{name:'Concesionario asignado',exact:true});
 await expect(dealerContact.locator('option')).toHaveText(['Selecciona el concesionario','Concesionario creado en Clientes','Otro concesionario de Clientes']);
 await expect(page.locator('select[name=dealership_id]')).toBeDisabled();
 await dealerContact.selectOption({label:'Concesionario creado en Clientes'});
 await page.getByLabel('Nombre y apellido',{exact:true}).fill('Acceso del concesionario');await page.getByLabel('Nombre de usuario',{exact:true}).fill('assigned_dealer');await page.getByLabel('Correo de acceso',{exact:true}).fill('assigned-dealer@example.test');await page.getByLabel('Contraseña',{exact:true}).fill('Assigned-password-12345');
 await page.screenshot({path:'test-results/user-dealer-contact-assignment.png',fullPage:true});await page.getByRole('button',{name:'Guardar usuario',exact:true}).click();await expect(page.getByText('Usuario guardado.',{exact:true})).toBeVisible();await expect(dealerContact.locator('option:checked')).toHaveText('Concesionario creado en Clientes');
 await dealerContact.selectOption({label:'Otro concesionario de Clientes'});await page.getByRole('button',{name:'Guardar usuario',exact:true}).click();await expect(page.getByText('Usuario guardado.',{exact:true})).toBeVisible();await expect(dealerContact.locator('option:checked')).toHaveText('Otro concesionario de Clientes');

 await page.goto('/users/create');await page.getByRole('combobox',{name:'Rol',exact:true}).selectOption({label:'Cliente'});await page.getByRole('checkbox',{name:'Usar un contacto existente',exact:true}).check();
 const concessionaire=page.getByRole('combobox',{name:'Concesionario asignado',exact:true});
 const client=page.getByRole('combobox',{name:'Ficha del cliente',exact:true});
 await expect(concessionaire.locator('option')).toHaveText(['Selecciona el concesionario','Concesionario creado en Clientes','Otro concesionario de Clientes']);
 await expect(client).toBeDisabled();await expect(client.locator('option')).toHaveText(['Primero selecciona el concesionario']);
 await concessionaire.selectOption({label:'Concesionario creado en Clientes'});await expect(client.locator('option')).toHaveText(['Selecciona el cliente','Cliente vinculado']);await expect(client).toHaveValue('');
 await client.selectOption({label:'Cliente vinculado'});
 await concessionaire.selectOption('');await expect(client).toBeDisabled();await expect(client).toHaveValue('');await expect(client.locator('option')).toHaveText(['Primero selecciona el concesionario']);
 await concessionaire.selectOption({label:'Otro concesionario de Clientes'});await expect(client).toHaveValue('');await expect(client.locator('option')).toHaveText(['Selecciona el cliente','Cliente del otro concesionario']);
 await concessionaire.selectOption({label:'Concesionario creado en Clientes'});await client.selectOption({label:'Cliente vinculado'});
 await client.selectOption('');await expect(concessionaire.locator('option:checked')).toHaveText('Concesionario creado en Clientes');await expect(client.locator('option')).toHaveText(['Selecciona el cliente','Cliente vinculado']);await client.selectOption({label:'Cliente vinculado'});
 await page.getByLabel('Nombre y apellido',{exact:true}).fill('Acceso de Cliente vinculado');await page.getByLabel('Nombre de usuario',{exact:true}).fill('assigned_customer');await page.getByLabel('Correo de acceso',{exact:true}).fill('assigned-customer@example.test');await page.getByLabel('Contraseña',{exact:true}).fill('Assigned-password-12345');
 await page.screenshot({path:'test-results/user-contact-assignment-desktop.png',fullPage:true});await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);await page.screenshot({path:'test-results/user-contact-assignment-mobile.png',fullPage:true});
 await page.getByRole('button',{name:'Guardar usuario',exact:true}).click();await expect(page.getByText('Usuario guardado.',{exact:true})).toBeVisible();await expect(concessionaire.locator('option:checked')).toHaveText('Concesionario creado en Clientes');await expect(client.locator('option:checked')).toHaveText('Cliente vinculado');

 await page.locator('.workspace-user summary').click();await page.getByRole('button',{name:'Cerrar sesión',exact:true}).click();await page.getByLabel('Correo electrónico').fill('assigned-customer@example.test');await page.getByLabel('Contraseña',{exact:true}).fill('Assigned-password-12345');await page.getByRole('button',{name:'Ingresar al panel'}).click();await expect(page.getByRole('heading',{name:'Mi estado de cuenta',exact:true})).toBeVisible();await expect(page.locator('.customer-overview-period')).toContainText('Cliente vinculado');await expect(page.getByText('Cliente del otro concesionario',{exact:false})).toHaveCount(0);expect(errors).toEqual([]);
});
