// Administración de usuarios y consulta del log de accesos. SOLO rol admin.
//   GET  /api/usuarios?action=list
//   POST /api/usuarios?action=create   {email, password, nombre?, rol}
//   POST /api/usuarios?action=update   {id, nombre?, rol?, activo?, password?}
//   POST /api/usuarios?action=delete   {id}
//   GET  /api/usuarios?action=accesos  &q= &resultado=exito|fallo &desde=YYYY-MM-DD &hasta=YYYY-MM-DD &limit= &offset=
// Identidad y contraseña viven en Supabase Auth (Admin API); rol/activo/nombre en c98_get.usuarios.
import { query, queryOne, paramBag } from './_lib/db.js';
import { requireAdmin, ROLES } from './_lib/auth.js';
import { authAdmin, AuthAdminError } from './_lib/supabase_admin.js';

const BAN_PERMANENTE = '876000h'; // ~100 años
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const FECHA = /^\d{4}-\d{2}-\d{2}$/;

class HttpError extends Error {
  constructor(msg, code = 400) { super(msg); this.code = code; }
}

const clip = (v, n) => String(v ?? '').trim().slice(0, n);

function validarRol(rol) {
  if (!ROLES.includes(rol)) throw new HttpError(`Rol inválido (${ROLES.join(', ')})`);
  return rol;
}
function validarPassword(p) {
  if (typeof p !== 'string' || p.length < 8) throw new HttpError('La contraseña debe tener al menos 8 caracteres');
  return p;
}

/** Cuántos admins activos quedarían si este usuario deja de serlo. */
async function otrosAdminsActivos(id) {
  const r = await queryOne(
    `SELECT count(*)::int AS n FROM c98_get.usuarios WHERE rol = 'admin' AND activo AND user_id <> $1`, [id]);
  return r.n;
}

async function listar() {
  const usuarios = await query(
    `SELECT user_id AS id, email, nombre, rol, activo, ultimo_login, created_at
     FROM c98_get.usuarios ORDER BY lower(email)`);
  return { ok: true, usuarios, roles: ROLES };
}

async function crear(body) {
  const email = clip(body.email, 254).toLowerCase();
  if (!EMAIL.test(email)) throw new HttpError('Correo inválido');
  const rol = validarRol(body.rol || 'analista');
  const password = validarPassword(body.password);
  const nombre = clip(body.nombre, 120) || null;
  let creado;
  try {
    creado = await authAdmin('POST', '', { email, password, email_confirm: true });
  } catch (e) {
    if (e instanceof AuthAdminError && (e.status === 422 || e.status === 409)) throw new HttpError('Ya existe un usuario con ese correo', 409);
    throw e;
  }
  try {
    await query(
      `INSERT INTO c98_get.usuarios (user_id, email, nombre, rol) VALUES ($1, $2, $3, $4)
       ON CONFLICT (user_id) DO UPDATE SET nombre = EXCLUDED.nombre, rol = EXCLUDED.rol, activo = true`,
      [creado.id, email, nombre, rol]);
  } catch (e) {
    await authAdmin('DELETE', `/${creado.id}`).catch(() => {}); // no dejar un usuario sin perfil
    throw e;
  }
  return { ok: true, id: creado.id };
}

async function actualizar(body, yo) {
  const id = String(body.id || '');
  if (!UUID.test(id)) throw new HttpError('id inválido');
  const actual = await queryOne('SELECT rol, activo FROM c98_get.usuarios WHERE user_id = $1', [id]);
  if (!actual) throw new HttpError('Usuario no encontrado', 404);

  const set = [];
  const bag = paramBag();
  const authBody = {};
  if (body.nombre !== undefined) set.push(`nombre = ${bag.add(clip(body.nombre, 120) || null)}`);
  if (body.rol !== undefined) {
    validarRol(body.rol);
    set.push(`rol = ${bag.add(body.rol)}`);
  }
  if (body.activo !== undefined) {
    const activo = body.activo === true || body.activo === 'true';
    set.push(`activo = ${bag.add(activo)}`);
    authBody.ban_duration = activo ? 'none' : BAN_PERMANENTE; // el ban de Supabase corta también el refresh token
  }
  if (body.password !== undefined && body.password !== '') authBody.password = validarPassword(body.password);

  const nuevoRol = body.rol !== undefined ? body.rol : actual.rol;
  const nuevoActivo = body.activo !== undefined ? (body.activo === true || body.activo === 'true') : actual.activo;
  const pierdeAdmin = actual.rol === 'admin' && actual.activo && !(nuevoRol === 'admin' && nuevoActivo);
  if (pierdeAdmin) {
    if (id === yo.id) throw new HttpError('No puedes quitarte el rol de administrador ni desactivarte a ti mismo', 409);
    if ((await otrosAdminsActivos(id)) === 0) throw new HttpError('Debe quedar al menos un administrador activo', 409);
  }
  if (!set.length && !Object.keys(authBody).length) throw new HttpError('Nada que actualizar');

  if (Object.keys(authBody).length) await authAdmin('PUT', `/${id}`, authBody);
  if (set.length) await query(`UPDATE c98_get.usuarios SET ${set.join(', ')} WHERE user_id = ${bag.add(id)}`, bag.params);
  return { ok: true };
}

