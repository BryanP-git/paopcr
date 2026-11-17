const API='api/index.php?r=';
const $=(s,e=document)=>e.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>'$'+Math.round(Number(n)).toLocaleString('es-CO');
const E={pendiente:'Pendiente',en_preparacion:'En preparación',listo:'Listo',entregado:'Entregado',cancelado:'Cancelado'};
const hoy=()=>new Date(Date.now()-new Date().getTimezoneOffset()*6e4).toISOString().slice(0,10);
const me=()=>{try{return JSON.parse(localStorage.getItem('usr'))}catch(e){return null}};
function logout(){localStorage.clear();location='index.html'}
function guard(roles){const u=me();if(!u||!localStorage.getItem('tk')||(u.rol!=='administrador'&&!roles.includes(u.rol)))logout();return u}
async function api(r,body,method){
  const o={method:method||(body?'POST':'GET'),headers:{'Content-Type':'application/json','Authorization':'Bearer '+localStorage.getItem('tk')}};
  if(body)o.body=JSON.stringify(body);
  const res=await fetch(API+r,o);const d=await res.json().catch(()=>({}));
  if(res.status===401&&r!=='auth/login')logout();
  if(!res.ok)throw new Error(d.error||'Error del servidor');return d}
function stream(cb){const es=new EventSource(API+'stream&token='+localStorage.getItem('tk'));es.onmessage=e=>cb(JSON.parse(e.data));return es}
function toast(m){let t=$('#toast');if(!t){t=document.createElement('div');t.id='toast';document.body.append(t)}t.textContent=m;t.className='show';clearTimeout(t._h);t._h=setTimeout(()=>t.className='',3500)}
function dlg(html,wide){const d=$('#dlg');d.className=wide?'wide':'';d.innerHTML=html;if(!d.open)d.showModal();return d}
function formDlg(title,fields,vals,save,label){
  const d=dlg(`<h3>${esc(title)}</h3>`+fields.map(f=>`<label>${esc(f.l)}${f.t==='select'
    ?`<select name="${f.k}">${f.o.map(o=>`<option value="${esc(o[0])}" ${String(vals[f.k]??'')===String(o[0])?'selected':''}>${esc(o[1])}</option>`).join('')}</select>`
    :`<input name="${f.k}" type="${f.t||'text'}" step="any" value="${f.t==='password'?'':esc(vals[f.k])}">`}</label>`).join('')
    +`<div class="row end" style="margin-top:16px"><button class="btn sec" id="no">Cancelar</button><button class="btn" id="ok">${label||'Guardar'}</button></div>`);
  $('#no',d).onclick=()=>d.close();
  $('#ok',d).onclick=async()=>{const o={};d.querySelectorAll('[name]').forEach(e=>o[e.name]=e.value);try{await save(o);d.close()}catch(e){toast(e.message)}}}
function receipt(r){return `<div class="rcp"><h3>Comprobante de venta N.° ${r.id}</h3><p class="mut">${esc(r.fecha)} · ${r.tipo==='llevar'?'Para llevar':'Mesa '+esc(r.mesa)}</p>`
  +r.items.map(i=>`<div class="ln"><span>${i.cantidad} × ${esc(i.nombre)}</span><span>${money(i.precio*i.cantidad)}</span></div>`).join('')
  +`<div class="ln"><span>Subtotal</span><span>${money(r.subtotal)}</span></div><div class="ln"><span>Impuesto</span><span>${money(r.impuesto)}</span></div><div class="ln tt"><span>Total</span><span>${money(r.total)}</span></div>
  <p class="mut">Pago: ${esc(r.metodo)} · Recibido ${money(r.recibido)} · Cambio ${money(r.recibido-r.total)}</p></div><div class="row end"><button class="btn sec" onclick="$('#dlg').close()">Cerrar</button><button class="btn" onclick="print()">Imprimir</button></div>`}
async function renderHist(el,fecha){
  fecha=fecha||hoy();const ps=await api('historial&fecha='+fecha),vt=ps.filter(p=>p.pagado==1).reduce((a,p)=>a+ +p.total,0);
  el.innerHTML=`<div class="row"><input type="date" id="hf" value="${fecha}" style="width:170px"><button class="btn sec" id="hb">Consultar</button><span class="mut">${ps.length} pedidos · ventas cobradas ${money(vt)}</span></div>
  <div class="tw"><table><tr><th>N.°</th><th>Hora</th><th>Mesa</th><th>Estado</th><th>Pago</th><th>Total</th><th></th></tr>${ps.map((p,i)=>`<tr><td>${p.id}</td><td>${p.hora}</td><td>${p.tipo==='llevar'?'Llevar':esc(p.mesa)}</td>
  <td><span class="badge ${p.estado}">${E[p.estado]}</span>${p.motivo_cancelacion?`<small class="mut"> ${esc(p.motivo_cancelacion)}</small>`:''}</td><td>${p.pagado==1?esc(p.metodo):'Sin cobrar'}</td><td>${money(p.total)}</td>
  <td>${p.pagado==1?`<button class="btn sm sec" data-rc="${i}">Comprobante</button>`:''}</td></tr>`).join('')||'<tr><td colspan="7" class="mut">Sin pedidos en esta fecha</td></tr>'}</table></div>`;
  $('#hb',el).onclick=()=>renderHist(el,$('#hf',el).value).catch(e=>toast(e.message));
  el.querySelectorAll('[data-rc]').forEach(b=>b.onclick=()=>dlg(receipt(ps[b.dataset.rc])))}
