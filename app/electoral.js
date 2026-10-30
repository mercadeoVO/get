// GET · Pantalla Electoral: resumen de UNA encuesta electoral (leads_importer.php?action=electoral_resumen).
// Usa globales de dashboard_local.html: $e, escH, ENC (encuesta elegida) y window.ELECTORAL_GEO (ubicación).
(function(){
  const ELEC={tab:null,corte:'',datos:null,validos:false,seq:0};
  const TIPOS={voto:'Intención de voto',imagen:'Imagen',conocimiento:'Conocimiento'};
  const CORTES=[['','Total'],['sexo','Sexo'],['rango_edad','Edad'],['estrato','Estrato']];

  // ── Formato ────────────────────────────────────────────────────────────
  const fmtN=n=>Number(n).toLocaleString('es-CO');
  const dec=(x,d=1)=>Number(x).toLocaleString('es-CO',{minimumFractionDigits:d,maximumFractionDigits:d});
  const pct=(a,b,d=1)=>b>0?dec(100*a/b,d)+' %':'—';
  const pts=x=>(x>0?'+':x<0?'−':'')+dec(Math.abs(x),1)+' pts';
  const cap=s=>String(s||'').replace(/(^|[\s(-])(\p{L})/gu,(m,a,b)=>a+b.toUpperCase()).replace(/(?<=\S) (De|Del|La|Las|Los|Y|Van|Von)(?= |$)/g,(m,w)=>' '+w.toLowerCase());
  const capPrimera=s=>{s=String(s||'').trim();return s.charAt(0).toUpperCase()+s.slice(1);};
  const norm=s=>String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().trim();
  const claseImagen=r=>{const n=norm(r);return n.startsWith('posit')?'pos':n.startsWith('negat')?'neg':'nolo';};
  const conoce=r=>{const n=norm(r);return n==='si'||n.startsWith('posit')||n.startsWith('negat');};
  const NO_VALIDO=/^(ninguno|ninguna|no sabe|no responde|nr|ns|blanco|voto en blanco|no votaria|otro|otra)\b/;

  // ── Datos ──────────────────────────────────────────────────────────────
  function geoFiltro(){
    const g=window.ELECTORAL_GEO||{};
    for(const [k,n] of [['bar','barrio'],['com','comuna'],['zon','zona'],['mun','municipio'],['dep','departamento'],['reg','region']]){
      if(g[k]) return {nivel:n,ids:[String(g[k])]};
    }
    return null;
  }
  // Agrupa los datos de una pregunta por grupo del corte: {grupos, por:{g:{resp:n}}, tot:{g:n}, total}
  function agrupar(p){
    const por={},tot={};let total=0;
    p.datos.forEach(x=>{
      const g=x.grupo; por[g]??={}; por[g][x.respuesta]=(por[g][x.respuesta]||0)+x.n;
      tot[g]=(tot[g]||0)+x.n; total+=x.n;
    });
    const grupos=Object.keys(por).sort((a,b)=>a==='Sin dato'?1:b==='Sin dato'?-1:a.localeCompare(b,'es',{numeric:true}));
    return {grupos,por,tot,total};
  }
  const suma=(por,fn)=>Object.entries(por).reduce((a,[r,n])=>a+(fn(r)?n:0),0);

  // ── Carga ──────────────────────────────────────────────────────────────
  async function cargar(){
    const id=$e('enc-sel').value, seq=++ELEC.seq;
    if(!id){ renderListado(); return; }
    $e('elec-body').innerHTML='<div class="elec-vacio">Calculando…</div>';
    const fd=new FormData(); fd.append('import_id',id); fd.append('corte',ELEC.corte);
    const f={}; const g=geoFiltro(); if(g) f.geo=g; fd.append('filtros',JSON.stringify(f));
    try{
      const d=await fetch('leads_importer.php?action=electoral_resumen',{method:'POST',body:fd}).then(r=>r.json());
      if(seq!==ELEC.seq) return;
      if(!d.ok) throw new Error(d.error||'Error');
      ELEC.datos=d;
      const tipos=tiposDisponibles();
      if(!tipos.includes(ELEC.tab)) ELEC.tab=tipos[0]||null;
      render();
    }catch(e){ if(seq===ELEC.seq) $e('elec-body').innerHTML=`<div class="elec-vacio">No se pudo cargar la encuesta: ${escH(e.message)}</div>`; }
  }
  const tiposDisponibles=()=>['voto','imagen','conocimiento'].filter(t=>(ELEC.datos?.preguntas||[]).some(p=>p.tipo===t));

  // ── Listado cuando no hay encuesta elegida ─────────────────────────────
  function renderListado(){
    $e('elec-kpis').innerHTML=''; $e('elec-tools').hidden=true; $e('elec-nota').textContent='';
    const l=ENC.lista||[];
    $e('elec-body').innerHTML=l.length
      ? `<div class="elec-lista">${l.map(e=>`<button class="elec-card" onclick="window.elegirEncuesta(${e.id})">
          <b>${escH(e.archivo)}</b>
          <span>${fmtN(e.filas_ins)} respuestas · ${escH(String(e.importado_en).slice(0,10))}</span>
          <span>${escH([e.tipo_encuesta?String(e.tipo_encuesta).split(',').map(t=>(ENC.opciones?.tipos.find(o=>o.v===t)||{}).l||t).join(' · '):'Sin clasificar',e.lista_eleccion||'',e.nivel_eleccion||''].filter(Boolean).join(' · '))}</span>
        </button>`).join('')}</div>`
      : '<div class="elec-vacio">No hay encuestas electorales con estos filtros.</div>';
  }
  window.elegirEncuesta=id=>{ $e('enc-sel').value=String(id); window.onSelEncuesta(); };

  // ── Render principal ───────────────────────────────────────────────────
  function render(){
    const d=ELEC.datos; if(!d) return;
    renderKpis(d);
    $e('elec-tools').hidden=false;
    const tipos=tiposDisponibles();
    $e('elec-tabs').innerHTML=tipos.map(t=>`<button class="${t===ELEC.tab?'on':''}" onclick="window.elecTab('${t}')">${TIPOS[t]}</button>`).join('');
    $e('elec-cortes').innerHTML=CORTES.map(([k,l])=>`<button class="${k===ELEC.corte?'on':''}" onclick="window.elecCorte('${k}')">${l}</button>`).join('');
    $e('elec-valid-wrap').hidden=ELEC.tab!=='voto';
    $e('elec-valid').checked=ELEC.validos;
    const ps=d.preguntas.filter(p=>p.tipo===ELEC.tab);
    $e('elec-body').innerHTML=!ps.length?'<div class="elec-vacio">Esta encuesta no tiene preguntas de este tipo.</div>'
      : ELEC.tab==='voto'?ps.map(vistaVoto).join(''):ELEC.tab==='imagen'?vistaImagen(ps):vistaConocimiento(ps);
    $e('elec-nota').textContent='Muestra captada por Meta Ads (no probabilística): el margen de error es solo una referencia. Cada porcentaje usa como base a quienes respondieron esa pregunta.';
  }
  window.elecTab=t=>{ ELEC.tab=t; render(); };
  window.elecCorte=c=>{ if(c===ELEC.corte) return; ELEC.corte=c; cargar(); };
  window.elecValidos=v=>{ ELEC.validos=v; render(); };

  // ── Tarjetas resumen ───────────────────────────────────────────────────
  function renderKpis(d){
    const k=[];
    k.push(['Respuestas',fmtN(d.total),'leads de la encuesta'+(geoFiltro()?' en la ubicación elegida':'')]);
    if(d.total>0) k.push(['Margen de error','± '+dec(196*Math.sqrt(0.25/d.total),1)+' %','95 % de confianza · referencial']);
    k.push(['Cobertura',fmtN(d.cobertura.municipios),d.cobertura.municipios===1?'municipio':'municipios en '+d.cobertura.departamentos+(d.cobertura.departamentos===1?' departamento':' departamentos')]);
    const v=d.preguntas.find(p=>p.tipo==='voto');
    if(v){
      const a=agrupar(v), tot={}; Object.values(a.por).forEach(m=>Object.entries(m).forEach(([r,n])=>{ if(!NO_VALIDO.test(norm(r))) tot[r]=(tot[r]||0)+n; }));
      const base=Object.values(tot).reduce((x,y)=>x+y,0), top=Object.entries(tot).sort((x,y)=>y[1]-x[1])[0];
      if(top) k.push(['Líder en intención de voto',pct(top[1],base),cap(top[0])+' · votos válidos']);
    }
    const imgs=imagenes(d), cons=conocimientos(d);
    if(cons.length){ const t=cons.sort((x,y)=>y.pc-x.pc)[0]; k.push(['Más conocido',dec(t.pc*100,1)+' %',cap(t.nombre)]); }
    const conocidas=imgs.filter(i=>i.base&&i.c.nolo/i.base<=0.5);   // solo figuras que conoce más de la mitad
    if(conocidas.length){ const t=conocidas.sort((x,y)=>y.net-x.net)[0]; k.push(['Mejor saldo de imagen',pts(t.net*100),cap(t.nombre)+' · entre las más conocidas']); }
    $e('elec-kpis').innerHTML=k.map(([l,val,s])=>`<div class="kpi"><div class="label">${escH(l)}</div><div class="value">${escH(val)}</div><div class="sub">${escH(s)}</div></div>`).join('');
  }
  function imagenes(d){
    return d.preguntas.filter(p=>p.tipo==='imagen').map(p=>{
      const a=agrupar(p);
      const c={pos:0,neg:0,nolo:0}; a.grupos.forEach(g=>Object.entries(a.por[g]).forEach(([r,n])=>c[claseImagen(r)]+=n));
      const base=c.pos+c.neg+c.nolo;
      return {p,a,c,base,nombre:p.persona||p.pregunta,net:base?(c.pos-c.neg)/base:0};
    });
  }
  function conocimientos(d){
    return d.preguntas.filter(p=>p.tipo==='conocimiento').map(p=>{
      const a=agrupar(p), c={si:0,no:0,pos:0,neg:0};
      a.grupos.forEach(g=>Object.entries(a.por[g]).forEach(([r,n])=>{ if(conoce(r)){c.si+=n; const cl=claseImagen(r); if(cl==='pos'||cl==='neg') c[cl]+=n;} else c.no+=n; }));
      const base=c.si+c.no;
      return {p,a,c,base,nombre:p.persona||p.pregunta,pc:base?c.si/base:0};
    });
  }

  // ── Vistas ─────────────────────────────────────────────────────────────
  // Tabla con color por celda (mapa de calor): filas × grupos del corte
  function tablaCalor(filas,grupos,celda){
    return `<div class="elec-tabla"><table><thead><tr><th></th><th>Total</th>${grupos.map(g=>`<th>${escH(g)}</th>`).join('')}</tr></thead><tbody>${
      filas.map(f=>`<tr><th>${escH(f.nombre)}</th>${[f.total,...f.grupos].map((c,i)=>`<td style="${c.estilo||''}">${escH(c.texto)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  }
  const tinte=(intensidad,color)=>`background:color-mix(in srgb,${color} ${Math.round(Math.max(0,Math.min(1,intensidad))*70)}%,var(--card))`;

  function vistaVoto(p){
    const a=agrupar(p);
    const resp=new Set(); a.grupos.forEach(g=>Object.keys(a.por[g]).forEach(r=>resp.add(r)));
    const valido=r=>!ELEC.validos||!NO_VALIDO.test(norm(r));
    const lista=[...resp].filter(valido);
    const totales=Object.fromEntries(lista.map(r=>[r,a.grupos.reduce((s,g)=>s+(a.por[g][r]||0),0)]));
    const base=Object.values(totales).reduce((x,y)=>x+y,0);
    lista.sort((x,y)=>totales[y]-totales[x]);
    const titulo=`<h3 class="elec-q">${escH(capPrimera(p.pregunta.replace(/^¿|\?\s*$/g,'')))}<small>${fmtN(base)} respuestas${ELEC.validos?' válidas':''}</small></h3>`;
    if(a.grupos.length>1){
      const baseG=Object.fromEntries(a.grupos.map(g=>[g,lista.reduce((s,r)=>s+(a.por[g][r]||0),0)]));
      const maxG=Object.fromEntries(a.grupos.map(g=>[g,Math.max(...lista.map(r=>(a.por[g][r]||0)/(baseG[g]||1)))]));
      const filas=lista.slice(0,15).map(r=>({nombre:cap(r),total:{texto:pct(totales[r],base)},
        grupos:a.grupos.map(g=>{const f=(a.por[g][r]||0)/(baseG[g]||1);return {texto:pct(a.por[g][r]||0,baseG[g]),estilo:tinte(maxG[g]?f/maxG[g]:0,'var(--accent)')};})}));
      return titulo+tablaCalor(filas,a.grupos,null);
    }
    const max=Math.max(...lista.map(r=>totales[r]),1);
    return titulo+`<div class="barras">${lista.slice(0,15).map((r,i)=>`<div class="brow"><span class="bname">${escH(cap(r))}</span>
      <span class="btrack"><span class="bfill ${i===0?'lider':''}" style="width:${(100*totales[r]/max).toFixed(1)}%"></span></span>
      <span class="bval"><b>${pct(totales[r],base)}</b> <small>${fmtN(totales[r])}</small></span></div>`).join('')}</div>`;
  }

  function vistaImagen(ps){
    const filas=imagenes({preguntas:ps}).sort((x,y)=>y.net-x.net);
    const grupos=filas[0]?.a.grupos||[];
    const titulo='<h3 class="elec-q">Imagen de cada persona<small>saldo = % positiva − % negativa · gris: no la conoce</small></h3>';
    if(grupos.length>1){
      const netG=(f,g)=>{const c={pos:0,neg:0,nolo:0};Object.entries(f.a.por[g]).forEach(([r,n])=>c[claseImagen(r)]+=n);const b=c.pos+c.neg+c.nolo;return b?(c.pos-c.neg)/b:0;};
      const t=filas.map(f=>({nombre:cap(f.nombre),total:{texto:pts(f.net*100),estilo:tinte(Math.abs(f.net)*1.4,f.net>=0?'var(--accent)':'var(--coral)')},
        grupos:grupos.map(g=>{const n=netG(f,g);return {texto:pts(n*100),estilo:tinte(Math.abs(n)*1.4,n>=0?'var(--accent)':'var(--coral)')};})}));
      return titulo+tablaCalor(t,grupos,null);
    }
    const ext=Math.max(...filas.map(f=>Math.max(f.c.pos,f.c.neg)/(f.base||1)),0.01);
    return titulo+`<div class="barras div">${filas.map(f=>{
      const pn=f.c.neg/(f.base||1), pp=f.c.pos/(f.base||1), pl=f.c.nolo/(f.base||1);
      return `<div class="brow"><span class="bname">${escH(cap(f.nombre))}</span>
        <span class="bdiv"><span class="bneg"><i style="width:${(100*pn/ext).toFixed(1)}%"></i></span><span class="bpos"><i style="width:${(100*pp/ext).toFixed(1)}%"></i></span></span>
        <span class="bval"><b class="${f.net>=0?'sube':'baja'}">${pts(f.net*100)}</b> <small>${dec(pp*100,0)} % pos · ${dec(pn*100,0)} % neg · ${dec(pl*100,0)} % no conoce</small></span></div>`;}).join('')}</div>`;
  }

  function vistaConocimiento(ps){
    const filas=conocimientos({preguntas:ps}).sort((x,y)=>y.pc-x.pc);
    const grupos=filas[0]?.a.grupos||[];
    const titulo='<h3 class="elec-q">Conocimiento de cada persona<small>% que la conoce · entre quienes la conocen, opinión positiva o negativa</small></h3>';
    if(grupos.length>1){
      const pcG=(f,g)=>{let s=0,t=0;Object.entries(f.a.por[g]).forEach(([r,n])=>{t+=n;if(conoce(r))s+=n;});return t?s/t:0;};
      const maxv=Math.max(...filas.flatMap(f=>[f.pc,...grupos.map(g=>pcG(f,g))]),0.01);
      const t=filas.map(f=>({nombre:cap(f.nombre),total:{texto:dec(f.pc*100,1)+' %',estilo:tinte(f.pc/maxv,'var(--accent)')},
        grupos:grupos.map(g=>{const v=pcG(f,g);return {texto:dec(v*100,1)+' %',estilo:tinte(v/maxv,'var(--accent)')};})}));
      return titulo+tablaCalor(t,grupos,null);
    }
    return titulo+`<div class="barras">${filas.map(f=>{
      const op=f.c.pos+f.c.neg;
      return `<div class="brow"><span class="bname">${escH(cap(f.nombre))}</span>
        <span class="btrack"><span class="bfill" style="width:${(100*f.pc).toFixed(1)}%"></span></span>
        <span class="bval"><b>${dec(f.pc*100,1)} %</b> <small>${op?dec(100*f.c.pos/op,0)+' % positiva · '+dec(100*f.c.neg/op,0)+' % negativa · ':''}base ${fmtN(f.base)}</small></span></div>`;}).join('')}</div>`;
  }

  // ── Exposición ─────────────────────────────────────────────────────────
  window.cargarResumen=cargar;
  document.addEventListener('electoral:geo',cargar);
})();
