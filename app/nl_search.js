// GET · Buscador inteligente de segmentación.
// Se carga al final de leads_segmentacion.html (<script src="nl_search.js"></script>) y usa sus globales
// (F, GEO, DIMS, buildPanel, refreshBadges, queueQuery). Inyecta su propia barra y estilos.
(function(){
  const CATS=['sexo','rango_edad','estrato','nivel_educativo','afiliacion_politica','platform'];
  const css=document.createElement('style');
  css.textContent=`
  .nl-bar{margin:0 0 12px;display:flex;flex-direction:column;gap:6px}
  .nl-row{display:flex;gap:8px}
  .nl-row input{flex:1;min-width:0;padding:11px 14px;border-radius:12px;border:1px solid var(--border,#d6dbe8);background:var(--surface,#fff);color:var(--text,#1b2138);font:inherit;font-size:.9rem}
  .nl-row input:focus{outline:2px solid var(--accent,#5b5bd6);outline-offset:0}
  .nl-row button{padding:0 18px;border-radius:12px;border:0;background:var(--accent,#5b5bd6);color:#fff;font:inherit;font-weight:700;cursor:pointer}
  .nl-row button:disabled{opacity:.55;cursor:wait}
  .nl-out{font-size:.78rem;color:var(--text-dim,#5f6883);line-height:1.5}
  .nl-out .nl-warn{color:var(--warn,#b45309)} .nl-out .nl-err{color:var(--bad,#dc2626)}
  `;
  document.head.appendChild(css);

  const bar=document.createElement('div'); bar.className='nl-bar';
  bar.innerHTML=`<div class="nl-row">
      <input id="nl-q" type="text" maxlength="500" autocomplete="off"
        placeholder="Describe el segmento: «mujeres de 25 a 44 en Ituango que se preocupan por la seguridad»">
      <button id="nl-btn" type="button">✨ Buscar</button></div>
    <div class="nl-out" id="nl-out"></div>`;
  // El total del segmento (.status) va arriba; el buscador queda justo debajo.
  const status=document.querySelector('.status');
  if(status) status.parentNode.insertBefore(bar,status.nextSibling);
  else { const m=document.querySelector('main'); m.parentNode.insertBefore(bar,m); }

  const $q=bar.querySelector('#nl-q'), $b=bar.querySelector('#nl-btn'), $o=bar.querySelector('#nl-out');
  const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function apply(res){
    const f=res.filtros||{};
    [...CATS,'temas','import_ids'].forEach(k=>F[k]&&F[k].clear());
    F.preguntas=[]; F.is_organic=''; F.created_desde=''; F.created_hasta='';
    CATS.forEach(k=>(f[k]||[]).forEach(v=>F[k].add(String(v))));
    (f.temas||[]).forEach(id=>F.temas.add(String(id)));
    F.preguntasModo=(f.preguntas_modo==='any'&&(f.preguntas||[]).length>1)?'any':'all';
    F.preguntas=(f.preguntas||[]).map(c=>{
      const p=(DIMS.preguntas||[]).find(x=>x.id===c.pregunta_id)||{};
      return {pregunta_id:c.pregunta_id,pregunta:p.pregunta||('Pregunta '+c.pregunta_id),tema:p.subtema||p.tema,op:c.op,valores:c.valores||[]};
    });
    GEO={level:'region',path:[],sel:new Set(),selLabels:{}};
    if(f.geo){
      GEO.level=f.geo.nivel; GEO.path=f.geo.path||[]; GEO.sel=new Set(f.geo.ids.map(String));
      GEO.selLabels=Object.fromEntries(Object.entries(f.geo.labels||{}).map(([k,v])=>[String(k),v]));
    }
    buildPanel();
    document.querySelectorAll('.tile[data-key]').forEach(el=>{
      const s=F[el.dataset.key]; if(s&&s.has&&s.has(el.dataset.v)) el.classList.add('on');
    });
    // abre solo los desplegables que tienen filtros activos
    const open=(id,on)=>{ const c=document.getElementById('card-'+id); if(c&&on) c.classList.remove('collapsed'); };
    open('demo',['sexo','rango_edad','estrato','nivel_educativo'].some(k=>F[k].size));
    open('pol',F.afiliacion_politica.size>0); open('temas',F.temas.size>0);
    open('eav',F.preguntas.length>0); open('geo',!!f.geo);
    if(typeof refreshBadges==='function') refreshBadges();
    queueQuery();
  }

  async function run(){
    const texto=$q.value.trim(); if(!texto) return;
    $b.disabled=true; $o.innerHTML='Interpretando…';
    try{
      const r=await fetch('nl_search.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({texto})});
      const res=await r.json();
      if(!res.ok){ $o.innerHTML=`<span class="nl-err">${esc(res.error||'Error')}</span>`; return; }
      if(!Object.keys(res.filtros||{}).length){
        $o.innerHTML=esc(res.explicacion||'No encontré filtros para esa frase.')+((res.no_resuelto||[]).length?`<br><span class="nl-warn">Sin resolver: ${res.no_resuelto.map(esc).join(' · ')}</span>`:'');
        return;
      }
      apply(res);
      $o.innerHTML='✓ '+esc(res.explicacion||'Filtros aplicados.')+((res.no_resuelto||[]).length?`<br><span class="nl-warn">Sin resolver: ${res.no_resuelto.map(esc).join(' · ')}</span>`:'');
    }catch(e){ $o.innerHTML=`<span class="nl-err">No se pudo completar la búsqueda: ${esc(e.message)}</span>`; }
    finally{ $b.disabled=false; }
  }
  $b.addEventListener('click',run);
  $q.addEventListener('keydown',e=>{ if(e.key==='Enter') run(); });
})();
