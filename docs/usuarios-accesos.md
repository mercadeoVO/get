# Usuarios, roles y registro de accesos (web en Vercel)

Solo aplica a la web (Vercel + Supabase). La app local PHP no tiene login.

## Diseño

**Identidad** sigue en Supabase Auth (correo + contraseña, cookies HttpOnly, ver `docs/vercel.md`).
**Perfil de aplicación** nuevo en `c98_get.usuarios` (1:1 con `auth.users`): rol, activo, nombre, último login.
**Log** nuevo en `c98_get.accesos_log`: un registro por intento de login.

```
login ─► /api/auth ─► Supabase Auth (valida contraseña) ─► perfilDe(): ¿existe en c98_get.usuarios y activo?
             └────────► registrarAcceso() ─► c98_get.accesos_log (éxito/fallo, motivo, IP, user-agent)
/admin.html ─► /api/usuarios (requireAdmin) ─► Supabase Auth Admin API + c98_get.usuarios / accesos_log
```

### Tablas (migración `tools/supabase/migraciones/001_usuarios_accesos.sql`)

| Tabla | Columnas clave |
|---|---|
| `c98_get.usuarios` | `user_id` (PK, FK a `auth.users` ON DELETE CASCADE), `email` (único, sin distinguir mayúsculas), `nombre`, `rol`, `activo`, `ultimo_login`, `created_at`, `updated_at` |
| `c98_get.accesos_log` | `id`, `ocurrido_en`, `user_id` (sin FK: el historial sobrevive al borrado), `email`, `exito`, `motivo`, `ip`, `user_agent` |

`motivo` (solo en fallos): `credenciales`, `inactivo`, `no_permitido` (fuera de `ALLOWED_EMAILS`), `error`.
Ambas tablas con RLS activado y sin políticas: solo el backend (`DATABASE_URL`) las lee/escribe.

### Roles

| Rol | Hoy puede |
|---|---|
| `admin` | Todo lo de `analista` + gestionar usuarios + ver accesos |
| `analista` | Ver los datos (lo que ya podían todos los usuarios) |
| `lector` | Igual que `analista` hoy; reservado para cuando existan funciones de escritura |

Solo `admin` se evalúa en el servidor (`requireAdmin` en `api/_lib/auth.js`); no basta con ocultar el botón.

### Reglas de negocio

- Desactivar = `activo=false` **y** ban en Supabase Auth (`ban_duration`), así no se puede renovar el token. Los tokens de acceso vigentes dejan de servir al instante porque cada petición consulta `activo`.
- No puedes eliminarte, desactivarte ni quitarte el rol admin a ti mismo, y siempre debe quedar ≥1 admin activo.
- Eliminar borra el usuario en Supabase Auth (el perfil cae por CASCADE); el historial de accesos se conserva.
- Usuarios ya existentes en Supabase y sin fila de perfil se aprovisionan solos en su primer uso: `admin` si su correo está en `ADMIN_EMAILS`, si no `analista`. La migración además hace backfill de los actuales.
- Si la migración aún no está aplicada, el login y los datos siguen funcionando (modo previo); `/api/usuarios` responde pidiendo aplicarla.

## Endpoints (`api/usuarios.js`, solo admin: 401 sin sesión, 403 sin rol)

| Método y acción | Cuerpo / parámetros |
|---|---|
| `GET ?action=list` | — |
| `POST ?action=create` | `{email, password (≥8), nombre?, rol}` → 409 si el correo existe |
| `POST ?action=update` | `{id, nombre?, rol?, activo?, password?}` |
| `POST ?action=delete` | `{id}` |
| `GET ?action=accesos` | `q` (correo o IP), `resultado=exito\|fallo`, `desde`/`hasta` (YYYY-MM-DD, zona Bogotá), `limit` (≤200), `offset` |

`/api/auth?action=me` ahora devuelve también `rol` y `nombre`; `login` devuelve `rol`.

## UI

`/admin.html` (`web/admin.html`): pestañas **Usuarios** (crear, editar, activar/desactivar, eliminar, rol) y **Accesos** (filtros, paginación, totales de 24 h). Los administradores ven un enlace «Administración» en todas las páginas (`web/auth-guard.js`).

## Puesta en marcha (orden)

1. Aplicar `tools/supabase/migraciones/001_usuarios_accesos.sql` (SQL Editor de Supabase, o `psql "$SUPABASE_DB_URL" -f ...`). Idempotente.
2. Variables nuevas en Vercel:
   - `SUPABASE_SERVICE_ROLE_KEY` — clave **secreta** (Settings → API Keys → *service_role* / *secret*). Solo servidor; sin ella no se pueden crear/editar/eliminar usuarios.
   - `ADMIN_EMAILS` — tu correo (y el de otros admins iniciales).
3. Desplegar. Entra con tu correo: queda como admin. (Alternativa por SQL: `UPDATE c98_get.usuarios SET rol='admin' WHERE lower(email)=lower('tu@correo.com');`)
4. Abre `/admin.html`.

## Límites conocidos

- No hay bloqueo por intentos fallidos; el log permite detectarlos (filtro «Fallo»). Siguiente paso razonable: límite por IP/correo consultando `accesos_log`, o el rate-limit de Supabase Auth.
- El log solo cubre logins por la web (no cada petición ni el logout).
- No se purga `accesos_log`; si crece, borrar por fecha (`DELETE ... WHERE ocurrido_en < now() - interval '1 year'`).
- Crear usuarios exige contraseña inicial definida por el admin (no hay correo de invitación).
