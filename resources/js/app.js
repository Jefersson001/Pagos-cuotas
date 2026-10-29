import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
import { createIcons, Layers, LayoutDashboard, ShoppingBag, UsersRound, CalendarClock, ChartNoAxesCombined, UserCog, ShieldCheck, Settings2, LogOut, Menu, SunMoon, CircleCheck, ArrowRight, Plus, Sparkles, ArrowUpRight, Wallet, BadgeDollarSign, AlarmClock, ChartPie, ChevronRight, CalendarCheck, Search, UserPlus, Check, Pencil, UserRound, Printer, MessageCircle, Download } from 'lucide';
const icons={Layers,LayoutDashboard,ShoppingBag,UsersRound,CalendarClock,ChartNoAxesCombined,UserCog,ShieldCheck,Settings2,LogOut,Menu,SunMoon,CircleCheck,ArrowRight,Plus,Sparkles,ArrowUpRight,Wallet,BadgeDollarSign,AlarmClock,ChartPie,ChevronRight,CalendarCheck,Search,UserPlus,Check,Pencil,UserRound,Printer,MessageCircle,Download};
window.Alpine=Alpine;
Alpine.data('productPlan',seed=>({
 price:seed.price,initial:seed.initial,count:seed.count,vehicleModel:seed.model||'',color:seed.color||'',images:seed.images||{},imageFailed:false,
 init(){this.$watch('vehicleImage',()=>{this.imageFailed=false;});},
 get vehicle(){const model=String(this.vehicleModel).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]/g,'');return Object.entries(this.images).find(([key,vehicle])=>key===model||(vehicle.aliases||[]).includes(model))?.[1]||null;},
 get vehicleImage(){return this.vehicle?.colors[this.color]||'';},
 get vehicleCaption(){return this.vehicle?this.vehicle.label+' · '+this.color.charAt(0).toUpperCase()+this.color.slice(1):'';},
 cents(value){const match=String(value??'').match(/^(\d{1,10})(?:\.(\d{1,2}))?$/);return match?Number(match[1])*100+Number((match[2]||'').padEnd(2,'0')):null;},
 money(value){return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(value/100);},
 get error(){const total=this.cents(this.price),initial=this.cents(this.initial),count=Number(this.count);if(total===null||total<=0)return 'Ingresa el precio Tráetelo con Bera para previsualizar el plan.';if(initial===null)return 'Ingresa una inicial válida.';if(initial>total)return 'La inicial no puede superar el precio Tráetelo con Bera.';if(!Number.isInteger(count)||count<1||count>120)return 'La cantidad de cuotas debe estar entre 1 y 120.';if(total>initial&&total-initial<count)return 'El saldo es menor a un céntimo por cuota.';return '';},
 get schedule(){if(this.error)return [];const balance=this.cents(this.price)-this.cents(this.initial),count=Number(this.count);if(balance===0)return [];return Array.from({length:count},(_,index)=>({number:index+1,amount:Math.floor(balance/count)+(index<balance%count?1:0)}));},
}));
const clientRelation=seed=>({
 clientId:seed.client?String(seed.client.id):'',clientDealers:seed.client?{[seed.client.id]:seed.client.dealership_id}:{},selectedClient:seed.client,clientText:seed.client?seed.client.name+' · '+seed.client.document:'',clientOptions:[],clientOpen:false,clientLoading:false,clientLookupError:'',clientHasMore:false,activeClientIndex:-1,clientSearchVersion:0,clientTimer:null,clientsUrl:seed.clientsUrl,error:'',
 openClientOptions(){this.clientOpen=true;if(!this.clientOptions.length&&!this.clientLoading)this.loadClients(++this.clientSearchVersion);},
 clientInputChanged(){
  this.clientId='';this.selectedClient=null;this.clientOptions=[];this.clientHasMore=false;this.activeClientIndex=-1;this.clientOpen=true;this.clientLookupError='';this.error='';this.clientLoading=true;
  clearTimeout(this.clientTimer);const version=++this.clientSearchVersion;this.clientTimer=setTimeout(()=>this.loadClients(version),300);
 },
 async loadClients(version){
  this.clientLoading=true;this.clientLookupError='';
  try{
   const url=new URL(this.clientsUrl,window.location.origin);url.searchParams.set('q',this.selectedClient?'':this.clientText.trim());
   const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
   if(!response.ok)throw new Error('No se pudieron consultar los concesionarios. Vuelve a escribir para intentarlo.');
   const data=await response.json();if(version!==this.clientSearchVersion)return;
   this.clientOptions=data.clients;this.clientHasMore=data.has_more;this.activeClientIndex=-1;
  }catch(error){if(version===this.clientSearchVersion){this.clientOptions=[];this.clientLookupError=error.message;}}
  finally{if(version===this.clientSearchVersion)this.clientLoading=false;}
 },
 selectClient(client){
  clearTimeout(this.clientTimer);++this.clientSearchVersion;this.selectedClient=client;this.clientDealers[client.id]=client.dealership_id;this.clientId=String(client.id);this.clientText=client.name+' · '+client.document;this.clientOpen=false;this.clientLoading=false;this.clientLookupError='';this.activeClientIndex=-1;this.error='';
 },
 moveClient(direction){
  if(!this.clientOpen){this.openClientOptions();return;}
  if(!this.clientOptions.length)return;this.activeClientIndex=this.activeClientIndex<0?(direction>0?0:this.clientOptions.length-1):(this.activeClientIndex+direction+this.clientOptions.length)%this.clientOptions.length;
  this.$nextTick(()=>document.getElementById('sale-client-option-'+this.clientOptions[this.activeClientIndex]?.id)?.scrollIntoView({block:'nearest'}));
 },
 chooseActiveClient(){if(this.clientOpen&&this.activeClientIndex>=0&&this.clientOptions[this.activeClientIndex])this.selectClient(this.clientOptions[this.activeClientIndex]);},
});
const productRelation=seed=>({
 productText:'',productOptions:[],productOpen:false,productLoading:false,productError:'',productHasMore:false,productIndex:-1,productVersion:0,productTimer:null,
 productInputChanged(){this.productOptions=[];this.productOpen=true;this.productLoading=true;this.productIndex=-1;clearTimeout(this.productTimer);const version=++this.productVersion;this.productTimer=setTimeout(()=>this.loadProducts(version),300);},
 openProductOptions(){this.productOpen=true;if(!this.productOptions.length&&!this.productLoading)this.loadProducts(++this.productVersion);},
 async loadProducts(version){this.productLoading=true;this.productError='';try{const url=new URL(seed.productsUrl,location.origin);url.searchParams.set('client_id',this.clientId);url.searchParams.set('q',this.productText.trim());const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok)throw new Error('No se pudieron consultar los productos.');const data=await response.json();if(version!==this.productVersion)return;this.productOptions=data.products;this.productHasMore=data.has_more;this.productIndex=-1;}catch(e){if(version===this.productVersion){this.productOptions=[];this.productError=e.message;}}finally{if(version===this.productVersion)this.productLoading=false;}},
 selectProduct(product){const item=this.items.find(i=>Number(i.product_id)===product.id);if(item){item.quantity=Math.min(100000,Number(item.quantity)+1);}else{if(this.items.length>=100){this.error='Puedes agregar hasta 100 productos.';return;}this.items.push({product_id:product.id,description:product.name,quantity:1,unit_price:product.price,initial_amount:product.initial_amount});}clearTimeout(this.productTimer);++this.productVersion;this.productText='';this.productOptions=[];this.productOpen=false;this.productLoading=false;this.productIndex=-1;this.error='';},
 moveProduct(direction){if(!this.productOpen){this.openProductOptions();return;}if(!this.productOptions.length)return;this.productIndex=this.productIndex<0?(direction>0?0:this.productOptions.length-1):(this.productIndex+direction+this.productOptions.length)%this.productOptions.length;this.$nextTick(()=>document.getElementById('product-option-'+this.productOptions[this.productIndex]?.id)?.scrollIntoView({block:'nearest'}));},
 chooseProduct(){if(this.productOpen&&this.productIndex>=0)this.selectProduct(this.productOptions[this.productIndex]);},
 resetProducts(){clearTimeout(this.productTimer);++this.productVersion;this.items=[];this.productText='';this.productOptions=[];this.productOpen=false;this.productLoading=false;this.productError='';}
});
Alpine.data('saleWizard', (seed)=>({
 ...clientRelation(seed),...productRelation(seed),step:0,error:'',dirty:true,items:seed.items,count:seed.count,frequency:seed.frequency,first:seed.first,custom:seed.custom,
 init(){this.ensureCustom();this.$watch('clientId',()=>this.resetProducts());this.$watch('count',()=>this.ensureCustom());window.addEventListener('beforeunload',e=>{if(this.dirty&&this.items.some(i=>i.description)){e.preventDefault();e.returnValue='';}});},
 cents(value){const [a,b='']=(String(value||'0')).split('.');return Number(a)*100+Number(b.padEnd(2,'0').slice(0,2));},
 money(c){return new Intl.NumberFormat('es-VE',{style:'currency',currency:'USD'}).format(c/100);},
 get total(){return this.items.reduce((n,i)=>n+this.cents(i.unit_price)*Number(i.quantity||0),0);},
 get initial(){return (this.items.reduce((n,i)=>n+this.cents(i.initial_amount)*Number(i.quantity||0),0)/100).toFixed(2);},
 get balance(){return this.total-this.cents(this.initial);},
 ensureCustom(){const count=Math.max(1,Math.min(120,Number(this.count)||1));for(let n=0;n<count;n++)if(!this.custom[n])this.custom[n]={due_date:this.first,amount:''};},
 get customTotal(){return this.custom.slice(0,Number(this.count)).reduce((n,i)=>n+this.cents(i.amount),0);},
 get schedule(){if(this.balance<=0)return [];const count=Math.max(1,Math.min(120,Number(this.count)||1));return Array.from({length:count},(_,n)=>{const d=new Date(`${this.first}T12:00:00`);if(Number.isNaN(d.getTime()))return {date:'',amount:0};if(this.frequency==='monthly'){const day=d.getDate();d.setDate(1);d.setMonth(d.getMonth()+n);const max=new Date(d.getFullYear(),d.getMonth()+1,0).getDate();d.setDate(Math.min(day,max));}else d.setDate(d.getDate()+n*(this.frequency==='weekly'?7:15));return {date:d.toLocaleDateString('es-VE'),amount:Math.floor(this.balance/count)+(n<this.balance%count?1:0)};});},
 next(){this.error='';if(this.step===0&&(!this.clientId||!this.selectedClient)){this.error='Selecciona un concesionario de las coincidencias.';this.$refs.clientInput.focus();return;}this.clientOpen=false;this.productOpen=false;if(this.step===1&&!this.items.length){this.error='Selecciona al menos un producto para el pedido.';return;}const section=this.$root.querySelector(`[data-step="${this.step}"]`);for(const field of section.querySelectorAll('input,select,textarea')){if(!field.checkValidity()){field.reportValidity();return;}}if(this.step>=2&&(this.total<=0||this.balance<0)){this.error='Revisa el total de la venta y el pago inicial.';return;}if(this.step===4&&this.frequency==='custom'&&this.balance>0&&this.customTotal!==this.balance){this.error='La suma de las cuotas debe coincidir con el saldo a financiar.';return;}this.step=Math.min(5,this.step+1);window.scrollTo({top:0,behavior:'smooth'});}
}));
Alpine.data('clientForm',seed=>({
 dealershipId:seed.dealershipId,responsibleId:seed.responsibleId,users:seed.users,loading:false,error:'',requestNumber:0,url:seed.url,
 init(){if(!this.users.some(user=>String(user.id)===this.responsibleId))this.responsibleId='';},
 async loadUsers(){
  const requestNumber=++this.requestNumber;this.responsibleId='';this.users=[];this.error='';this.loading=false;
  if(!this.dealershipId)return;
  this.loading=true;
  try{
   const url=new URL(this.url,window.location.origin);url.searchParams.set('dealership_id',this.dealershipId);
   const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw new Error('No se pudo cargar la lista');
   const data=await response.json();if(requestNumber===this.requestNumber)this.users=data.users;
  }catch{if(requestNumber===this.requestNumber)this.error='No se pudieron cargar los usuarios. Intenta nuevamente.';}
  finally{if(requestNumber===this.requestNumber)this.loading=false;}
 }
}));
Alpine.data('paymentDialog',autoOpen=>({
 submitting:false,
 fillInstallmentAmount(){const option=this.$refs.installment?.selectedOptions[0];const remaining=option?.value?Number(option.dataset.remaining):this.orderBalance;this.$refs.amount.value=((this.newPayment?remaining:Math.min(remaining,this.availableCredit))/100).toFixed(2);},
 init(){if(autoOpen||window.location.hash==='#registrar-pago'){if(window.location.hash==='#registrar-pago')window.history.replaceState({},'',window.location.pathname+window.location.search);this.$nextTick(()=>this.open());}},
 open(){const dialog=document.getElementById('payment-dialog');if(!dialog.open)dialog.showModal();document.body.classList.add('modal-open');this.$nextTick(()=>dialog.querySelector('[name=amount]').focus());},
 close(){if(!this.submitting)document.getElementById('payment-dialog').close();},
 closed(){document.body.classList.remove('modal-open');}
}));

