/* Visor — navegación compartida, tema claro/oscuro y helpers de color para gráficos.
   Uso: <div id="v-nav" data-page="analitica" data-refresh="load()"></div> + <script src="visor.js"> */
(function(){
  var PAGES = [
    ['segmentacion', 'Segmentación', 'leads_segmentacion.html'],
    ['analitica',    'Analítica',    'leads_analytics.html'],
    ['electoral',    'Electoral',    'dashboard_local.html'],
    ['importar',     'Importar',     'leads_import.html'],
    ['temas',        'Temas pendientes', 'leads_temas_pendientes.html'],
    ['geografia',    'Geografía',    'c98geo_dashboard.html']
  ];
  var KEY = 'visor-theme';
  var root = document.documentElement;

  function saved(){ try { return localStorage.getItem(KEY); } catch(e){ return null; } }
  function systemDark(){ return window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches; }
  function current(){ return root.getAttribute('data-theme') || (systemDark() ? 'dark' : 'light'); }

  // Se aplica antes de pintar para evitar el destello claro/oscuro.
  if (saved()) root.setAttribute('data-theme', saved());

  var SUN  = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  var MOON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></svg>';
  var SYNC = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';

  function setTheme(t){
    root.setAttribute('data-theme', t);
    try { localStorage.setItem(KEY, t); } catch(e){}
    paintToggle();
    document.dispatchEvent(new CustomEvent('visor:theme', {detail:t}));
  }
  function paintToggle(){
    var t = current();
    document.querySelectorAll('.v-theme button').forEach(function(b){
      b.setAttribute('aria-pressed', String(b.dataset.t === t));
    });
  }

  function build(){
    var host = document.getElementById('v-nav');
    if (!host) return;
    var page = host.dataset.page, refresh = host.dataset.refresh;
    var links = PAGES.map(function(p){
      return '<a class="v-link" href="'+p[2]+'"'+(p[0]===page?' aria-current="page"':'')+'>'+p[1]+'</a>';
    }).join('');
    host.className = 'v-nav';
    host.innerHTML =
      '<a class="v-brand" href="leads_segmentacion.html"><span class="v-dots"><i></i><i></i><i></i></span>GET</a>' +
      '<nav class="v-links" aria-label="Secciones">'+links+'</nav>' +
      '<div class="v-tools">' +
        (refresh ? '<button type="button" class="v-refresh" id="v-refresh">'+SYNC+'Actualizar</button>' : '') +
        '<div class="v-theme" role="group" aria-label="Tema">' +
          '<button type="button" data-t="light" aria-label="Tema claro">'+SUN+'</button>' +
          '<button type="button" data-t="dark" aria-label="Tema oscuro">'+MOON+'</button>' +
        '</div>' +
      '</div>';
    host.querySelectorAll('.v-theme button').forEach(function(b){
      b.addEventListener('click', function(){ setTheme(b.dataset.t); });
    });
    var r = document.getElementById('v-refresh');
    if (r) r.addEventListener('click', function(){ new Function(refresh)(); });
    paintToggle();
  }

  // Colores del tema actual, para Chart.js y SVG: V.css('--text')
  window.V = {
    css: function(n){ return getComputedStyle(root).getPropertyValue(n).trim(); },
    // Serie categórica: azul de marca primero, coral y amarillo como contraste.
    palette: function(){
      return ['--accent','--coral','--sky','--yellow','--cyan','--purple','--c-bar','--c-zona','--c-subr','--small','--c-com','--c-dpto']
        .map(function(n){ return V.css(n); });
    },
    onTheme: function(fn){ document.addEventListener('visor:theme', fn); }
  };

  var ICONS = {"folder": "<path d=\"M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z\"/>", "file": "<path d=\"M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z\"/><path d=\"M14 2v4a2 2 0 0 0 2 2h4\"/>", "check": "<circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"m9 12 2 2 4-4\"/>", "x": "<circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"m15 9-6 6\"/><path d=\"m9 9 6 6\"/>", "alert": "<path d=\"m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3\"/><path d=\"M12 9v4\"/><path d=\"M12 17h.01\"/>", "ban": "<circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"m4.9 4.9 14.2 14.2\"/>", "search": "<circle cx=\"11\" cy=\"11\" r=\"8\"/><path d=\"m21 21-4.3-4.3\"/>", "trash": "<path d=\"M3 6h18\"/><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6\"/><path d=\"M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"/>", "refresh": "<path d=\"M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8\"/><path d=\"M21 3v5h-5\"/><path d=\"M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16\"/><path d=\"M8 16H3v5\"/>", "minus": "<path d=\"M5 12h14\"/>", "clock": "<circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M12 6v6l4 2\"/>", "vote": "<path d=\"m9 11 3 3L22 4\"/><path d=\"M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11\"/>", "list": "<path d=\"M8 6h13\"/><path d=\"M8 12h13\"/><path d=\"M8 18h13\"/><path d=\"M3 6h.01\"/><path d=\"M3 12h.01\"/><path d=\"M3 18h.01\"/>"};
  // Iconos de trazo (familia única, 24x24). Uso: V.icon('check', 16)
  window.V.icon = function(n, s){ s = s || 16;
    return '<svg class="ic" width="'+s+'" height="'+s+'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(ICONS[n]||'')+'</svg>'; };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
