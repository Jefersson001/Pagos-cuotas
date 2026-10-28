import {openMenu,openSettings} from './navigation.js';
import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
let credentials;
test.beforeAll(()=>{credentials=JSON.parse(execFileSync('php',['tests/browser/seed.php'],{env:{...process.env,DB_CONNECTION:'mysql',DB_DATABASE:'ventas_programada_browser'},encoding:'utf8'}));});
test('productos relacionales, cantidades e inicial calculada del pedido',async({page})=>{
 test.setTimeout(90000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('/login');await page.locator('[name=email]').fill(credentials.email);await page.locator('[name=password]').fill(credentials.password);await page.getByRole('button',{name:'Ingresar al panel'}).click();
 const createProduct=async(name,price,initial)=>{
  await openSettings(page);await page.getByRole('link',{name:'Productos',exact:true}).click();await page.getByRole('link',{name:'Nuevo producto',exact:true}).click();
  await page.getByLabel('Marca',{exact:true}).fill('Bera');await page.getByLabel('Modelo',{exact:true}).fill(name.replace('Bera ',''));await page.getByRole('combobox',{name:'Transmisión',exact:true}).selectOption('sincronico');await page.getByLabel('Año',{exact:true}).fill('2026');await page.getByText('Rojo',{exact:true}).click();
  await expect(page.locator('[name=color][value=rojo]')).toBeChecked();await expect(page.locator('.product-color-option')).toHaveCount(5);await expect(page.locator('[name=color][value=rojo] + .product-color-circle')).toHaveCSS('outline-style','solid');
  const priceField=page.getByLabel('Precio Tráetelo con Bera ($)',{exact:true}),initialField=page.getByLabel('Inicial ($)',{exact:true}),countField=page.getByLabel('Cuotas',{exact:true}),plan=page.locator('[data-product-plan]');
  await expect(countField).toHaveValue('5');await page.getByLabel('Precio contado ($)',{exact:true}).fill('80.00');await priceField.fill(price);await initialField.fill(initial);
  await expect(plan.locator('[data-plan-installment]')).toHaveCount(5);await expect(plan.locator('[data-plan-initial]')).toContainText('$'+initial);await expect(plan.locator('[data-plan-total]')).toHaveText('$'+price);
  if(name==='Bera Alfa'){
   const modelField=page.getByLabel('Modelo',{exact:true}),photo=page.locator('[data-vehicle-image]');
   await modelField.fill('LIVAN X3 PRO');
   for(const color of ['Gris','Blanco','Plata','Rojo','Negro']){
    await page.locator('.product-color-option').filter({hasText:color}).click();await expect(photo).toBeVisible();await expect(photo).toHaveAttribute('src',new RegExp('/images/vehicles/x3-pro/'+color.toLowerCase()+'\\.jpg$'));await expect.poll(()=>photo.evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
   }
   await page.screenshot({path:'test-results/product-vehicle-preview.png',fullPage:true});
   for(const model of ['X3-Pro','livan automatico','Livan sincronico','Livan Automático','Livan Sincrónico']){await modelField.fill(model);await expect(photo).toBeVisible();await expect(photo).toHaveAttribute('src',/\/negro\.jpg$/);await expect.poll(()=>photo.evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);}await modelField.fill('Otro modelo');await expect(photo).toHaveCount(0);await expect(page.getByText('No hay fotografías disponibles para este modelo.',{exact:true})).toBeVisible();
   await modelField.fill('Alfa');await page.locator('.product-color-option').filter({hasText:'Rojo'}).click();
   await initialField.fill('0');await countField.fill('3');await expect(plan.locator('[data-plan-installment] td:last-child')).toHaveText(['$33.34','$33.34','$33.33']);
   await countField.fill('2');await expect(plan.locator('[data-plan-installment] td:last-child')).toHaveText(['$50.01','$50.00']);
   await priceField.fill('120');await initialField.fill('20');await countField.fill('5');await expect(plan.locator('[data-plan-installment] td:last-child')).toHaveText(['$20.00','$20.00','$20.00','$20.00','$20.00']);
   await initialField.fill('130');await expect(plan.getByText('La inicial no puede superar el precio Tráetelo con Bera.',{exact:true})).toBeVisible();await expect(plan.locator('.table-scroll')).toBeHidden();
   await initialField.fill('120');await expect(plan.locator('[data-plan-installment]')).toHaveCount(0);await expect(plan.locator('[data-plan-initial]')).toContainText('$120.00');
   await priceField.fill(price);await initialField.fill(initial);await expect(plan.locator('[data-plan-installment]')).toHaveCount(5);
   await page.screenshot({path:'test-results/product-payment-plan.png',fullPage:true});
  }
  await page.getByRole('button',{name:'Guardar producto',exact:true}).click();await expect(page.getByText('Producto guardado.',{exact:true})).toBeVisible();await expect(countField).toHaveValue('5');await expect(plan.locator('[data-plan-installment]')).toHaveCount(5);
 };
 await createProduct('Bera Alfa','100.01','20.01');await createProduct('Bera Beta','200.02','40.02');
 await openSettings(page);await page.getByRole('link',{name:'Productos',exact:true}).click();await page.getByRole('textbox',{name:'Consultar productos'}).fill('Bera Beta');await expect(page.getByRole('row').filter({hasText:'Alfa'})).toHaveCount(0);await expect(page.getByRole('row').filter({hasText:'Beta'})).toBeVisible();
 await openSettings(page);await page.getByRole('link',{name:'Clientes',exact:true}).click();await page.getByRole('link',{name:'Nuevo cliente'}).click();await page.getByLabel('Nombre y apellido').fill('Cliente productos');await page.getByLabel('Cédula / Identificación').fill('PRODUCTS-BROWSER');await page.getByLabel('Teléfono',{exact:true}).fill('04121234567');await page.locator('#client-responsible').selectOption({index:1});await page.getByRole('button',{name:'Guardar cliente'}).click();await page.getByRole('link',{name:'Nueva venta'}).click();
 const next=()=>page.getByRole('button',{name:'Continuar'}).click();await next();await expect(page.getByRole('heading',{name:'Pedido',exact:true})).toBeVisible();await expect(page.getByLabel('Descripción del pedido')).toHaveCount(0);await next();await expect(page.getByText('Selecciona al menos un producto para el pedido.',{exact:true})).toBeVisible();
 const lookup=page.getByRole('combobox',{name:'Productos',exact:true});
 await lookup.fill('SIN-PRODUCTO');await expect(page.getByText('No hay productos que coincidan.',{exact:true})).toBeVisible();
 await lookup.fill('Bera Alfa');await expect(page.getByRole('option').filter({hasText:'Bera Alfa'})).toBeVisible();await lookup.press('ArrowDown');await lookup.press('Enter');await lookup.click();await expect(page.locator('#product-options')).toBeVisible();await page.getByRole('heading',{name:'Pedido',exact:true}).click();await page.getByRole('spinbutton',{name:'Cantidad de Bera Alfa 2026 Sincrónico Rojo',exact:true}).fill('2');
 await lookup.click();await expect(lookup).toBeFocused();await expect(page.locator('#product-options')).toBeVisible();await expect(page.getByRole('spinbutton',{name:'Cantidad de Bera Alfa 2026 Sincrónico Rojo',exact:true})).toHaveValue('2');
 await lookup.fill('Bera Beta');await page.getByRole('option').filter({hasText:'Bera Beta'}).click();await page.getByRole('spinbutton',{name:'Cantidad de Bera Beta 2026 Sincrónico Rojo',exact:true}).fill('3');
 await page.getByRole('button',{name:'Quitar Bera Beta 2026 Sincrónico Rojo',exact:true}).click();await expect(page.getByRole('spinbutton',{name:'Cantidad de Bera Beta 2026 Sincrónico Rojo',exact:true})).toHaveCount(0);
 await lookup.click();await expect(lookup).toBeFocused();await expect(page.locator('#product-options')).toBeVisible();await expect(page.getByRole('spinbutton',{name:'Cantidad de Bera Alfa 2026 Sincrónico Rojo',exact:true})).toHaveValue('2');
 await lookup.fill('Bera Beta');await page.getByRole('option').filter({hasText:'Bera Beta'}).click();await page.getByRole('spinbutton',{name:'Cantidad de Bera Beta 2026 Sincrónico Rojo',exact:true}).fill('3');
 await page.screenshot({path:'test-results/order-products.png',fullPage:true});await next();await expect(page.getByLabel('Inicial programada ($)',{exact:true})).toHaveValue('160.08');await expect(page.getByLabel('Inicial programada ($)',{exact:true})).toHaveAttribute('readonly','');await page.screenshot({path:'test-results/order-products-initial.png',fullPage:true});
 await next();await next();await next();await page.getByRole('button',{name:'Confirmar venta',exact:true}).click();await expect(page.getByText('Venta creada y cronograma registrado.')).toBeVisible();await expect(page.getByText('No se han registrado pagos.',{exact:true})).toBeVisible();await expect(page.locator('tbody tr').filter({hasText:'Bera Alfa'})).toContainText('$100.01');await expect(page.locator('tbody tr').filter({hasText:'Bera Beta'})).toContainText('$200.02');
 await page.getByRole('button',{name:'Registrar pago',exact:true}).click();await page.locator('#payment-installment').selectOption({label:'Inicial · $160.08'});await expect(page.locator('#payment-amount')).toHaveValue('160.08');await page.getByRole('button',{name:'Cancelar',exact:true}).click();expect(errors).toEqual([]);
});