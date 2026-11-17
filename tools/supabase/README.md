# Copia de GET a Supabase

Copia **a Supabase** (Postgres) las bases locales `C98_GET` y `c98_geo`. La MariaDB local solo se lee; nunca se modifica.

## Conexión

| | Origen (local) | Destino (Supabase) |
|---|---|---|
| Motor | MariaDB, `127.0.0.1:3307`, usuario `root` | Postgres 17, Session pooler (puerto 5432) |
| Credenciales | las de `app/config.php` | `SUPABASE_DB_URL` |
| Schemas | `c98_get`, `c98_geo` | `c98_get`, `c98_geo` (mismos nombres) |

1. Copia `.env.supabase.example` a `.env.supabase` en la raíz del repo y pon la URI real. **No pongas corchetes** alrededor de la contraseña.
2. `.env.supabase` está en `.gitignore`. No lo subas ni lo pegues en chats. Como alternativa, define la variable de entorno `SUPABASE_DB_URL`.
3. Dependencias: `python -m pip install --user psycopg2-binary pymysql`.

`conexion.py` centraliza las dos conexiones (`conectar_mysql()`, `conectar_pg()`); los demás scripts la importan.

## Scripts (orden de ejecución)

Se ejecutan desde la raíz del repo, p. ej. `python tools/supabase/copy_datos.py`.

1. `copy_datos.py` — crea tablas y copia datos. Opciones: `--schema-only`, `--only c98_geo`, `--tables geo_geometry`, `--reset`.
   **Cuidado:** vuelve a crear (DROP + CREATE) cada tabla que copia, solo en Supabase.
2. `indices_vistas.py` — índices (incluido GiST sobre `geo_geometry.geom`) y las vistas `v_import_resumen` y `v_participacion_historica`. Es idempotente.
3. `claves_foraneas.py` — las 19 claves foráneas, con las mismas reglas ON DELETE/UPDATE que en MariaDB. Es idempotente.

## Qué se omite a propósito

- Tablas `*_bkp*` y `bak_*`.
- Índice FULLTEXT de `dim_geo` (tabla deprecada) y longitudes de prefijo de índices.
- Fechas `0000-00-00` de MariaDB pasan a `NULL`.
- `geo_geometry` no tiene FK (su `id_tipo` es polimórfico, igual que en local).

## Diferencias a recordar

- MariaDB compara texto sin distinguir mayúsculas; Postgres sí. Usa `ILIKE` o `lower()`.
- La copia es una foto: los datos locales siguen cambiando. Para refrescar, `copy_datos.py --reset` y luego los otros dos scripts.
- Tamaño al 2026-11-17: ~345 MB de 500 MB del plan Free.
