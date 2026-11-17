// Se inyecta en cada página (excepto login): si no hay sesión, redirige al login.
// Las páginas no contienen datos; los datos solo salen de /api, que exige la cookie de sesión.
(function () {
  var root = document.documentElement;
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
    })
    .catch(function () { root.style.visibility = ''; });
})();
