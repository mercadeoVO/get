// Port (solo lectura) de app/leads_importer.php a Postgres/Supabase.
// Acciones portadas: check, stats, analytics, logs, segment_dims, segmentar, segmento_export,
// segmento_preguntas, segmento_respuestas, respuestas_muestra, buscar_respuestas.
// Las demás (importar, editar, borrar…) siguen solo en la versión local con PHP.
import { query, queryOne, getPool } from './_lib/db.js';
import { requireUser } from './_lib/auth.js';
import { buildSegmentWhere, SEGMENT_GEO_NIVELES, ORDEN_EDAD, catQuery, ci } from './_lib/segment.js';

const T = 'c98_get';
const G = 'c98_geo';
const N = (rows) => rows.map((r) => ({ val: r.val, cnt: Number(r.cnt) }));

function filtrosDe(body) {
  const b = body || {};
  if (typeof b.filtros === 'string') {
    try { const f = JSON.parse(b.filtros); return f && typeof f === 'object' ? f : {}; } catch { return {}; }
  }
  if (b.filtros && typeof b.filtros === 'object') return b.filtros;
  // segmentar también acepta el JSON de filtros directamente como cuerpo
  return Object.keys(b).length ? b : {};
}

const ACCIONES = {
  async check() {
    const r = await queryOne(`SELECT COUNT(*) AS n FROM ${G}.dim_municipio`);
    return { ok: true, backend: 'vercel+supabase', modo: 'solo-lectura', c98_geo: `ready:${r.n} municipios` };
  },

  async stats() {
    const row = await queryOne(`SELECT COALESCE(SUM(filas_ins),0) AS total_nuevos, COALESCE(SUM(filas_dup),0) AS total_dup,
        COALESCE(SUM(filas_err),0) AS total_err, COUNT(*) AS total_imps FROM ${T}.import_log`);
    const ult = await queryOne(`SELECT archivo, importado_en, filas_ins FROM ${T}.import_log ORDER BY id DESC LIMIT 1`);
    return {
      ok: true, total_nuevos: Number(row.total_nuevos), total_dup: Number(row.total_dup),
      total_err: Number(row.total_err), total_imps: Number(row.total_imps), ultima_imp: ult,
    };
  },

  async logs() {
    const rows = await query(`SELECT * FROM ${T}.v_import_resumen LIMIT 50`);
    return { ok: true, logs: rows };
  },

  async analytics({ q }) {
    const importId = q.import_id ? parseInt(q.import_id, 10) || null : null;
    const imp = importId ? [importId] : [];
    // Con import_id el filtro usa el parámetro $1; sin él, no hay condición.
    const whereL = importId ? 'WHERE l.import_id = $1' : 'WHERE 1=1';
    const whereAndL = importId ? 'AND l.import_id = $1' : '';
    const whereAnd = importId ? 'AND import_id = $1' : '';
    const where = importId ? 'WHERE import_id = $1' : 'WHERE 1=1';

    const kpi = await queryOne(`
      SELECT COUNT(*) AS total, COUNT(l.nombre) AS c_nombre, COUNT(l.email) AS c_email, COUNT(l.telefono) AS c_telefono,
        COUNT(l.sexo) AS c_sexo, COUNT(l.rango_edad) AS c_rango_edad, COUNT(l.estrato) AS c_estrato,
        COUNT(l.afiliacion_politica) AS c_afiliacion_politica, COUNT(l.nivel_educativo) AS c_nivel_educativo,
        COUNT(l.id_geo) AS c_id_geo, COUNT(dpto.id) AS c_geo_departamento, COUNT(m.id) AS c_municipio,
        COUNT(z.id) AS c_zona, COUNT(c.id) AS c_comuna_localidad, COUNT(b.id) AS c_barrio, COUNT(sr.id) AS c_subregion,
        COUNT(l.platform) AS c_platform, COUNT(NULLIF(l.is_organic,0)) AS c_organic,
        MIN(l.created_time) AS primera_fecha, MAX(l.created_time) AS ultima_fecha
      FROM ${T}.import_log_leads l
      LEFT JOIN ${G}.dim_municipio    m    ON m.id   = LEFT(l.id_geo, 5)  AND l.id_geo IS NOT NULL
      LEFT JOIN ${G}.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2) AND l.id_geo IS NOT NULL
      LEFT JOIN ${G}.dim_subregion    sr   ON sr.id  = m.id_subregion
      LEFT JOIN ${G}.dim_zona         z    ON z.id   = LEFT(l.id_geo, 8)  AND CHAR_LENGTH(l.id_geo) >= 8
      LEFT JOIN ${G}.dim_comuna       c    ON c.id   = LEFT(l.id_geo, 11) AND CHAR_LENGTH(l.id_geo) >= 11
      LEFT JOIN ${G}.dim_barrio       b    ON b.id   = l.id_geo           AND CHAR_LENGTH(l.id_geo) = 15
      ${whereL}`, imp);

    const total = Math.max(1, Number(kpi.total));
    const cols = ['nombre', 'email', 'telefono', 'sexo', 'rango_edad', 'estrato', 'afiliacion_politica', 'nivel_educativo',
      'id_geo', 'geo_departamento', 'subregion', 'municipio', 'zona', 'comuna_localidad', 'barrio', 'platform'];
    const cobertura = {};
    for (const col of cols) cobertura[col] = Math.round((Number(kpi[`c_${col}`] || 0) / total) * 1000) / 10;

    const T_ = `${T}.import_log_leads`;
    const cq = (col, extra, order, limit) => catQuery({ col, from: T_, where: `WHERE ${extra} ${whereAnd}`, order, limit });
    const distSql = {
      sexo: cq('sexo', 'sexo IS NOT NULL'),
      rango_edad: cq('rango_edad', 'rango_edad IS NOT NULL', ORDEN_EDAD),
      estrato: cq('estrato', 'estrato IS NOT NULL', 'val ASC'),
      afiliacion_politica: cq('afiliacion_politica', 'afiliacion_politica IS NOT NULL', 'cnt DESC', 'LIMIT 15'),
      nivel_educativo: cq('nivel_educativo', 'nivel_educativo IS NOT NULL', 'cnt DESC', 'LIMIT 12'),
      platform: catQuery({ col: "COALESCE(platform,'desconocido')", from: T_, where, order: 'cnt DESC' }),
    };
    const geoSql = {
      departamento: `SELECT dpto.nombre AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_departamento dpto ON dpto.id = LEFT(l.id_geo, 2)
        WHERE l.id_geo IS NOT NULL ${whereAndL} GROUP BY dpto.id ORDER BY cnt DESC LIMIT 30`,
      subregion: `SELECT CONCAT(sr.nombre, '|||', COALESCE(dpto.nombre,'?')) AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_municipio m ON m.id = LEFT(l.id_geo, 5)
        JOIN ${G}.dim_subregion sr ON sr.id = m.id_subregion
        JOIN ${G}.dim_departamento dpto ON dpto.id = m.id_departamento
        WHERE l.id_geo IS NOT NULL ${whereAndL} GROUP BY sr.id, dpto.id ORDER BY cnt DESC LIMIT 30`,
      municipio: `SELECT CONCAT(m.nombre, '|||', COALESCE(dpto.nombre,'?')) AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_municipio m ON m.id = LEFT(l.id_geo, 5)
        JOIN ${G}.dim_departamento dpto ON dpto.id = m.id_departamento
        WHERE l.id_geo IS NOT NULL ${whereAndL} GROUP BY m.id, dpto.id ORDER BY cnt DESC LIMIT 30`,
      zona: `SELECT CONCAT(z.nombre, '|||', COALESCE(m.nombre,'?')) AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_zona z ON z.id = LEFT(l.id_geo, 8)
        JOIN ${G}.dim_municipio m ON m.id = z.id_municipio
        WHERE CHAR_LENGTH(l.id_geo) >= 8 ${whereAndL} GROUP BY z.id, m.id ORDER BY cnt DESC LIMIT 30`,
      comuna: `SELECT CONCAT(c.nombre, '|||', COALESCE(m.nombre,'?')) AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_comuna c ON c.id = LEFT(l.id_geo, 11)
        JOIN ${G}.dim_zona z ON z.id = c.id_zona
        JOIN ${G}.dim_municipio m ON m.id = z.id_municipio
        WHERE CHAR_LENGTH(l.id_geo) >= 11 ${whereAndL} GROUP BY c.id, m.id ORDER BY cnt DESC LIMIT 30`,
      barrio: `SELECT CONCAT(b.nombre, '|||', CONCAT_WS(' - ', m.nombre, z.nombre, c.nombre)) AS val, COUNT(*) AS cnt FROM ${T_} l
        JOIN ${G}.dim_barrio b ON b.id = l.id_geo
        JOIN ${G}.dim_comuna c ON c.id = b.id_comuna
        JOIN ${G}.dim_zona z ON z.id = c.id_zona
        JOIN ${G}.dim_municipio m ON m.id = z.id_municipio
        WHERE CHAR_LENGTH(l.id_geo) = 15 ${whereAndL} GROUP BY b.id, m.id, z.id, c.id ORDER BY cnt DESC LIMIT 40`,
    };

    const joinLeadTemas = importId ? `JOIN ${T_} l ON l.id_lead = r.lead_id` : '';
    const whereTemas = importId ? 'WHERE l.import_id = $1' : '';
    const leadFilterSub = importId ? `AND r.lead_id IN (SELECT id_lead FROM ${T_} WHERE import_id = $1)` : '';

    const [distRows, geoRows, timeline, porImport, tema, subtema, preguntas] = await Promise.all([
      Promise.all(Object.entries(distSql).map(async ([k, s]) => [k, N(await query(s, imp))])),
      Promise.all(Object.entries(geoSql).map(async ([k, s]) => [k, N(await query(s, imp))])),
      query(`SELECT importado_en::date AS fecha, SUM(filas_ins) AS nuevos, SUM(filas_dup) AS duplicados, COUNT(*) AS importaciones
             FROM ${T}.import_log ${importId ? 'WHERE id = $1' : ''} GROUP BY importado_en::date ORDER BY fecha DESC LIMIT 30`, imp),
      query(`SELECT il.id, il.archivo, il.hoja, il.importado_en::date AS fecha, il.filas_ins AS nuevos, il.filas_dup AS dup,
                    COALESCE(il.tipo,'leads') AS tipo, il.etapa
             FROM ${T}.import_log il ${importId ? 'WHERE il.id = $1' : ''} ORDER BY il.id DESC LIMIT 20`, imp),
      query(`SELECT dt.tema AS val, COUNT(r.id) AS cnt FROM ${T}.dim_tema dt
             JOIN ${T}.pregunta_tema pt ON pt.tema_id = dt.id
             JOIN ${T}.import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
             ${joinLeadTemas} ${whereTemas} GROUP BY dt.tema ORDER BY cnt DESC`, imp),
      query(`SELECT CONCAT(dt.subtema, '|||', dt.tema) AS val, COUNT(r.id) AS cnt FROM ${T}.dim_tema dt
             JOIN ${T}.pregunta_tema pt ON pt.tema_id = dt.id
             JOIN ${T}.import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
             ${joinLeadTemas} ${whereTemas} GROUP BY dt.id ORDER BY cnt DESC`, imp),
      query(`SELECT p.id AS pregunta_id, p.pregunta, dt.tema, dt.subtema, dt.codigo, COUNT(r.id) AS n_respuestas
             FROM ${T}.dim_tema dt
             JOIN ${T}.pregunta_tema pt ON pt.tema_id = dt.id
             JOIN ${T}.import_log_leads_preguntas p ON p.id = pt.pregunta_id
             LEFT JOIN ${T}.import_log_leads_respuestas r ON r.pregunta_id = p.id ${leadFilterSub}
             GROUP BY p.id, dt.id ${importId ? 'HAVING COUNT(r.id) > 0' : ''}
             ORDER BY dt.codigo, n_respuestas DESC`, imp),
    ]);

    return {
      ok: true, total, kpi, cobertura,
      dist: Object.fromEntries(distRows),
      geo: Object.fromEntries(geoRows),
      temas: {
        tema: N(tema), subtema: N(subtema),
        preguntas: preguntas.map((p) => ({ ...p, n_respuestas: Number(p.n_respuestas), pregunta_id: Number(p.pregunta_id) })),
      },
      timeline: timeline.reverse(),
      por_import: porImport,
    };
  },

  async segment_dims() {
    const T_ = `${T}.import_log_leads`;
    const cat = (col, order, limit) =>
      catQuery({ col, from: T_, where: `WHERE ${col} IS NOT NULL AND ${col}::text <> ''`, order, limit });
    const defs = {
      sexo: cat('sexo'),
      rango_edad: cat('rango_edad', ORDEN_EDAD),
      estrato: catQuery({ col: 'estrato', from: T_, where: 'WHERE estrato IS NOT NULL', order: 'val ASC' }),
      platform: cat('platform'),
      afiliacion_politica: cat('afiliacion_politica', 'cnt DESC', 'LIMIT 40'),
      nivel_educativo: cat('nivel_educativo', 'cnt DESC', 'LIMIT 40'),
    };
    const [cats, total, imports, regiones, departamentos, preguntas, temas] = await Promise.all([
      Promise.all(Object.entries(defs).map(async ([k, s]) => [k, N(await query(s))])),
      queryOne(`SELECT COUNT(*) AS n FROM ${T_}`),
      query(`SELECT il.id, il.archivo, il.importado_en::date AS fecha, COUNT(l.id_lead) AS leads
             FROM ${T}.import_log il LEFT JOIN ${T_} l ON l.import_id = il.id GROUP BY il.id ORDER BY il.id DESC LIMIT 200`),
      query(`SELECT id, nombre FROM ${G}.dim_region ORDER BY nombre`),
      query(`SELECT id, nombre, id_region FROM ${G}.dim_departamento ORDER BY nombre`),
      query(`SELECT * FROM (
               SELECT DISTINCT ON (p.id) p.id, p.pregunta, p.es_electoral, dt.dominio, dt.tema, dt.subtema, dt.codigo,
                      (SELECT COUNT(*) FROM ${T}.import_log_leads_respuestas r WHERE r.pregunta_id = p.id) AS n
               FROM ${T}.import_log_leads_preguntas p
               LEFT JOIN ${T}.pregunta_tema pt ON pt.pregunta_id = p.id
               LEFT JOIN ${T}.dim_tema dt ON dt.id = pt.tema_id
               ORDER BY p.id, dt.codigo) x
             WHERE n > 0 ORDER BY (codigo IS NULL), codigo, n DESC`),
      query(`SELECT dt.id, dt.dominio, dt.tema, dt.subtema, dt.codigo, COUNT(DISTINCT r.lead_id) AS n
             FROM ${T}.dim_tema dt
             LEFT JOIN ${T}.pregunta_tema pt ON pt.tema_id = dt.id
             LEFT JOIN ${T}.import_log_leads_respuestas r ON r.pregunta_id = pt.pregunta_id
             GROUP BY dt.id ORDER BY dt.codigo`),
    ]);
    return {
      ok: true, ...Object.fromEntries(cats),
      total_leads: Number(total.n),
      imports: imports.map((r) => ({ id: Number(r.id), archivo: r.archivo, fecha: r.fecha, leads: Number(r.leads) })),
      regiones: regiones.map((r) => ({ id: Number(r.id), nombre: r.nombre })),
      departamentos: departamentos.map((r) => ({ id: r.id, nombre: r.nombre, id_region: Number(r.id_region) })),
      preguntas: preguntas.map((r) => ({
        id: Number(r.id), pregunta: r.pregunta, dominio: r.dominio, tema: r.tema, subtema: r.subtema,
        codigo: r.codigo, es_electoral: Number(r.es_electoral), n: Number(r.n),
      })),
      temas: temas.map((r) => ({
        id: Number(r.id), dominio: r.dominio, tema: r.tema, subtema: r.subtema, codigo: r.codigo, n: Number(r.n),
      })),
    };
  },

  async segmentar({ body }) {
    const filtros = filtrosDe(body);
    let nivelMapa = (body && body.nivel_mapa) || filtros.nivel_mapa || 'departamento';
    if (!SEGMENT_GEO_NIVELES.includes(nivelMapa)) nivelMapa = 'departamento';
    const { where, params } = buildSegmentWhere(filtros);
    const L = `${T}.import_log_leads l`;

    const distDefs = {
      sexo: ['sexo'],
      rango_edad: ['rango_edad', ORDEN_EDAD],
      estrato: ['estrato', 'val ASC'],
      afiliacion_politica: ['afiliacion_politica', 'cnt DESC', 'LIMIT 15'],
      nivel_educativo: ['nivel_educativo', 'cnt DESC', 'LIMIT 12'],
      platform: ['platform'],
    };
    const geoAgg = {
      region: `SELECT dpto.id_region AS geo_id, MAX(reg.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
               JOIN ${G}.dim_region reg ON reg.id = dpto.id_region
               ${where} AND l.id_geo IS NOT NULL AND dpto.id_region IS NOT NULL GROUP BY dpto.id_region`,
      departamento: `SELECT dpto.id AS geo_id, MAX(dpto.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
               ${where} AND l.id_geo IS NOT NULL GROUP BY dpto.id`,
      subregion: `SELECT sr.id AS geo_id, MAX(sr.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_municipio m ON m.id = LEFT(l.id_geo,5)
               JOIN ${G}.dim_subregion sr ON sr.id = m.id_subregion
               ${where} AND l.id_geo IS NOT NULL AND m.id_subregion IS NOT NULL GROUP BY sr.id`,
      municipio: `SELECT m.id AS geo_id, MAX(m.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_municipio m ON m.id = LEFT(l.id_geo,5)
               ${where} AND l.id_geo IS NOT NULL GROUP BY m.id`,
      zona: `SELECT z.id AS geo_id, MAX(z.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_zona z ON z.id = LEFT(l.id_geo,8)
               ${where} AND CHAR_LENGTH(l.id_geo) >= 8 GROUP BY z.id`,
      comuna: `SELECT c.id AS geo_id, MAX(c.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_comuna c ON c.id = LEFT(l.id_geo,11)
               ${where} AND CHAR_LENGTH(l.id_geo) >= 11 GROUP BY c.id`,
      barrio: `SELECT b.id AS geo_id, MAX(b.nombre) AS nombre, COUNT(*) AS cnt FROM ${L}
               JOIN ${G}.dim_barrio b ON b.id = l.id_geo
               ${where} AND CHAR_LENGTH(l.id_geo) = 15 GROUP BY b.id`,
    };

    const [tot, glob, dist, timeline, temasRows, geoRows, sinGeo] = await Promise.all([
      queryOne(`SELECT COUNT(*) AS n FROM ${L} ${where}`, params),
      queryOne(`SELECT COUNT(*) AS n FROM ${L}`),
      Promise.all(Object.entries(distDefs).map(async ([k, [col, order, limit]]) => [k, N(await query(catQuery({
        col: `l."${col}"`, from: L,
        where: `${where} AND l."${col}" IS NOT NULL AND l."${col}"::text <> ''`, order, limit }), params))])),
      query(`SELECT to_char(l.created_time, 'YYYY-MM') AS fecha, COUNT(*) AS nuevos FROM ${L} ${where}
             AND l.created_time IS NOT NULL GROUP BY 1 ORDER BY 1 ASC LIMIT 60`, params),
      query(`SELECT pt.tema_id, COUNT(DISTINCT l.id_lead) AS cnt FROM ${L}
             JOIN ${T}.import_log_leads_respuestas r ON r.lead_id = l.id_lead
             JOIN ${T}.pregunta_tema pt ON pt.pregunta_id = r.pregunta_id
             ${where} GROUP BY pt.tema_id`, params),
      query(geoAgg[nivelMapa], params),
      queryOne(`SELECT COUNT(*) AS n FROM ${L} ${where} AND l.id_geo IS NULL`, params),
    ]);

    const total = Number(tot.n), totalGlobal = Number(glob.n);
    const geoMap = geoRows.map((r) => ({ geo_id: String(r.geo_id), nombre: r.nombre, cnt: Number(r.cnt) }));
    const temasCnt = {};
    for (const r of temasRows) temasCnt[String(r.tema_id)] = Number(r.cnt);
    return {
      ok: true, total, total_global: totalGlobal,
      pct_del_total: totalGlobal > 0 ? Math.round((total / totalGlobal) * 10000) / 100 : 0,
      dist: Object.fromEntries(dist),
      timeline: timeline.map((r) => ({ fecha: r.fecha, nuevos: Number(r.nuevos) })),
      nivel_mapa: nivelMapa, geo_map: geoMap, sin_geo: Number(sinGeo.n),
      mapeados: geoMap.reduce((s, r) => s + r.cnt, 0), temas_cnt: temasCnt,
    };
  },

  async segmento_preguntas({ body }) {
    const { where, params } = buildSegmentWhere(filtrosDe(body));
    const rows = await query(`SELECT r.pregunta_id, COUNT(DISTINCT r.lead_id) AS n FROM ${T}.import_log_leads l
        JOIN ${T}.import_log_leads_respuestas r ON r.lead_id = l.id_lead ${where} GROUP BY r.pregunta_id`, params);
    const conteos = {};
    for (const r of rows) conteos[String(r.pregunta_id)] = Number(r.n);
    return { ok: true, conteos };
  },

  async segmento_respuestas({ body }) {
    const preguntaId = parseInt(body && body.pregunta_id, 10) || 0;
    if (!preguntaId) return { __status: 400, ok: false, error: 'Falta pregunta_id.' };
    const { where, params } = buildSegmentWhere(filtrosDe(body));
    const rows = await query(`SELECT mode() WITHIN GROUP (ORDER BY r.respuesta) AS respuesta, COUNT(*) AS n FROM ${T}.import_log_leads l
        JOIN ${T}.import_log_leads_respuestas r ON r.lead_id = l.id_lead
        ${where} AND r.pregunta_id = $${params.length + 1} GROUP BY ${ci('r.respuesta')} ORDER BY n DESC LIMIT 50`, [...params, preguntaId]);
    return { ok: true, pregunta_id: preguntaId, respuestas: rows.map((r) => ({ respuesta: r.respuesta, n: Number(r.n) })) };
  },

  async respuestas_muestra({ q }) {
    const preguntaId = parseInt(q.pregunta_id, 10) || 0;
    const importId = q.import_id ? parseInt(q.import_id, 10) || null : null;
    if (!preguntaId) return { __status: 400, ok: false, error: 'Falta pregunta_id.' };
    const params = importId ? [preguntaId, importId] : [preguntaId];
    const f = importId ? `AND r.lead_id IN (SELECT id_lead FROM ${T}.import_log_leads WHERE import_id = $2)` : '';
    const [filas, tot] = await Promise.all([
      query(`SELECT mode() WITHIN GROUP (ORDER BY r.respuesta) AS respuesta, COUNT(*) AS n FROM ${T}.import_log_leads_respuestas r
             WHERE r.pregunta_id = $1 ${f} GROUP BY ${ci('r.respuesta')} ORDER BY n DESC LIMIT 20`, params),
      queryOne(`SELECT COUNT(*) AS n FROM ${T}.import_log_leads_respuestas r WHERE r.pregunta_id = $1 ${f}`, params),
    ]);
    const total = Number(tot.n);
    return {
      ok: true, pregunta_id: preguntaId, total,
      respuestas: filas.map((r) => ({ respuesta: r.respuesta, n: Number(r.n), pct: total ? Math.round((100 * Number(r.n) / total) * 10) / 10 : 0 })),
    };
  },

  async buscar_respuestas({ q }) {
    const txt = String(q.q || '').trim();
    if ([...txt].length < 3) return { ok: true, hits: [] };
    const like = '%' + [...txt].slice(0, 80).join('').replace(/[\\%_]/g, (c) => '\\' + c) + '%';
    // sin distinguir mayúsculas ni tildes, como el LIKE de MariaDB con utf8mb4_unicode_ci
    const rows = await query(`SELECT r.pregunta_id, mode() WITHIN GROUP (ORDER BY r.respuesta) AS respuesta, COUNT(*) AS n
        FROM ${T}.import_log_leads_respuestas r
        WHERE ${ci('r.respuesta')} LIKE ${ci('$1')} GROUP BY r.pregunta_id, ${ci('r.respuesta')} ORDER BY n DESC LIMIT 60`, [like]);
    const hits = {};
    for (const r of rows) (hits[String(r.pregunta_id)] ||= []).push({ respuesta: r.respuesta, n: Number(r.n) });
    return { ok: true, hits };
  },
};

