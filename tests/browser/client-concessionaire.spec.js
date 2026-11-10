import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';

let credentials;
test.beforeAll(()=>{credentials=JSON.parse(execFileSync('php',['tests/browser/seed.php'],{env:{...process.env,DB_CONNECTION:'mysql',DB_DATABASE:'ventas_cuotas_browser'},encoding:'utf8'}));});

test('cliente con concesionario de Contactos y asignación automática por usuario',async({page})=>{
 test.setTimeout(180000);const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const login=async(email,password)=>{await page.goto('/login');await page.getByLabel('Correo electrónico').fill(email);await page.getByLabel('Contraseña',{exact:true}).fill(password);await page.getByRole('button',{name:'Ingresar al panel'}).click();await expect(page.getByRole('heading',{name:'Tu espacio de trabajo'})).toBeVisible();};
 await login(credentials.email,credentials.password);

 await page.goto('/settings/dealerships/create');
 await page.getByLabel('Nombre de la organización').fill('Empresa de la prueba');
 await page.getByLabel('Código',{exact:true}).fill('CONTACT-TEST');

 await page.getByRole('button',{name:'Guardar organización',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Editar organización',exact:true})).toBeVisible();
 const companyId=page.url().split('/').at(-2);

 await page.goto('/clientes/create?dealership_id='+companyId);
 await page.getByRole('combobox',{name:'Tipo',exact:true}).selectOption('dealership');
 await expect(page.getByRole('combobox',{name:'Concesionario',exact:true})).toBeHidden();
 await page.getByLabel('Cliente / Razón social').fill('Concesionario de Contactos');
 await page.getByLabel('RIF',{exact:true}).fill('J-CONTACT-001');
 await page.getByRole('button',{name:'Guardar cliente',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Concesionario de Contactos',exact:true})).toBeVisible();

 await page.goto('/clientes/create');
 await page.getByLabel('Nombre y apellido').fill('Cliente elegido por administrador');
 await page.getByRole('combobox',{name:'Concesionario',exact:true}).selectOption({label:'Concesionario de Contactos · J-CONTACT-001'});
 await page.screenshot({path:'test-results/client-concessionaire-admin.png',fullPage:true});
 await page.getByRole('button',{name:'Guardar cliente',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Cliente elegido por administrador',exact:true})).toBeVisible();
 await expect(page.locator('[data-concessionaire-details]')).toContainText('Concesionario de Contactos');

 await page.goto('/clientes');
 await expect(page.locator('.workspace-navigation nav').getByRole('link',{name:'Concesionarios',exact:true})).toHaveCount(0);
 await expect(page.locator('[data-client-kind]')).toHaveCount(2);
 await page.getByRole('combobox',{name:'Tipo',exact:true}).selectOption('dealership');
 await expect(page.locator('[data-client-kind]')).toHaveCount(1);
 await expect(page.locator('[data-client-kind]')).toHaveAttribute('data-client-kind','dealership');
 await page.getByRole('combobox',{name:'Tipo',exact:true}).selectOption('person');
 await expect(page.locator('[data-client-kind]')).toHaveCount(1);
 await expect(page.locator('[data-client-kind]')).toHaveAttribute('data-client-kind','person');
 await page.getByRole('combobox',{name:'Tipo',exact:true}).selectOption('');
 await expect(page.locator('[data-client-kind]')).toHaveCount(2);
 await page.screenshot({path:'test-results/unified-clients-desktop.png',fullPage:true});

 const password='Contact-user-password-12345';
 await page.goto('/users/create');
 await page.getByLabel('Nombre y apellido').fill('Usuario del concesionario');
 await page.getByLabel('Nombre de usuario',{exact:true}).fill('contact_user');
 await page.getByLabel('Correo de acceso',{exact:true}).fill('contact-user@example.test');
 await page.getByLabel('Contraseña',{exact:true}).fill(password);
 await page.getByRole('combobox',{name:'Rol',exact:true}).selectOption({label:'Concesionario'});await page.getByRole('checkbox',{name:'Relacionar contacto',exact:true}).check();

 await page.getByRole('combobox',{name:'Concesionario asignado',exact:true}).selectOption({label:'Concesionario de Contactos · J-CONTACT-001'});
 await page.getByRole('button',{name:'Guardar usuario',exact:true}).click();
 await expect(page.getByText('Usuario guardado.',{exact:true})).toBeVisible();
 await page.locator('.workspace-user summary').click();await page.getByRole('button',{name:'Cerrar sesión',exact:true}).click();

 await login('contact-user@example.test',password);
 await page.goto('/clientes/create');
 await expect(page.getByRole('combobox',{name:'Concesionario',exact:true})).toHaveCount(0);
 await expect(page.getByRole('textbox',{name:'Concesionario',exact:true})).toHaveValue('Concesionario de Contactos');
 await expect(page.getByRole('textbox',{name:'Concesionario',exact:true})).toHaveAttribute('readonly');
 await page.getByLabel('Nombre y apellido').fill('Cliente asignado automáticamente');
 await page.setViewportSize({width:390,height:844});
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
 await page.screenshot({path:'test-results/client-concessionaire-automatic-mobile.png',fullPage:true});
 await page.getByRole('button',{name:'Guardar cliente',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Cliente asignado automáticamente',exact:true})).toBeVisible();
 await expect(page.locator('[data-concessionaire-details]')).toContainText('Concesionario de Contactos');
 expect(errors).toEqual([]);
});
