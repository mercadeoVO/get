# GET en Vercel + Supabase (fase 1: solo lectura)

La versión web convive con la local: `app/` (PHP + MariaDB) no se toca. Vercel sirve el mismo frontend y
reemplaza los endpoints PHP por funciones Node que leen de Supabase.

```
navegador ──► Vercel (public/*.html)            ← app/*.html|js|css copiados por scripts/build-web.mjs
          └─► /api/* (Node)  ──► Supabase Postgres (schemas c98_get, c98_geo)
                  └─ cookie de sesión HttpOnly ◄─ Supabase Auth (correo + contraseña)
```

## Qué está portado

| Endpoint PHP (la URL no cambia, `vercel.json` la reescribe) | Función |
|---|---|
| `leads_importer.php?action=` `check`, `stats`, `logs`, `analytics`, `segment_dims`, `segmentar`, `segmento_export`, `segmento_preguntas`, `segmento_respuestas`, `respuestas_muestra`, `buscar_respuestas` | `api/leads_importer.js` |
| `c98geo_api.php` (árbol geográfico) | `api/c98geo_api.js` |
| `c98geo_map_api.php` (GeoJSON, PostGIS) | `api/c98geo_map_api.js` |
| login / logout / sesión | `api/auth.js` |
| usuarios, roles y log de accesos | `api/usuarios.js` |

**No portado (sigue solo en local):** importar Excel, editar/borrar importaciones, temas, `get_dims`,
`municipios_lista`, `drive_scan`, (el buscador inteligente ya está portado). La web responde un error claro.

## Variables de entorno en Vercel

| Variable | Valor |
|---|---|
| `DATABASE_URL` | Supabase → Connect → **Transaction pooler** (puerto 6543). Es secreta. |
| `SUPABASE_URL` | `https://<ref>.supabase.co` |
| `SUPABASE_ANON_KEY` | clave pública *anon* / *publishable* (Settings → API Keys) |
| `SUPABASE_SERVICE_ROLE_KEY` | clave secreta para administrar usuarios desde `/admin.html` (ver `docs/usuarios-accesos.md`) |
| `ADMIN_EMAILS` | correos que quedan como administrador al primer login |
| `ALLOWED_EMAILS` | opcional: correos permitidos, separados por comas |
| `ANTHROPIC_API_KEY` | para el buscador inteligente (`api/nl_search.js`) |

## Acceso

- Los usuarios se administran en `/admin.html` (solo rol admin): crear, editar, rol, desactivar, eliminar y log de accesos. Ver `docs/usuarios-accesos.md`. (También se pueden crear en Supabase → Authentication → Users.)
- Desactiva el registro libre: Authentication → Sign In / Providers → Email → *Allow new users to sign up* = off.
- Las páginas HTML no traen datos; los datos solo salen de `/api`, que exige la cookie de sesión.

## Probar en local

```bash
npm install
node scripts/build-web.mjs          # genera public/
# .env.web (no se versiona) con DATABASE_URL, SUPABASE_URL, SUPABASE_ANON_KEY
node scripts/dev-server.mjs         # http://localhost:3000
```

Solo en local, `DEV_NO_AUTH=1` salta el login (se ignora si existe `VERCEL`).

## Diferencias con MariaDB ya resueltas

- Comparaciones y agrupaciones sin distinguir mayúsculas/tildes (`utf8mb4_unicode_ci`) se replican con `unaccent(lower())`.
- Las fechas `0000-00-00` quedaron `NULL` en la copia (el KPI «primera fecha» y el mes `0000-00` del timeline ya no aparecen).
- Respuestas grandes (CSV y GeoJSON) se envían por trozos para esquivar el límite de 4,5 MB de Vercel.
