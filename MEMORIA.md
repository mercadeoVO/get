# C98_GET — Memoria del proyecto

> Punto de entrada para retomar el trabajo en una sesión futura sin releer todo el historial.
> Última actualización: 2026-10-30 (renombre MEDFUT → GET y cierre de los worktrees).
>
> **Nota de nombres:** el proyecto se llama **GET** (antes MEDFUT). La carpeta raíz todavía se
> llama `C98_MEDFUT` en disco — ver decisión 9 para completar ese último renombre.

## Qué es esto

Carpeta consolidada que une los dos proyectos que antes vivían separados:

- **`app/`** — GET (antes MEDFUT), la aplicación activa (PHP + MariaDB) de ingesta y análisis de
  leads de Meta Ads. Antes vivía en `C:\xampp\htdocs\medfut`. Hoy `C:\xampp\htdocs\get` es una
  **junction** hacia `app/`; Apache la sirve en `http://localhost/get/`. No borrar la junction.
- **`geo/`** — C98_GEO, la capa geográfica maestra de Colombia (BD `c98_geo`, MariaDB) que
  alimenta a `app/` vía JOIN cross-BD. Antes vivía en `C:\Users\impag\Documents\C98_GEO`.

Dos bases de datos en la misma instancia MariaDB (`127.0.0.1:3307`): **`C98_GET`** (la app; antes
`medfut`, luego `C98_MEDFUT`) y **`c98_geo`** (la capa geográfica).

## Documentación por subproyecto (no duplicar aquí)

- `app/CLAUDE.md` — contexto técnico de GET: esquema de `import_log_leads`, convenciones,
  patrones de código.
- `geo/CLAUDE.md` — briefing original de C98_GEO + estado real (sección 0).
- `geo/README.md` — punto de entrada rápido de C98_GEO.
- `geo/docs/diccionario_datos.md` — el documento más largo y más importante de la parte geo:
  hallazgos, correcciones, fuentes externas, decisiones y por qué.
- `geo/docs/procesos.md` — división de C98_GEO en procesos con estado.
- `geo/docs/lecciones_aprendidas.md` — patrones de error específicos de la carga geoespacial
  (padding DIVIPOLA, falsos positivos de encoding, matching aproximado vs. id exacto, etc.).
- `LECCIONES_APRENDIDAS.md` (este directorio) — lecciones del lado GET/app, complementa a la
  de `geo/` sin repetirla.

## Decisiones ya tomadas — no volver a discutir

1. **`import_log_leads` ya NO persiste texto geo crudo** (region/geo_departamento/subregion/
   municipio/zona/comuna_localidad/barrio fueron eliminadas el 2026-07-28). `id_geo`
   (VARCHAR(15), código DANE) es la única fuente de verdad geográfica en esa tabla — se resuelve
   en memoria al importar (`reconcileLeadGeo()` + `autoParseLeadGeo()` opt-in + `resolveGeoId()`
   en `leads_importer.php`) y se descarta el texto crudo. La tabla de respaldo original
   (`import_log_leads_geo_bkp`) ya no existe; hoy hay `C98_GET.import_log_leads_geo_fix64_bkp`.
2. **La extracción automática de ubicación desde `adset_name`/`campaign_name`/`ad_name` es
   opt-in, apagada por defecto.** El usuario decidió explícitamente que el sistema no debe
   adivinar geografía en silencio — hay un toggle en el wizard de `leads_import.html`
   ("🧭 Extracción automática de ubicación") donde se elige columna origen y niveles.
3. **`C98_GET.dim_geo` está deprecada** (era de otra era del proyecto, con datos desincronizados
   de `c98_geo`). La tabla sigue existiendo físicamente (la usa la vista
   `v_participacion_historica`) pero ningún código nuevo debe leerla; la geografía sale de `c98_geo`.
4. **`c98_geo` no se migró a adjacency-list.** El modelo real es 7 tablas dimensionales
   encadenadas por FK real al padre inmediato (`dim_region → ... → dim_barrio`). `geo_geometry`
   sigue con FK polimórfica sin constraint de motor.
5. **Cobertura de barrio/comuna limitada a 5 ciudades >1M hab.** (Bogotá, Medellín, Cali,
   Barranquilla, Cartagena). Expandir a más municipios es una decisión de alcance no tomada
   (ver `geo/docs/procesos.md`, proceso P9).
6. **`http://localhost/get/`** redirige automáticamente a `leads_analytics.html`
   (`app/index.php`) — es la página de entrada del sitio.
7. **Marca renombrada MEDFUT → GET (2026-10-30).** `<title>`, encabezados, logo del menú
   (`visor.js`), footers, texto de `api.php` y documentación dicen GET.
