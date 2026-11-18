// /api/auth?action=login|logout|me
import { loginConPassword, setSessionCookies, clearSessionCookies, usuarioActual } from './_lib/auth.js';
import { registrarAcceso } from './_lib/accesos.js';

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  const action = (req.query && req.query.action) || '';
  try {
    if (action === 'login' && req.method === 'POST') {
      const { email, password } = req.body || {};
      if (!email || !password) return res.status(400).json({ ok: false, error: 'Falta correo o contraseña' });
      const correo = String(email).trim();
      let r;
      try {
        r = await loginConPassword(correo, String(password));
      } catch (e) {
        await registrarAcceso(req, { email: correo, exito: false, motivo: 'error' });
        throw e;
      }
      if (r.error) {
        await registrarAcceso(req, { email: correo, userId: r.user?.id, exito: false, motivo: r.motivo });
        return res.status(401).json({ ok: false, error: r.error });
      }
      await registrarAcceso(req, { email: r.session.user.email, userId: r.session.user.id, exito: true });
      setSessionCookies(req, res, r.session);
      return res.json({ ok: true, email: r.session.user.email, rol: r.perfil.rol });
    }
    if (action === 'logout') {
      clearSessionCookies(req, res);
      return res.json({ ok: true });
    }
    if (action === 'me') {
      const u = await usuarioActual(req, res);
      if (!u) return res.status(401).json({ ok: false, error: 'No autenticado' });
      return res.json({ ok: true, email: u.email, rol: u.rol, nombre: u.nombre || null });
    }
    return res.status(400).json({ ok: false, error: 'Acción no reconocida' });
  } catch (e) {
    return res.status(500).json({ ok: false, error: e.message });
  }
}
