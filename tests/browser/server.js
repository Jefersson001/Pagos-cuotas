import {spawn} from 'node:child_process';
import path from 'node:path';
export default async function(){
 const server=spawn('php',['-S','127.0.0.1:8096','../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],{cwd:path.resolve('public'),windowsHide:true,stdio:'ignore',env:{...process.env,APP_URL:'http://127.0.0.1:8096',DB_CONNECTION:process.env.BROWSER_DB_CONNECTION||'mysql',DB_DATABASE:process.env.BROWSER_DB_DATABASE||'ventas_cuotas_browser',APP_DEBUG:'false'}});
 server.unref();
 const stop=()=>{if(server.exitCode===null)server.kill();};
 for(let i=0;i<50;i++){
  if(server.exitCode!==null)throw new Error('El servidor PHP de pruebas no pudo iniciar. Revisa el puerto 8096.');
  try{const response=await fetch('http://127.0.0.1:8096/up');if(response.ok)return async()=>stop();}catch{}
  await new Promise(resolve=>setTimeout(resolve,200));
 }
 stop();throw new Error('El servidor PHP no respondió a tiempo.');
}
