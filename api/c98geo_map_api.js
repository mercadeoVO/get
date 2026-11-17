// Port de app/c98geo_map_api.php — geometrías reales para el mapa (PostGIS).
// Uso: /c98geo_map_api.php?tipo=departamento&resolution=mid[&padre=05]
// La respuesta se escribe en trozos: un GeoJSON nacional supera el límite de 4,5 MB de Vercel
// para respuestas no streaming.
import { getPool, paramBag } from './_lib/db.js';
import { requireUser } from './_lib/auth.js';

const G = 'c98_geo';
const NIVELES = {
  region:       { tabla: 'dim_region',       padres: [] },
  departamento: { tabla: 'dim_departamento', padres: ['id_region'] },
  subregion:    { tabla: 'dim_subregion',    padres: ['id_departamento'] },
  municipio:    { tabla: 'dim_municipio',    padres: ['id_departamento', 'id_subregion'] },
  zona:         { tabla: 'dim_zona',         padres: ['id_municipio'] },
  comuna:       { tabla: 'dim_comuna',       padres: ['id_zona'] },
  barrio:       { tabla: 'dim_barrio',       padres: ['id_comuna'] },
};
const TABLA_POR_FK = {
  id_region: 'dim_region', id_departamento: 'dim_departamento', id_subregion: 'dim_subregion',
  id_municipio: 'dim_municipio', id_zona: 'dim_zona', id_comuna: 'dim_comuna',
};
const RES_FALLBACK = {
  region: ['low', 'mid', 'high'], departamento: ['low', 'mid', 'high'], subregion: ['low', 'mid', 'high'],
  municipio: ['mid', 'high', 'low'], zona: ['high'], comuna: ['high'], barrio: ['high'],
};
// Comparación sin mayúsculas ni tildes (MariaDB lo hacía con la collation utf8mb4_unicode_ci)
const norm = (x) => `extensions.unaccent(lower(${x}))`;

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-cache');
  if (!(await requireUser(req, res))) return;
  try {
    const q = req.query || {};
    const tipo = q.tipo || '';
    if (!NIVELES[tipo]) throw new Error(`tipo inválido: ${tipo}`);
    const cfg = NIVELES[tipo];
    const resSolicitada = q.resolution || RES_FALLBACK[tipo][0];
    const padre = q.padre ?? null;
    const nombre = q.nombre || null;
    let padreNombre = q.padre_nombre || null;
    const idExacto = q.id || null;

    let idsLista = [];
    if (q.ids) {
      idsLista = [...new Set(String(q.ids).split(',').map((s) => s.trim()).filter((s) => /^[0-9A-Za-z]+$/.test(s)))].slice(0, 800);
    }
    let punto = null;
    if (q.punto) {
      const p = String(q.punto).split(',');
      if (p.length === 2 && p.every((x) => x.trim() !== '' && Number.isFinite(Number(x)))) punto = [Number(p[0]), Number(p[1])];
    }

    let padreCampo = null;
    if (padre !== null && cfg.padres.length > 0) {
      padreCampo = cfg.padres.includes(q.padre_campo) ? q.padre_campo : cfg.padres[0];
    }

    const pool = getPool();
    const disp = (await pool.query(`SELECT DISTINCT resolution FROM ${G}.geo_geometry WHERE tipo = $1`, [tipo]))
      .rows.map((r) => r.resolution);
    let resolution = disp.includes(resSolicitada) ? resSolicitada : (RES_FALLBACK[tipo][0] || 'high');
    if (!disp.includes(resolution) && disp.length > 0) resolution = disp[0];

    let joinPadre = '';
    const campoPN = padreCampo ?? cfg.padres[0] ?? null;
    if (padreNombre !== null && campoPN !== null && TABLA_POR_FK[campoPN]) {
      joinPadre = ` JOIN ${G}.${TABLA_POR_FK[campoPN]} pe ON pe.id = e.${campoPN} `;
    } else {
      padreNombre = null;
    }

    const bag = paramBag();
    let sql = `
      SELECT e.id AS id, e.nombre AS nombre, extensions.ST_AsGeoJSON(g.geom) AS geojson,
             g.centroid_lat AS lat, g.centroid_lon AS lon
      FROM ${G}.${cfg.tabla} e
      JOIN ${G}.geo_geometry g ON g.id_tipo = e.id::text AND g.tipo = ${bag.add(tipo)} AND g.resolution = ${bag.add(resolution)}
      ${joinPadre}
      WHERE g.geom IS NOT NULL`;

    if (padreNombre !== null) {
      const a = bag.add(padreNombre), b = bag.add(padreNombre);
      sql += ` AND (${norm('pe.nombre')} LIKE '%' || ${norm(a + '::text')} || '%' OR ${norm(b + '::text')} LIKE '%' || ${norm('pe.nombre')} || '%')`;
    }
    if (padreCampo !== null) sql += ` AND e.${padreCampo}::text = ${bag.add(String(padre))}`;
    if (nombre !== null) {
      const a = bag.add(nombre), b = bag.add(nombre);
      sql += ` AND (${norm('e.nombre')} LIKE '%' || ${norm(a + '::text')} || '%' OR ${norm(b + '::text')} LIKE '%' || ${norm('e.nombre')} || '%')`;
    }
    if (idExacto !== null) sql += ` AND e.id::text = ${bag.add(String(idExacto))}`;
    if (idsLista.length) sql += ` AND e.id::text = ANY(${bag.add(idsLista)}::text[])`;
    if (punto) {
      sql += ` AND extensions.ST_Contains(g.geom, extensions.ST_SetSRID(extensions.ST_MakePoint(${bag.add(punto[0])}::float8, ${bag.add(punto[1])}::float8), 4326))`;
    }
    sql += ' ORDER BY e.nombre';

    const rows = (await pool.query(sql, bag.params)).rows;

    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.write(`{"ok":true,"tipo":${JSON.stringify(tipo)},"resolution":${JSON.stringify(resolution)},"count":${rows.length},"data":{"type":"FeatureCollection","features":[`);
    let first = true;
    for (const r of rows) {
      const props = {
        id: r.id, nombre: r.nombre,
        lat: r.lat !== null ? Number(r.lat) : null, lon: r.lon !== null ? Number(r.lon) : null,
      };
      res.write((first ? '' : ',') + `{"type":"Feature","properties":${JSON.stringify(props)},"geometry":${r.geojson}}`);
      first = false;
    }
    res.end(']}}');
  } catch (e) {
    if (!res.headersSent) res.status(400).json({ ok: false, error: e.message });
    else res.end();
  }
}
