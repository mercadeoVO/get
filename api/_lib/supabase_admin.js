// Cliente mínimo de la Admin API de Supabase Auth (/auth/v1/admin/users). Requiere SUPABASE_SERVICE_ROLE_KEY,
// una clave SECRETA: solo se usa en el servidor, nunca se envía al navegador.
export class AuthAdminError extends Error {
  constructor(msg, status) { super(msg); this.status = status; }
}

export async function authAdmin(method, path, body) {
  const url = (process.env.SUPABASE_URL || '').replace(/\/+$/, '');
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY || '';
  if (!url || !key) throw new AuthAdminError('Falta SUPABASE_SERVICE_ROLE_KEY en el servidor', 500);
  const r = await fetch(`${url}/auth/v1/admin/users${path}`, {
    method,
    headers: { apikey: key, Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new AuthAdminError(j.msg || j.message || j.error_description || `Supabase Auth ${r.status}`, r.status);
  return j;
}
