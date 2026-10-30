# GET — Contexto para Claude Code

> **Renombre de marca (2026-10-30):** el producto antes llamado **MEDFUT** ahora se llama
> **GET**. El cambio es solo de nombre visible (títulos, encabezados, logo del menú y
> documentación). La URL `/medfut/`, la carpeta `C98_MEDFUT`, la BD `C98_MEDFUT` y los nombres
> de archivo siguen igual a propósito, para no romper Apache ni favoritos.

## Qué es este proyecto

Sistema de ingesta y análisis de leads de Meta Ads (Facebook/Instagram) para
operaciones de marketing político en Colombia. Corre sobre XAMPP (Apache + MariaDB).

**Stack:**
- PHP 8.2 + PDO (MariaDB 10.x en XAMPP, puerto 3307)
- MariaDB: base `C98_MEDFUT` (antes `medfut`, renombrada 2026-07-30) + base `c98_geo` (misma instancia, cross-BD)
- HTML/JS vanilla (sin frameworks) para los dashboards
- Sin Composer en producción — todo vanilla PHP

**URL local:** `http://localhost/medfut/`

> **Ubicación real de los archivos (desde 2026-07-30):**
> `C:\Users\impag\Documents\C98_MEDFUT\app\`. `C:\xampp\htdocs\medfut` sigue existiendo como
> **symlink** hacia esa carpeta — Apache y la URL de arriba no cambiaron. Editar en cualquiera
> de las dos rutas edita el mismo archivo. Ver `../MEMORIA.md` para el contexto de la mudanza y
> del proyecto hermano `../geo/` (C98_GEO).

---

## Conexión a BD

`config.php` — define `getDB(): PDO`:
```
host=127.0.0.1  port=3307  dbname=C98_MEDFUT  user=root  pass=''
```

Para queries cross-BD se usa el prefijo `c98_geo.tabla` directamente en SQL.

---

## Bases de datos

### `C98_MEDFUT` (BD, antes `medfut`) — tablas principales

| Tabla | Descripción |
|-------|-------------|
| `import_log` | Log de cada importación (archivo Excel + metadatos) |
| `import_log_leads` | Leads individuales (PK = `id VARCHAR(64)`) |
| `import_log_leads_respuestas` | Respuestas EAV a preguntas dinámicas del formulario |
| `import_log_leads_preguntas` | Catálogo normalizado de preguntas (SMALLINT PK) |
| `import_electoral` | Resultados electorales normalizados (1 fila por candidato × pregunta) |
| `dim_participacion` | Dimensión de participaciones electorales |
| `dim_candidato` | Dimensión de candidatos |

**`import_log_leads` columnas clave:**
```sql
id VARCHAR(64) PK          -- Meta lead ID o cédula (deduplicación)
import_id INT FK           -- → import_log.id
id_geo VARCHAR(15) NULL    -- Código DANE jerárquico → c98_geo (ver abajo)
nombre, email, telefono    -- Datos de contacto
sexo, rango_edad, estrato  -- Segmentación demográfica
afiliacion_politica, nivel_educativo
geo_departamento, subregion, municipio, zona, comuna_localidad, barrio -- texto raw
ad_id, adset_id, campaign_id, form_id, platform, is_organic          -- Meta metadata
```

**`dim_geo` fue ELIMINADA.** El campo `id_geo` migró de INT FK a `VARCHAR(15)` con
código DANE de `c98_geo`.

### `c98_geo` — jerarquía geográfica Colombia (BD separada, mismo servidor)

Tablas y sus PKs (VARCHAR, códigos DANE jerárquicos):

| Tabla | PK | Ejemplo |
|-------|----|---------|
| `dim_departamento` | `id VARCHAR(2)` | `'05'` (Antioquia) |
| `dim_subregion` | `id VARCHAR(10)` | `'05SR01'` |
| `dim_municipio` | `id VARCHAR(5)` | `'05001'` (Medellín) |
| `dim_zona` | `id VARCHAR(8)` | `'05001Z01'` |
| `dim_comuna` | `id VARCHAR(11)` | `'05001Z01C01'` |
| `dim_barrio` | `id VARCHAR(15)` | `'05001Z01C01B001'` |

**Relaciones:**
```
dim_barrio.id_comuna → dim_comuna.id
dim_comuna.id_zona → dim_zona.id
dim_zona.id_municipio → dim_municipio.id
dim_municipio.id_departamento → dim_departamento.id
dim_municipio.id_subregion → dim_subregion.id (nullable)
```

**Estrategia `LEFT(id_geo, N)` para queries analytics:**
```sql
LEFT JOIN c98_geo.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)
LEFT JOIN c98_geo.dim_municipio    m    ON m.id    = LEFT(l.id_geo, 5)
LEFT JOIN c98_geo.dim_zona         z    ON z.id    = LEFT(l.id_geo, 8)   AND CHAR_LENGTH(l.id_geo) >= 8
LEFT JOIN c98_geo.dim_comuna       c    ON c.id    = LEFT(l.id_geo, 11)  AND CHAR_LENGTH(l.id_geo) >= 11
LEFT JOIN c98_geo.dim_barrio       b    ON b.id    = l.id_geo            AND CHAR_LENGTH(l.id_geo) = 15
```

---

## Archivos principales

### Backend PHP

| Archivo | Descripción |
|---------|-------------|
| `config.php` | Conexión BD (`getDB()`) |
| `leads_importer.php` | API principal (~2600 líneas). Acciones: `check`, `get_sheets`, `preview`, `import`, `analytics`, `stats`, `logs`, `dims`, `set_etapa`, `segment_dims`, `segmentar`, `segmento_export`, etc. |
| `ExcelReaderPhp.php` | Lector XLSX nativo (ZipArchive + SimpleXML). Reemplaza Python/openpyxl. |
| `c98geo_api.php` | API de jerarquía geográfica (lookups, autocompletar) |
| `c98geo_map_api.php` | API para mapa geográfico |
| `api.php` | API general / misc |

### Frontends HTML

| Archivo | Descripción |
|---------|-------------|
| `leads_import.html` | UI de importación de Excel → BD |
| `leads_analytics.html` | Dashboard de análisis de leads (cobertura, geo, demografía) |
| `leads_segmentacion.html` | Constructor de consultas cruzadas (demografía × geo × política × respuestas EAV) + mapa Leaflet coropleta/contorno + export CSV. Ver `docs/segmentacion.md`. |
| `c98geo_dashboard.html` | Explorer de jerarquía geográfica c98_geo |
| `dashboard_local.html` | Dashboard general |

### Scripts de migración (ejecutar 1 vez en browser)

| Archivo | Descripción |
|---------|-------------|
| `_migration_id_lead.php` | Migración de PK a VARCHAR(64) |
| `_geo_migrate_id_geo.php` | Fase 1: poblar id_geo INT desde dim_geo |
| `_geo2_diag.php` | Diagnóstico de mapeo dim_geo → c98_geo |
| `_geo2_migrate.php` | Migración id_geo INT → VARCHAR(15) c98_geo |
| `_geo2_drop_dimgeo.php` | DROP TABLE dim_geo (ya ejecutado) |
| `_geo_drop_text_cols.php` | DROP columnas texto geo de import_log_leads (ya ejecutado) |

---

## Patrones de código clave

### Normalización de cadenas para comparación SQL sin tildes
```php
function _nsql(string $col): string {
    return "UPPER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        $col,'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ñ','n')))";
}
// Uso: WHERE _nsql('m.nombre') = _nsql('?')
```

### Normalización PHP de strings geo
```php
function normalizeGeoStr(string $s): string {
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',/* ... */];
    return strtoupper(trim(str_replace(array_keys($map), array_values($map), $s)));
}
```

### resolveGeoId() — resolución geo a c98_geo (en leads_importer.php)
Toma campos `['barrio', 'zona', 'comuna_localidad', 'municipio']` del lead y
retorna el `id` c98_geo más granular disponible (P1=barrio, P2=comuna, P2b=zona, P3=municipio).
Devuelve `?string` (VARCHAR(15)) o `null`.

### ExcelReaderPhp — lector XLSX
```php
$reader = new PhpXlsxReader('/path/file.xlsx');
$reader->getSheets()           // → [['name'=>'Sheet1','rows'=>100,'cols'=>10], ...]
$reader->readRows('Sheet1', 21) // → [['col1','col2',...], ['v1','v2',...], ...]
$reader->close();
// Wrapper convenience:
$sheets = xlsxGetSheets($path);            // → array
$result = xlsxReadRows($path, $sheet, 21); // → ['ok'=>true,'rows'=>[...]]
```

---

## Decisiones de arquitectura tomadas

1. **Sin dim_geo** — eliminada. Geo resuelto via `c98_geo` (cross-BD JOIN).
2. **id_geo como código DANE VARCHAR(15)** — permite queries por jerarquía con `LEFT()`.
3. **PHP nativo para XLSX** — `ExcelReaderPhp.php` usando ZipArchive; sin Python, sin Composer.
4. **PK de leads = VARCHAR(64)** — soporta Meta IDs, cédulas, UUIDs.
5. **EAV para preguntas dinámicas** — `import_log_leads_respuestas` + catálogo `import_log_leads_preguntas`.
6. **Enriquecimiento geo desde adset_name** — `parseAdsetForGeo()` extrae municipio/zona/sexo/edad del nombre del conjunto de anuncios y rellena campos vacíos.

---

## Estado actual

- `dim_geo` → **ELIMINADA** (migración completada 100%)
- `id_geo` → `VARCHAR(15)` con código DANE de `c98_geo`
- 53,381 leads remapeados a c98_geo con 100% de cobertura
- Python eliminado como dependencia — Excel se lee con `ExcelReaderPhp.php`
- `leads_import.html` funcional: sin alerta de Python
- `leads_analytics.html` funcional: JOINs a c98_geo para todas las vistas geo

---

## Cómo correr

1. XAMPP corriendo (Apache en 80, MySQL en 3307)
2. BD `C98_MEDFUT` y `c98_geo` existentes en MariaDB
3. Abrir `http://localhost/medfut/leads_import.html` para importar
4. Abrir `http://localhost/medfut/leads_analytics.html` para analytics
5. Para Claude Code: `cd C:\xampp\htdocs\medfut` y luego `claude`

---

## Variables de entorno / Configuración sensible

`config.php` tiene credenciales hardcodeadas para desarrollo local:
- `DB_PASS = ''` (sin contraseña — sólo local)
- No hay `.env` — si se mueve a producción, refactorizar.
