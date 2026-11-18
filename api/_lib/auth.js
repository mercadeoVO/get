// Sesión con Supabase Auth guardada en cookies HttpOnly.
// Variables de entorno: SUPABASE_URL, SUPABASE_ANON_KEY (públicas por diseño) y, opcional,
// ALLOWED_EMAILS (lista separada por comas: si existe, solo esos correos pueden entrar) y ADMIN_EMAILS
// (correos que quedan como administrador al aprovisionarse su perfil en c98_get.usuarios).
// El rol y el estado activo viven en c98_get.usuarios (ver tools/supabase/migraciones/001_usuarios_accesos.sql).
import { query, queryOne } from './db.js';

export const ROLES = ['admin', 'analista', 'lector'];
const AT = 'sb_at';
const RT = 'sb_rt';

function env() {
  const url = (process.env.SUPABASE_URL || '').replace(/\/+$/, '');
  const anon = process.env.SUPABASE_ANON_KEY || '';
  if (!url || !anon) throw new Error('Faltan SUPABASE_URL / SUPABASE_ANON_KEY');
  return { url, anon };
}

export function parseCookies(req) {
  const out = {};
  for (const part of (req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

function secureFlag(req) {
  const proto = req.headers['x-forwarded-proto'];
  return proto ? proto.split(',')[0] === 'https' : false;
}

export function setSessionCookies(req, res, session) {
  const sec = secureFlag(req) ? '; Secure' : '';
  const base = `Path=/; HttpOnly; SameSite=Lax${sec}`;
  res.setHeader('Set-Cookie', [
    `${AT}=${encodeURIComponent(session.access_token)}; ${base}; Max-Age=${session.expires_in || 3600}`,
    `${RT}=${encodeURIComponent(session.refresh_token)}; ${base}; Max-Age=${60 * 60 * 24 * 30}`,
  ]);
}

export function clearSessionCookies(req, res) {
  const sec = secureFlag(req) ? '; Secure' : '';
  const base = `Path=/; HttpOnly; SameSite=Lax${sec}; Max-Age=0`;
  res.setHeader('Set-Cookie', [`${AT}=; ${base}`, `${RT}=; ${base}`]);
}

function emailPermitido(user) {
  const lista = (process.env.ALLOWED_EMAILS || '')
    .split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
  return lista.length === 0 || lista.includes((user.email || '').toLowerCase());
}

function adminEmails() {
  return (process.env.ADMIN_EMAILS || '').split(',').map((x) => x.trim().toLowerCase()).filter(Boolean);
}

/**
 * Perfil de aplicación (rol / activo) del usuario de Supabase Auth.
 * - Si aún no tiene fila en c98_get.usuarios, se crea: 'admin' si está en ADMIN_EMAILS, si no 'analista'.
 * - Si la tabla no existe (migración sin aplicar) se cae al comportamiento previo para no tumbar la web.
 */
export async function perfilDe(user) {
  const email = (user.email || '').toLowerCase();
  const esAdminEnv = adminEmails().includes(email);
  try {
    const row = await queryOne(
      'SELECT rol, activo, nombre FROM c98_get.usuarios WHERE user_id = $1', [user.id]);
    if (row) return row;
    const rol = esAdminEnv ? 'admin' : 'analista';
    await query(
      `INSERT INTO c98_get.usuarios (user_id, email, rol) VALUES ($1, $2, $3)
       ON CONFLICT DO NOTHING`, [user.id, email, rol]);
    const nuevo = await queryOne(
      'SELECT rol, activo, nombre FROM c98_get.usuarios WHERE user_id = $1', [user.id]);
    return nuevo || { rol, activo: true, nombre: null };
  } catch (e) {
    if (e.code === '42P01') return { rol: esAdminEnv ? 'admin' : 'analista', activo: true, nombre: null, sinTabla: true };
    throw e;
  }
}

async function userDeToken(token) {
  const { url, anon } = env();
  const r = await fetch(`${url}/auth/v1/user`, {
    headers: { apikey: anon, Authorization: `Bearer ${token}` },
  });
  if (!r.ok) return null;
  return r.json();
}

export async function loginConPassword(email, password) {
  const { url, anon } = env();
  const r = await fetch(`${url}/auth/v1/token?grant_type=password`, {
    method: 'POST',
    headers: { apikey: anon, 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (j.error_code === 'user_banned') return { error: 'Usuario desactivado', motivo: 'inactivo' };
    return { error: j.error_description || j.msg || 'Credenciales inválidas', motivo: 'credenciales' };
  }
  const user = j.user || {};
  if (!emailPermitido(user)) return { error: 'Este usuario no tiene acceso', motivo: 'no_permitido', user };
  const perfil = await perfilDe(user);
  if (!perfil.activo) return { error: 'Usuario desactivado', motivo: 'inactivo', user };
  return { session: j, perfil };
}

async function refrescar(refreshToken) {
  const { url, anon } = env();
  const r = await fetch(`${url}/auth/v1/token?grant_type=refresh_token`, {
    method: 'POST',
    headers: { apikey: anon, 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: refreshToken }),
  });
  return r.ok ? r.json() : null;
}

/** Devuelve el usuario autenticado o null (renueva las cookies si el token expiró). */
export async function usuarioActual(req, res) {
  const c = parseCookies(req);
  if (c[AT]) {
    const u = await userDeToken(c[AT]);
    if (u && emailPermitido(u)) {
      const p = await perfilDe(u);
      if (p.activo) return { ...u, rol: p.rol, nombre: p.nombre };
    }
  }
  if (c[RT]) {
    const s = await refrescar(c[RT]);
    if (s && emailPermitido(s.user || {})) {
      const p = await perfilDe(s.user);
      if (p.activo) {
        setSessionCookies(req, res, s);
        return { ...s.user, rol: p.rol, nombre: p.nombre };
      }
    }
  }
  return null;
}

/** Guardia para los endpoints de datos: responde 401 y devuelve null si no hay sesión. */
export async function requireUser(req, res) {
  // Solo para pruebas locales: Vercel siempre define VERCEL, así que aquí nunca se salta la sesión en producción.
  if (process.env.DEV_NO_AUTH === '1' && !process.env.VERCEL) return { email: 'dev@local', rol: 'admin' };
  const u = await usuarioActual(req, res);
  if (!u) {
    res.status(401).json({ ok: false, error: 'No autenticado' });
    return null;
  }
  return u;
}

/** Guardia de administración: 401 sin sesión, 403 si el rol no es admin. */
export async function requireAdmin(req, res) {
  const u = await requireUser(req, res);
  if (!u) return null;
  if (u.rol !== 'admin') {
    res.status(403).json({ ok: false, error: 'Solo administradores' });
    return null;
  }
  return u;
}
