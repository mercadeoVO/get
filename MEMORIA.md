# C98_MEDFUT — Memoria del proyecto

> Punto de entrada para retomar el trabajo en una sesión futura sin releer todo el historial.
> Última actualización: 2026-07-30 (día de la consolidación de C98_GEO + MEDFUT en esta carpeta).

## Qué es esto

Carpeta consolidada que une los dos proyectos que antes vivían separados:

- **`app/`** — GET (antes MEDFUT), la aplicación activa (PHP + MariaDB) de ingesta y análisis de leads de
  Meta Ads. Antes vivía en `C:\xampp\htdocs\medfut`. **Ese path sigue existiendo como symlink**
  apuntando aquí (`app/`) — Apache lo sigue sirviendo en `http://localhost/medfut/` sin ningún
  cambio de configuración. No borrar el symlink.
- **`geo/`** — C98_GEO, la capa geográfica maestra de Colombia (BD `c98_geo`, MariaDB) que
  alimenta a `app/` vía JOIN cross-BD. Antes vivía en `C:\Users\impag\Documents\C98_GEO`.

Ambas bases de datos (`medfut` y `c98_geo`) siguen en la misma instancia MariaDB
(`127.0.0.1:3307`) — la consolidación es solo de archivos, no de bases de datos.

## Documentación por subproyecto (no duplicar aquí)

- `app/CLAUDE.md` — contexto técnico de GET (antes MEDFUT): esquema de `import_log_leads`, convenciones,
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
   en `leads_importer.php`) y se descarta el texto crudo. Backup del histórico previo a este
   cambio en la tabla `medfut.import_log_leads_geo_bkp` (77.980 filas, snapshot 2026-07-28).
2. **La extracción automática de ubicación desde `adset_name`/`campaign_name`/`ad_name` es
   opt-in, apagada por defecto.** El usuario decidió explícitamente que el sistema no debe
   adivinar geografía en silencio — hay un toggle en el wizard de `leads_import.html`
   ("🧭 Extracción automática de ubicación") donde se elige columna origen y niveles.
3. **`medfut.dim_geo` está ELIMINADA** (era de otra era del proyecto, con datos desincronizados
   de `c98_geo`). Cualquier código que la referencie es un bug remanente, no una fuente válida.
4. **`c98_geo` no se migró a adjacency-list.** El modelo real es 7 tablas dimensionales
   encadenadas por FK real al padre inmediato (`dim_region → ... → dim_barrio`). `geo_geometry`
   sigue con FK polimórfica sin constraint de motor.
5. **Cobertura de barrio/comuna limitada a 5 ciudades >1M hab.** (Bogotá, Medellín, Cali,
   Barranquilla, Cartagena). Expandir a más municipios es una decisión de alcance no tomada
   (ver `geo/docs/procesos.md`, proceso P9).
6. **`http://localhost/medfut/`** redirige automáticamente a `leads_analytics.html`
   (`app/index.php`) — es la página de entrada del sitio.
7. **Marca renombrada MEDFUT → GET (2026-10-30), solo en lo visible.** Cambiaron los `<title>`,
   encabezados/logos/footers de los HTML de `app/`, el texto de `api.php` y la documentación.
   **Quedaron sin cambio a propósito:** la URL `/medfut/` (y la junction `C:\xampp\htdocs\medfut`),
   la carpeta `C98_MEDFUT`, la BD `C98_MEDFUT`, nombres de archivo (ej. `cross_db_medfut.md`),
   comentarios de código y la skill `c98geo-data-analyst`. Un renombre completo (URL, BD, carpetas)
   sigue pendiente, pero ya no hay worktrees que lo bloqueen (ver decisión 8).
8. **Un solo árbol (2026-10-30).** Se cerraron los 3 worktrees (`anuncios-url-datos`,
   `csv-upload-error`, `unificar-diseno-proyecto`): `master` avanzó por fast-forward a la rama
   `unificar-diseno-proyecto` (que ya contenía a `csv-upload-error`) y las 3 ramas `claude/*`
   se eliminaron por estar fusionadas. `C:\xampp\htdocs\medfut` apunta ahora (junction) a
   `C98_MEDFUT\app` de `master`, ya no a un worktree. Lo que estaba sin commitear quedó respaldado
   en ramas `respaldo/*-20261030` (estado de `master`, y cambios sueltos de los worktrees `csv` y
   `anuncios`); no se integró porque eran borradores anteriores a lo que ya tiene `master`.

## Pendiente real (no repetir reconocimiento — ver `geo/docs/procesos.md` para el detalle)

- **Lado `geo/`:** FK real/constraint en `geo_geometry`, `SPATIAL INDEX(geom)`, vista
  `v_integridad_geo`, test CI anti-huérfanos, limpieza de las tablas `*_bkp*` acumuladas,
  seguimiento de la reforma territorial de Antioquia (9→17 subregiones, aún no vigente), estado
  del piloto Power BI (`geo/MED/piloto.pbix`, no auditado), todo lo de demografía/audiencias
  digitales (bloqueado hasta cerrar Fase 6 de integridad).
- **Lado `app/`:** decidir qué hacer con `import_log_leads_geo_bkp` (¿por cuánto tiempo
  conservarlo?), y evaluar si vale la pena repoblar `id_geo` retroactivamente para leads
  importados antes de que existiera `autoParseLeadGeo()` (no hay forma de recuperar texto geo
  crudo ya descartado, pero sí se podría re-parsear desde `adset_name`/`campaign_name` si
  todavía están guardados en esas filas).
- **Compartido:** el backup en `C:\Users\impag\Documents\Claude\MEDFUT\SOFTWARE` quedó
  desactualizado respecto al estado post-consolidación — decidir si se sincroniza o se
  descontinúa ahora que `C98_MEDFUT` es la ubicación canónica.

## Estructura tras la consolidación (2026-07-30)

```
C:\Users\impag\Documents\C98_MEDFUT\
├── MEMORIA.md                  ← este archivo
├── LECCIONES_APRENDIDAS.md     ← lecciones del lado app/MEDFUT
├── app\                        ← ex C:\xampp\htdocs\medfut (symlink activo en esa ruta original)
└── geo\                        ← ex C:\Users\impag\Documents\C98_GEO
```

`C:\Users\impag\Documents\C98_GEO` (la carpeta original) quedó como cascarón vacío — no se pudo
eliminar en la sesión que hizo la mudanza porque era el directorio de trabajo activo del propio
agente en ese momento. Se puede borrar manualmente una vez cerrada esa sesión.