8. **Un solo árbol (2026-10-30).** Se cerraron los 3 worktrees (`anuncios-url-datos`,
   `csv-upload-error`, `unificar-diseno-proyecto`): `master` avanzó por fast-forward a la rama
   `unificar-diseno-proyecto` (que ya contenía a `csv-upload-error`) y las 3 ramas `claude/*`
   se eliminaron por estar fusionadas. Lo que estaba sin commitear quedó respaldado en ramas
   `respaldo/*-20261030` (estado de `master`, y cambios sueltos de los worktrees `csv` y
   `anuncios`); no se integró porque eran borradores anteriores a lo que ya tiene `master`.
9. **Renombre completo a GET (2026-10-30), salvo la carpeta raíz.** Hecho: URL `/medfut/` →
   `/get/` (junction `C:\xampp\htdocs\get`; la vieja `medfut` se eliminó y da 404), BD
   `C98_MEDFUT` → `C98_GET` (14 tablas + 2 vistas con `RENAME TABLE`, `config.php` actualizado),
   archivo `cross_db_medfut.md` → `cross_db_get.md`, y los textos/rutas que decían medfut.
   **Pendiente (manual):** la carpeta `C:\Users\impag\Documents\C98_MEDFUT` no se pudo renombrar
   porque hay sesiones de Claude Code con ella abierta (Windows bloquea el renombre). Con **todas**
   las sesiones cerradas, ejecutar en PowerShell:
   ```powershell
   Rename-Item C:\Users\impag\Documents\C98_MEDFUT C98_GET
   cmd /c rmdir C:\xampp\htdocs\get
   cmd /c mklink /J C:\xampp\htdocs\get C:\Users\impag\Documents\C98_GET\app
   ```
   y después sustituir `Documents\C98_MEDFUT` por `Documents\C98_GET` en los `.md` que citan la
   ruta (`app/CLAUDE.md`, `geo/CLAUDE.md`, `geo/README.md`, `geo/.claude/skills/.../SKILL.md`,
   este archivo). No tocar el path externo `C:\Users\impag\Documents\Claude\MEDFUT\SOFTWARE`
   (backup viejo, es otra carpeta).
10. **Regla de trabajo (2026-11-24): todo cambio se hace primero en local.** Cualquier cambio de código,
    esquema o configuración se aplica y se prueba primero en la máquina del usuario
    (`C:\Users\impag\Documents\C98_MEDFUT`). Si la sesión corre en la nube y no tiene esa carpeta
    conectada (Remote Control), el cambio se entrega como parche listo para `git apply`, con las
    instrucciones, y **no** se hace push, PR, despliegue en Vercel ni migración en Supabase por cuenta
    propia: solo cuando el usuario lo apruebe de forma explícita («sube»). Las pruebas en la nube
    (sin MariaDB ni los archivos reales) no reemplazan la prueba local del usuario.

## Pendiente real (no repetir reconocimiento — ver `geo/docs/procesos.md` para el detalle)

- **Lado `geo/`:** FK real/constraint en `geo_geometry`, `SPATIAL INDEX(geom)`, vista
  `v_integridad_geo`, test CI anti-huérfanos, limpieza de las tablas `*_bkp*` acumuladas,
  seguimiento de la reforma territorial de Antioquia (9→17 subregiones, aún no vigente), estado
  del piloto Power BI (`geo/MED/piloto.pbix`, no auditado), todo lo de demografía/audiencias
  digitales (bloqueado hasta cerrar Fase 6 de integridad).
- **Lado `app/`:** decidir qué hacer con `import_log_leads_geo_fix64_bkp` (¿por cuánto tiempo
  conservarlo?) y las ramas `respaldo/*-20261030` y stashes que quedaron del cierre de worktrees.
- **Compartido:** el backup en `C:\Users\impag\Documents\Claude\MEDFUT\SOFTWARE` quedó
  desactualizado respecto al estado actual — decidir si se sincroniza o se descontinúa ahora
  que esta carpeta es la ubicación canónica.

## Estructura (2026-10-30)

```
C:\Users\impag\Documents\C98_MEDFUT\      ← pasará a C98_GET (decisión 9)
├── MEMORIA.md                  ← este archivo
├── LECCIONES_APRENDIDAS.md     ← lecciones del lado app/GET
├── app\                        ← ex C:\xampp\htdocs\medfut (junction activa: C:\xampp\htdocs\get)
└── geo\                        ← ex C:\Users\impag\Documents\C98_GEO
```

`C:\Users\impag\Documents\C98_GEO` (la carpeta original) quedó como cascarón vacío — no se pudo
eliminar porque era el directorio de trabajo activo del propio agente. Se puede borrar
manualmente una vez cerradas las sesiones.