const csvCell = (v) => {
  if (v === null || v === undefined) return '';
  const s = String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
};

async function exportarCsv(req, res, body) {
  const { where, params } = buildSegmentWhere(filtrosDe(body));
  const d = new Date();
  const p2 = (n) => String(n).padStart(2, '0');
  const stamp = `${d.getFullYear()}${p2(d.getMonth() + 1)}${p2(d.getDate())}_${p2(d.getHours())}${p2(d.getMinutes())}`;
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="segmento_${stamp}.csv"`);
  res.write('﻿' + ['lead_id', 'nombre', 'email', 'telefono', 'sexo', 'rango_edad', 'estrato', 'nivel_educativo',
    'afiliacion_politica', 'plataforma', 'id_geo', 'departamento', 'municipio', 'created_time', 'campaign_name'].join(',') + '\r\n');
  // Paginación por clave (id_lead) para no cargar los ~140 mil leads de golpe en memoria.
  const pool = getPool();
  let last = 0;
  for (;;) {
    const rows = (await pool.query(`
      SELECT l.id_lead, l.id, l.nombre, l.email, l.telefono, l.sexo, l.rango_edad, l.estrato, l.nivel_educativo,
             l.afiliacion_politica, l.platform, l.id_geo, dpto.nombre AS departamento, m.nombre AS municipio,
             l.created_time, l.campaign_name
      FROM ${T}.import_log_leads l
      LEFT JOIN ${G}.dim_departamento dpto ON dpto.id = LEFT(l.id_geo,2)
      LEFT JOIN ${G}.dim_municipio m ON m.id = LEFT(l.id_geo,5)
      ${where} AND l.id_lead > $${params.length + 1}
      ORDER BY l.id_lead LIMIT 5000`, [...params, last])).rows;
    if (!rows.length) break;
    res.write(rows.map((r) => [r.id, r.nombre, r.email, r.telefono, r.sexo, r.rango_edad, r.estrato, r.nivel_educativo,
      r.afiliacion_politica, r.platform, r.id_geo, r.departamento, r.municipio, r.created_time, r.campaign_name]
      .map(csvCell).join(',') + '\r\n').join(''));
    last = rows[rows.length - 1].id_lead;
  }
  res.end();
}

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  if (!(await requireUser(req, res))) return;
  const q = req.query || {};
  const body = req.body && typeof req.body === 'object' ? req.body : {};
  const action = q.action || body.action || '';
  try {
    if (action === 'segmento_export') return await exportarCsv(req, res, body);
    const fn = ACCIONES[action];
    if (!fn) {
      const solo = ['import', 'preview', 'get_sheets', 'reset_db', 'delete_import', 'set_etapa', 'set_url_carpeta',
        'set_url_archivo', 'enrich_existing', 'add_candidato', 'add_partido', 'registrar_preguntas', 'crear_tema', 'temas_guardar'];
      const msg = solo.includes(action)
        ? `La acción «${action}» modifica datos y solo está disponible en la versión local (la web es de solo lectura).`
        : `Acción no reconocida o aún no portada a la web: ${action}`;
      return res.status(solo.includes(action) ? 403 : 400).json({ ok: false, error: msg });
    }
    const out = await fn({ q, body, req });
    const status = out && out.__status;
    if (status) { delete out.__status; return res.status(status).json(out); }
    return res.json(out);
  } catch (e) {
    const analitica = ['analytics', 'segmentar'].includes(action);
    return res.status(analitica ? 400 : 500).json({ ok: false, error: (analitica ? `Error en ${action}: ` : '') + e.message });
  }
}
