export async function openMenu(page){
 const box=await page.locator('.sidebar').boundingBox();
 if(page.viewportSize().width>900){if(box.x<0)await page.mouse.move(8,200);else await page.mouse.move(100,150);}
 else if(box.x<0)await page.getByRole('button',{name:'Abrir menú',exact:true}).click();
}
export async function openSettings(page){
 await openMenu(page);const button=page.getByRole('button',{name:'Configuración',exact:true});if(await button.getAttribute('aria-expanded')!=='true')await button.click();
}