Alpine.data('receiptApplication',seed=>({
 orders:seed.orders,orderId:seed.orderId,available:seed.available,
 init(){this.$watch('orderId',()=>{this.$nextTick(()=>{this.$refs.installment.value='';this.fillAmount();});});},
 get selectedOrder(){return this.orders.find(o=>String(o.id)===this.orderId);},
 fillAmount(){if(!this.selectedOrder){this.$refs.amount.value='';return;}const option=this.$refs.installment.selectedOptions[0];const pending=option?.value?Number(option.dataset.remaining):this.selectedOrder.balance;this.$refs.amount.value=(Math.min(pending,this.available)/100).toFixed(2);}
}));
Alpine.data('receiptForm',seed=>({
 ...clientRelation(seed),orders:[],methods:[],orderId:String(seed.orderId||''),installmentId:String(seed.installmentId||''),methodId:String(seed.methodId||''),accountLoading:false,accountError:'',accountVersion:0,credit:0,debt:0,
 init(){this.$watch('clientId',()=>{this.orderId='';this.installmentId='';this.methodId='';this.loadAccount();});this.$watch('orderId',()=>{this.installmentId='';this.$nextTick(()=>{if(this.selectedOrder)this.$refs.amount.value=(this.selectedOrder.balance/100).toFixed(2);});});if(this.clientId)this.loadAccount();},
 get selectedOrder(){return this.orders.find(o=>String(o.id)===this.orderId);},
 money(c){return new Intl.NumberFormat('es-VE',{style:'currency',currency:'USD'}).format(c/100);},
 async loadAccount(){const version=++this.accountVersion;this.orders=[];this.methods=[];this.credit=0;this.debt=0;this.accountError='';this.accountLoading=false;if(!this.clientId)return;this.accountLoading=true;
 try{const url=new URL(seed.accountUrl,location.origin);url.searchParams.set('client_id',this.clientId);const response=await fetch(url,{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)throw new Error('No se pudo consultar la cuenta del concesionario.');const data=await response.json();if(version!==this.accountVersion)return;this.orders=data.orders;this.methods=data.methods;this.credit=data.credit;this.debt=data.debt;if(!this.methodId)this.methodId=String(this.methods[0]?.id||'');}
 catch(e){if(version===this.accountVersion)this.accountError=e.message;}finally{if(version===this.accountVersion)this.accountLoading=false;}}
}));
Alpine.start();createIcons({icons});
Chart.defaults.font.family='Inter, system-ui, sans-serif';Chart.defaults.color='#737373';
Chart.defaults.animation=false;
function initializeCharts(root=document){const style=getComputedStyle(document.body),palette={red:style.getPropertyValue('--brand-red').trim(),surface:style.getPropertyValue('--surface').trim(),muted:style.getPropertyValue('--text-muted').trim(),line:style.getPropertyValue('--line').trim(),fill:style.getPropertyValue('--chart-fill').trim(),paid:style.getPropertyValue('--chart-paid').trim(),cancelled:style.getPropertyValue('--chart-cancelled').trim()};Chart.defaults.color=palette.muted;root.querySelectorAll('[data-chart]').forEach(canvas=>{
 const values=JSON.parse(canvas.dataset.values);if(['debt','credit'].includes(canvas.dataset.chart)){new Chart(canvas,{type:'bar',data:{labels:JSON.parse(canvas.dataset.labels),datasets:[{data:values,backgroundColor:canvas.dataset.chart==='credit'?palette.paid:palette.red,borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{callback:v=>'$'+v}},y:{grid:{display:false},ticks:{font:{size:10}}}}}});}else if(canvas.dataset.chart==='collections')new Chart(canvas,{type:'line',data:{labels:JSON.parse(canvas.dataset.labels),datasets:[{data:values,borderColor:palette.red,backgroundColor:palette.fill,fill:true,tension:.35,borderWidth:2,pointRadius:3,pointBackgroundColor:palette.surface,pointBorderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false},ticks:{font:{size:10}}},y:{beginAtZero:true,border:{display:false},grid:{color:palette.line},ticks:{font:{size:10},callback:v=>'$'+v}}}}});else new Chart(canvas,{type:'doughnut',data:{labels:['Activas','Pagadas','Canceladas'],datasets:[{data:values,backgroundColor:[palette.red,palette.paid,palette.cancelled],borderWidth:4,borderColor:palette.surface,borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,cutout:'78%',plugins:{legend:{display:false}}}});
});
}
initializeCharts();
let chartDarkMode=document.body.classList.contains('dark-mode');
new MutationObserver(()=>{const dark=document.body.classList.contains('dark-mode');if(dark===chartDarkMode)return;chartDarkMode=dark;document.querySelectorAll('[data-chart]').forEach(canvas=>Chart.getChart(canvas)?.destroy());initializeCharts();}).observe(document.body,{attributes:true,attributeFilter:['class']});
const liveStates=new WeakMap();
let contextSelection=document.querySelector('[data-context-url]')?.value??'';
let contextQueue=Promise.resolve();
function filterState(form){if(!liveStates.has(form))liveStates.set(form,{version:0,timer:null,inFlights:new Set()});return liveStates.get(form);}
function feedback(form,message='',error=false){
 let node=form.querySelector('.live-feedback');if(!node){node=document.createElement('span');node.className='live-feedback';node.setAttribute('role','status');node.setAttribute('aria-live','polite');form.append(node);}
 node.textContent=message;node.hidden=!message;node.classList.toggle('is-error',error);
}
async function persistContext(form,state,version){
 const field=form.querySelector('[data-context-url]');if(!field)return;
 const selected=field.value;const earlier=[...state.inFlights];
 contextQueue=contextQueue.catch(()=>{}).then(async()=>{
  if(version!==state.version||selected===contextSelection)return;
  await Promise.allSettled(earlier);if(version!==state.version)return;
  const response=await fetch(field.dataset.contextUrl,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:new URLSearchParams({dealership_id:selected})});
  if(!response.ok||response.redirected)throw new Error('No se pudo cambiar el concesionario.');contextSelection=selected;
 });
 await contextQueue;
}
function filterUrl(form,page=null){
 const url=new URL(form.action||window.location.href);url.search='';
 for(const [key,value] of new FormData(form)){if(typeof value==='string'&&(value!==''||key==='dealership_id'))url.searchParams.set(key,value);}
 if(page)url.searchParams.set('page',page);
 return url;
}
async function updateFilters(form,version,page=null){
 const state=filterState(form);if(version!==state.version)return;
 let panel=form.closest('[data-live-panel]');if(!panel)return;
 form.setAttribute('aria-busy','true');panel.setAttribute('aria-busy','true');feedback(form,'Actualizando…');
 try{
  await persistContext(form,state,version);if(version!==state.version)return;
  const url=filterUrl(form,page);
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html','X-Live-Filters':'1'}});
  if(version!==state.version)return;
  if(response.redirected&&new URL(response.url).pathname.endsWith('/login')){window.location.assign(response.url);return;}
  if(!response.ok)throw new Error('No se pudo actualizar la consulta. Intenta nuevamente.');
  const parsed=new DOMParser().parseFromString(await response.text(),'text/html');if(version!==state.version)return;
  if(response.redirected){throw new Error(parsed.querySelector('.notice.error')?.textContent.trim()||'Revisa los filtros seleccionados.');}
  const next=parsed.querySelector('[data-live-panel]');if(!next)throw new Error('No se pudo actualizar la consulta.');
  const active=document.activeElement;const selection=active instanceof HTMLInputElement?[active.selectionStart,active.selectionEnd]:null;
  const incomingForm=next.querySelector('[data-live-filters]');
  if(incomingForm){
   const users=form.querySelector('select[name=user_id]'),nextUsers=incomingForm.querySelector('select[name=user_id]');
   if(users&&nextUsers){const value=users.value;users.replaceChildren(...nextUsers.childNodes);users.value=Array.from(users.options).some(o=>o.value===value)?value:'';}
   const status=form.querySelector('select[name=status]'),nextStatus=incomingForm.querySelector('select[name=status]');if(status&&nextStatus){status.disabled=nextStatus.disabled;if(status.disabled)status.value='';}
   if(form.matches('[data-live-dates]')&&form.elements.date_mode.value===(form.dataset.dateDefault||'today')&&incomingForm.elements.date_mode.value===form.elements.date_mode.value){for(const name of ['from','to']){form.elements[name].value=incomingForm.elements[name].value;url.searchParams.set(name,form.elements[name].value);}}
   incomingForm.replaceWith(form);
  }
  panel.querySelectorAll('canvas').forEach(canvas=>Chart.getChart(canvas)?.destroy());panel.replaceWith(next);panel=next;
  const actions=document.querySelector('[data-page-actions]'),nextActions=parsed.querySelector('[data-page-actions]');if(actions&&nextActions)actions.replaceChildren(...nextActions.childNodes);
  const breadcrumb=document.querySelector('.breadcrumb'),nextBreadcrumb=parsed.querySelector('.breadcrumb');if(breadcrumb&&nextBreadcrumb)breadcrumb.replaceChildren(...nextBreadcrumb.childNodes);
  const todayLabel=document.querySelector('.today'),nextTodayLabel=parsed.querySelector('.today');if(todayLabel&&nextTodayLabel)todayLabel.textContent=nextTodayLabel.textContent;
  document.querySelector('main > .notice[data-dealership-warning]')?.remove();const warning=parsed.querySelector('main > .notice[data-dealership-warning]');if(warning)document.querySelector('main').prepend(warning);
  window.history.replaceState({},'',url);createIcons({icons});initializeCharts(next);
  if(active&&form.contains(active)){active.focus({preventScroll:true});if(selection&&selection[0]!==null)active.setSelectionRange(...selection);}
  feedback(form);
 }catch(error){if(error.name!=='AbortError'&&version===state.version)feedback(form,error.message||'No se pudo actualizar la consulta.',true);}
 finally{if(version===state.version){form.removeAttribute('aria-busy');panel.removeAttribute('aria-busy');}}
}
function scheduleFilters(form,immediate=false,page=null){
 const state=filterState(form);clearTimeout(state.timer);const version=++state.version;
 form.setAttribute('aria-busy','true');form.closest('[data-live-panel]')?.setAttribute('aria-busy','true');feedback(form,'Actualizando…');
 state.timer=setTimeout(()=>{const pending=updateFilters(form,version,page);state.inFlights.add(pending);pending.finally(()=>state.inFlights.delete(pending));},immediate?0:350);
}
function settleLiveFilters(){
 const pending=[];
 document.querySelectorAll('form[data-live-filters]').forEach(form=>{
  const state=filterState(form);clearTimeout(state.timer);++state.version;pending.push(...state.inFlights);
  form.removeAttribute('aria-busy');form.closest('[data-live-panel]')?.removeAttribute('aria-busy');feedback(form);
 });
 return pending;
}
document.addEventListener('input',event=>{
 const form=event.target.closest('form[data-live-filters]');if(!form||event.isComposing||event.target.matches('select,input[type=date],input[type=hidden]'))return;scheduleFilters(form);
});
document.addEventListener('change',event=>{
 const form=event.target.closest('form[data-live-filters]');if(!form)return;
 if(form.matches('[data-live-dates]')&&event.target.matches('input[type=date]')){const range=defaultDateRange(form);if(!event.target.value)event.target.value=range[event.target.name];form.elements.date_mode.value=form.elements.from.value===range.from&&form.elements.to.value===range.to?(form.dataset.dateDefault||'today'):'custom';}
 if(event.target.matches('[data-context-url]')){const user=form.querySelector('[name=user_id]');if(user)user.value='';}
 if(event.target.matches('select,input[type=date]'))scheduleFilters(form,true);
});

function filterToday(form){const parts=new Intl.DateTimeFormat('en-US',{timeZone:form.dataset.timezone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const value=type=>parts.find(part=>part.type===type).value;return `${value('year')}-${value('month')}-${value('day')}`;}
function defaultDateRange(form){const today=filterToday(form);return {from:form.dataset.dateDefault==='month'?today.slice(0,8)+'01':today,to:today};}
function refreshAutomaticDates(){document.querySelectorAll('[data-live-dates]').forEach(form=>{if(form.elements.date_mode.value!==(form.dataset.dateDefault||'today')||(form.contains(document.activeElement)&&document.activeElement.type==='date'))return;const range=defaultDateRange(form);if(form.elements.from.value!==range.from||form.elements.to.value!==range.to){form.elements.from.value=range.from;form.elements.to.value=range.to;scheduleFilters(form,true);}});}
window.addEventListener('pageshow',refreshAutomaticDates);
window.addEventListener('focus',refreshAutomaticDates);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshAutomaticDates();});
setInterval(refreshAutomaticDates,60000);
document.addEventListener('submit',event=>{
 const form=event.target;
 if(form.matches('[data-live-filters]')){event.preventDefault();scheduleFilters(form,true);return;}
 if(event.defaultPrevented)return;
 if(form.dataset.liveWaiting){event.preventDefault();return;}
 const ready=form.dataset.liveSubmissionReady==='true';delete form.dataset.liveSubmissionReady;
 if(!ready&&form.dataset.confirm&&!window.confirm(form.dataset.confirm)){event.preventDefault();return;}
 if(form.method==='post'&&!ready){
  const pending=settleLiveFilters();
  if(pending.length){
   event.preventDefault();form.dataset.liveWaiting='true';const submitter=event.submitter;if(submitter)submitter.disabled=true;
   Promise.allSettled(pending).then(()=>{delete form.dataset.liveWaiting;if(submitter)submitter.disabled=false;if(form.isConnected){form.dataset.liveSubmissionReady='true';form.requestSubmit(submitter);}});return;
  }
 }
 if(form.method==='post')setTimeout(()=>form.querySelectorAll('button:not([type=button])').forEach(button=>{button.disabled=true;button.textContent='Guardando…';}),0);
});
document.addEventListener('click',event=>{
 const row=event.target.closest('[data-row-link]');
 if(row&&!event.defaultPrevented&&!event.target.closest('a,button,input,select,textarea,label,[contenteditable="true"]')){
  if(window.getSelection()?.toString().trim())return;
  if(event.ctrlKey||event.metaKey||event.shiftKey)window.open(row.dataset.rowLink,'_blank','noopener,noreferrer');else window.location.assign(row.dataset.rowLink);
  return;
 }
 const whatsapp=event.target.closest('.whatsapp');if(whatsapp){const text=window.prompt('Edita el recordatorio antes de abrir WhatsApp:',whatsapp.dataset.message);if(text?.trim())window.open(`https://wa.me/${whatsapp.dataset.phone}?text=${encodeURIComponent(text)}`,'_blank','noopener,noreferrer');return;}
 const clear=event.target.closest('[data-clear-filters]');if(clear){const form=clear.closest('[data-live-filters]');if(!form)return;event.preventDefault();for(const field of form.elements){if(!field.name||field.matches('[data-context-url]'))continue;if(field instanceof HTMLSelectElement)field.selectedIndex=0;else field.value='';}scheduleFilters(form,true);return;}
 const link=event.target.closest('[data-live-panel] .pagination a');if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
 const form=link.closest('[data-live-panel]').querySelector('[data-live-filters]');if(!form)return;event.preventDefault();scheduleFilters(form,true,new URL(link.href).searchParams.get('page'));
});
window.addEventListener('popstate',()=>{
 const form=document.querySelector('[data-live-filters]');if(!form)return;const params=new URL(window.location.href).searchParams;
 for(const field of form.elements){if(field.name)field.value=params.get(field.name)??(field.matches('[data-context-url]')?contextSelection:field instanceof HTMLSelectElement?field.options[0]?.value??'':'');}
 if(form.matches('[data-live-dates]')){const range=defaultDateRange(form),mode=form.dataset.dateDefault||'today';const automatic=params.get('date_mode')===mode||(!params.get('from')&&!params.get('to'));form.elements.date_mode.value=automatic?mode:'custom';form.elements.from.value=automatic?range.from:(params.get('from')||range.from);form.elements.to.value=automatic?range.to:(params.get('to')||range.to);}
 scheduleFilters(form,true,params.get('page'));
});
