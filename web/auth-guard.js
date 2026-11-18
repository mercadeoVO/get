// Se inyecta en cada página (excepto login): si no hay sesión, redirige al login.
// Las páginas no contienen datos; los datos solo salen de /api, que exige la cookie de sesión.
(function () {
  var root = document.documentElement;
  // Engranaje de administración en la barra superior (junto al selector de tema), solo para admins.
  // La barra la construye visor.js al cargar el DOM; se espera a que exista.
  function engranaje() {
    var GEAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>';
    var css = document.createElement('style');
    css.textContent =
      '.v-gear{position:relative;display:inline-flex}' +
      '.v-gear>button{width:44px;height:44px;border:0;border-radius:50%;background:var(--card);color:var(--muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center}' +
      '.v-gear>button:hover,.v-gear>button[aria-expanded="true"]{background:var(--accent-soft);color:var(--text)}' +
      '.v-gear svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
      '.v-gear-menu{position:absolute;right:0;top:52px;min-width:190px;padding:6px;border-radius:14px;background:var(--card);' +
      'border:1px solid var(--line,rgba(128,128,128,.25));box-shadow:0 8px 24px rgba(0,0,0,.35);z-index:99999;display:none}' +
      '.v-gear-menu.on{display:block}' +
      '.v-gear-menu a{display:block;padding:10px 14px;border-radius:10px;color:var(--text);text-decoration:none;font-size:14px}' +
      '.v-gear-menu a:hover{background:var(--accent-soft)}';
    document.head.appendChild(css);

    function montar() {
      var tools = document.querySelector('.v-tools');
      if (!tools || tools.querySelector('.v-gear')) return !!tools;
      var w = document.createElement('div');
      w.className = 'v-gear';
      w.innerHTML = '<button type="button" aria-label="Administración" aria-haspopup="true" aria-expanded="false">' + GEAR + '</button>' +
        '<div class="v-gear-menu" role="menu"><a role="menuitem" href="/admin.html#usuarios">Usuarios</a>' +
        '<a role="menuitem" href="/admin.html#accesos">Historial de accesos</a></div>';
      var btn = w.firstChild, menu = w.lastChild;
      btn.onclick = function (e) {
        e.stopPropagation();
        var on = menu.classList.toggle('on');
        btn.setAttribute('aria-expanded', String(on));
      };
      document.addEventListener('click', function () { menu.classList.remove('on'); btn.setAttribute('aria-expanded', 'false'); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') menu.classList.remove('on'); });
      tools.appendChild(w);
      return true;
    }
    if (montar()) return;
    var n = 0, t = setInterval(function () { if (montar() || ++n > 40) clearInterval(t); }, 100);
  }

  root.style.visibility = 'hidden';
  fetch('/api/auth?action=me', { credentials: 'same-origin' })
    .then(function (r) {
      if (r.status === 401) {
        location.replace('/login.html?next=' + encodeURIComponent(location.pathname + location.search));
        return null;
      }
      return r.json();
    })
    .then(function (j) {
      root.style.visibility = '';
      if (!j || !j.ok) return;
      var b = document.createElement('button');
      b.textContent = 'Salir (' + j.email + ')';
      b.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:99999;padding:6px 10px;border-radius:8px;' +
        'border:1px solid #30363d;background:#161b22;color:#8b949e;font:12px system-ui;cursor:pointer;opacity:.85';
      b.onclick = function () {
        fetch('/api/auth?action=logout', { credentials: 'same-origin' }).then(function () { location.replace('/login.html'); });
      };
      document.body.appendChild(b);
      if (j.rol === 'admin') engranaje();
    })
    .catch(function () { root.style.visibility = ''; });
})();
