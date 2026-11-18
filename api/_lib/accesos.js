// Registro de intentos de login en c98_get.accesos_log. Nunca lanza: un fallo del log no debe impedir el login.
import { query } from './db.js';

export function ipDe(req) {
  const xff = String(req.headers['x-forwarded-for'] || '').split(',')[0].trim();
  return (xff || String(req.headers['x-real-ip'] || '') || req.socket?.remoteAddress || '').slice(0, 64) || null;
}

export async function registrarAcceso(req, { email, userId = null, exito, motivo = null }) {
  try {
    await query(
      `INSERT INTO c98_get.accesos_log (user_id, email, exito, motivo, ip, user_agent)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      [userId, String(email || '').trim().toLowerCase().slice(0, 254), !!exito, exito ? null : motivo,
        ipDe(req), String(req.headers['user-agent'] || '').slice(0, 400) || null]);
    if (exito && userId) {
      await query('UPDATE c98_get.usuarios SET ultimo_login = now() WHERE user_id = $1', [userId]);
    }
  } catch (e) {
    console.error('accesos_log:', e.message);
  }
}