async function eliminar(body, yo) {
  const id = String(body.id || '');
  if (!UUID.test(id)) throw new HttpError('id inválido');
  if (id === yo.id) throw new HttpError('No puedes eliminarte a ti mismo', 409);
  const actual = await queryOne('SELECT rol, activo FROM c98_get.usuarios WHERE user_id = $1', [id]);
  if (!actual) throw new HttpError('Usuario no encontrado', 404);
  if (actual.rol === 'admin' && actual.activo && (await otrosAdminsActivos(id)) === 0) {
    throw new HttpError('Debe quedar al menos un administrador activo', 409);
  }
  try {
    await authAdmin('DELETE', `/${id}`);
  } catch (e) {
    if (!(e instanceof AuthAdminError && e.status === 404)) throw e; // ya no existía en Auth
  }
  await query('DELETE FROM c98_get.usuarios WHERE user_id = $1', [id]); // normalmente ya cayó por ON DELETE CASCADE
  return { ok: true };
}

async function accesos(q) {
  const bag = paramBag();
  const where = [];
  const texto = clip(q.q, 120);
  if (texto) {
    const p = bag.add(`%${texto.replace(/[\\%_]/g, '\\$&')}%`);
    where.push(`(email ILIKE ${p} OR ip ILIKE ${p})`);
  }
  if (q.resultado === 'exito') where.push('exito');
  else if (q.resultado === 'fallo') where.push('NOT exito');
  if (q.desde) {
    if (!FECHA.test(q.desde)) throw new HttpError('Fecha «desde» inválida');
    where.push(`ocurrido_en >= (${bag.add(q.desde)}::date::timestamp AT TIME ZONE 'America/Bogota')`);
  }
  if (q.hasta) {
    if (!FECHA.test(q.hasta)) throw new HttpError('Fecha «hasta» inválida');
    where.push(`ocurrido_en < ((${bag.add(q.hasta)}::date + 1)::timestamp AT TIME ZONE 'America/Bogota')`);
  }
  const w = where.length ? `WHERE ${where.join(' AND ')}` : '';
  const limit = Math.min(Math.max(parseInt(q.limit, 10) || 50, 1), 200);
  const offset = Math.max(parseInt(q.offset, 10) || 0, 0);

  const total = await queryOne(`SELECT count(*)::int AS n FROM c98_get.accesos_log ${w}`, bag.params);
  const filas = await query(
    `SELECT id, ocurrido_en, user_id, email, exito, motivo, ip, user_agent
     FROM c98_get.accesos_log ${w} ORDER BY ocurrido_en DESC, id DESC
     LIMIT ${limit} OFFSET ${offset}`, bag.params);
  const resumen = await queryOne(
    `SELECT count(*) FILTER (WHERE exito)::int AS exitos_24h, count(*) FILTER (WHERE NOT exito)::int AS fallos_24h
     FROM c98_get.accesos_log WHERE ocurrido_en > now() - interval '24 hours'`);
  return { ok: true, total: total.n, limit, offset, resumen, accesos: filas };
}

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  const yo = await requireAdmin(req, res);
  if (!yo) return;
  const action = (req.query && req.query.action) || '';
  const body = req.body || {};
  try {
    const post = req.method === 'POST';
    let out;
    if (action === 'list' && !post) out = await listar();
    else if (action === 'accesos' && !post) out = await accesos(req.query || {});
    else if (action === 'create' && post) out = await crear(body);
    else if (action === 'update' && post) out = await actualizar(body, yo);
    else if (action === 'delete' && post) out = await eliminar(body, yo);
    else throw new HttpError('Acción no reconocida');
    return res.json(out);
  } catch (e) {
    if (e.code === '42P01') {
      return res.status(500).json({ ok: false, error: 'Falta aplicar la migración tools/supabase/migraciones/001_usuarios_accesos.sql' });
    }
    const code = e instanceof HttpError ? e.code : (e instanceof AuthAdminError && e.status < 500 ? 502 : 500);
    return res.status(code).json({ ok: false, error: e.message });
  }
}
