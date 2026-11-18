-- GET · Usuarios con rol + registro de accesos (log de logins)
-- Idempotente: se puede ejecutar varias veces (SQL Editor de Supabase, o psql con SUPABASE_DB_URL).
-- Requiere el schema c98_get (ya existe) y auth.users (lo crea Supabase).
--
-- Modelo:
--   c98_get.usuarios     perfil de aplicación 1:1 con auth.users (rol, activo, nombre, último login).
--                        La contraseña y la identidad siguen en Supabase Auth; aquí solo va lo de GET.
--   c98_get.accesos_log  un registro por intento de login (éxito o fallo), con IP y user-agent.
-- Las tablas solo las toca el backend (DATABASE_URL). RLS activado y sin políticas: anon/authenticated
-- no pueden leerlas aunque el schema se exponga por PostgREST.

BEGIN;

CREATE SCHEMA IF NOT EXISTS c98_get;

-- ── usuarios ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS c98_get.usuarios (
  user_id      uuid PRIMARY KEY REFERENCES auth.users (id) ON DELETE CASCADE,
  email        text        NOT NULL,
  nombre       text,
  rol          text        NOT NULL DEFAULT 'analista',
  activo       boolean     NOT NULL DEFAULT true,
  ultimo_login timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'usuarios_rol_chk'
                 AND conrelid = 'c98_get.usuarios'::regclass) THEN
    ALTER TABLE c98_get.usuarios
      ADD CONSTRAINT usuarios_rol_chk CHECK (rol IN ('admin', 'analista', 'lector'));
  END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS usuarios_email_uq ON c98_get.usuarios (lower(email));
CREATE INDEX IF NOT EXISTS usuarios_rol_activo_idx ON c98_get.usuarios (rol, activo);

-- ── accesos_log ─────────────────────────────────────────────────────────────
-- Sin FK a propósito: el historial debe sobrevivir al borrado del usuario, y los intentos fallidos
-- con correos inexistentes no tienen user_id.
CREATE TABLE IF NOT EXISTS c98_get.accesos_log (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  ocurrido_en  timestamptz NOT NULL DEFAULT now(),
  user_id      uuid,
  email        text        NOT NULL,
  exito        boolean     NOT NULL,
  motivo       text,                       -- NULL si exito; 'credenciales' | 'inactivo' | 'no_permitido' | 'error'
  ip           text,
  user_agent   text
);

CREATE INDEX IF NOT EXISTS accesos_log_fecha_idx   ON c98_get.accesos_log (ocurrido_en DESC);
CREATE INDEX IF NOT EXISTS accesos_log_email_idx   ON c98_get.accesos_log (lower(email), ocurrido_en DESC);
CREATE INDEX IF NOT EXISTS accesos_log_exito_idx   ON c98_get.accesos_log (exito, ocurrido_en DESC);

-- ── updated_at automático ───────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION c98_get.tg_usuarios_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS usuarios_updated_at ON c98_get.usuarios;
CREATE TRIGGER usuarios_updated_at BEFORE UPDATE ON c98_get.usuarios
  FOR EACH ROW EXECUTE FUNCTION c98_get.tg_usuarios_updated_at();

-- ── Seguridad ───────────────────────────────────────────────────────────────
ALTER TABLE c98_get.usuarios    ENABLE ROW LEVEL SECURITY;
ALTER TABLE c98_get.accesos_log ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    REVOKE ALL ON c98_get.usuarios, c98_get.accesos_log FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    REVOKE ALL ON c98_get.usuarios, c98_get.accesos_log FROM authenticated;
  END IF;
END $$;

-- ── Backfill: los usuarios que ya existen en Supabase Auth conservan su acceso ──
-- Entran como 'analista' (lo mismo que podían hacer hasta ahora). Los desactivados (banned) quedan inactivos.
INSERT INTO c98_get.usuarios (user_id, email, rol, activo)
SELECT u.id, lower(u.email), 'analista',
       NOT (u.banned_until IS NOT NULL AND u.banned_until > now())
FROM auth.users u
WHERE u.email IS NOT NULL
ON CONFLICT (user_id) DO NOTHING;

COMMIT;

-- ── PASO MANUAL (una sola vez): nombrar al primer administrador ─────────────
-- Opción A (recomendada): variable ADMIN_EMAILS en Vercel; el primer login de ese correo queda como admin.
-- Opción B: SQL directo (cambia el correo y ejecuta):
--   UPDATE c98_get.usuarios SET rol = 'admin' WHERE lower(email) = lower('tu-correo@dominio.com');
