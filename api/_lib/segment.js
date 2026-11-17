// Port de buildSegmentWhere() (app/leads_importer.php) a Postgres.
// Todo va parametrizado; columnas y niveles se validan contra whitelist.
import { paramBag } from './db.js';

export const SEGMENT_COLS = ['sexo', 'rango_edad', 'estrato', 'nivel_educativo', 'platform', 'afiliacion_politica'];
export const SEGMENT_GEO_NIVELES = ['region', 'departamento', 'subregion', 'municipio', 'zona', 'comuna', 'barrio'];

const T = 'c98_get';
const G = 'c98_geo';

/**
 * MariaDB comparaba y agrupaba con utf8mb4_unicode_ci (sin distinguir mayúsculas ni tildes).
 * ci(x) reproduce esa comparación en Postgres.
 */
export const ci = (x) => `extensions.unaccent(lower((${x})::text))`;
/** x coincide (sin mayúsculas/tildes) con alguno de los textos del parámetro p (text[]). */
export const ciAny = (x, p) => `${ci(x)} = ANY(ARRAY(SELECT ${ci('v')} FROM unnest(${p}::text[]) v))`;

/** Orden fijo de rangos de edad sobre la columna `val` (equivale a FIELD() de MariaDB; desconocidos primero). */
export const ORDEN_EDAD =
  "COALESCE(array_position(ARRAY['18a24','25a34','35a44','45a65+','18-24','25-34','35-44','45-65+']::text[], val::text), 0) ASC";

/**
 * Conteo por valor agrupando sin distinguir mayúsculas/tildes; el texto mostrado es la variante más frecuente.
 * Devuelve filas { val, cnt }.
 */
export function catQuery({ col, from, where = '', order = 'cnt DESC', limit = '' }) {
  return `SELECT * FROM (SELECT mode() WITHIN GROUP (ORDER BY ${col}) AS val, COUNT(*) AS cnt
          FROM ${from} ${where} GROUP BY ${ci(col)}) s ORDER BY ${order} ${limit}`;
}

const strs = (a) => (Array.isArray(a) ? a : []).map((v) => String(v)).filter((v) => v !== '');

/** Devuelve { where, params } sobre `c98_get.import_log_leads l`. */
export function buildSegmentWhere(f = {}) {
  const bag = paramBag();
  let w = ' WHERE 1=1';

  for (const col of SEGMENT_COLS) {
    if (!Array.isArray(f[col])) continue;
    let vals = f[col].filter((v) => v !== null && v !== '').map(String);
    if (!vals.length) continue;
    const sinDato = vals.includes('__NULL__');
    vals = vals.filter((v) => v !== '__NULL__');
    const cond = [];
    if (vals.length) cond.push(ciAny(`l."${col}"`, bag.add(vals)));
    if (sinDato) cond.push(`(l."${col}" IS NULL OR l."${col}"::text = '')`);
    w += ' AND (' + cond.join(' OR ') + ')';
  }

  if (f.is_organic === 0 || f.is_organic === 1 || f.is_organic === '0' || f.is_organic === '1') {
    w += ` AND l.is_organic = ${bag.add(parseInt(f.is_organic, 10))}`;
  }

  if (f.created_desde) w += ` AND l.created_time >= ${bag.add(f.created_desde + ' 00:00:00')}::timestamp`;
  if (f.created_hasta) {
    w += ` AND l.created_time < (${bag.add(f.created_hasta + ' 00:00:00')}::timestamp + INTERVAL '1 day')`;
  }

  for (const [k, op] of [['periodo_desde', '>='], ['periodo_hasta', '<=']]) {
    if (f[k] && /^\d{4}-\d{2}-\d{2}$/.test(String(f[k]))) {
      w += ` AND l.fecha_ref ${op} ${bag.add(f[k])}::date`;
    }
  }

  if (Array.isArray(f.import_ids)) {
    const ids = [...new Set(f.import_ids.map((v) => parseInt(v, 10)).filter((v) => v > 0))];
    if (ids.length) w += ` AND l.import_id = ANY(${bag.add(ids)}::int[])`;
  }

  const nivel = f.geo && f.geo.nivel;
  if (nivel && Array.isArray(f.geo.ids) && SEGMENT_GEO_NIVELES.includes(nivel)) {
    const ids = strs(f.geo.ids);
    if (ids.length) {
      switch (nivel) {
        case 'region': {
          const p = bag.add(ids.map((v) => parseInt(v, 10)).filter(Number.isFinite));
          w += ` AND LEFT(l.id_geo,2) IN (SELECT id FROM ${G}.dim_departamento WHERE id_region = ANY(${p}::int[]))`;
          break;
        }
        case 'departamento':
          w += ` AND LEFT(l.id_geo,2) = ANY(${bag.add(ids)}::text[])`;
          break;
        case 'subregion':
          w += ` AND LEFT(l.id_geo,5) IN (SELECT id FROM ${G}.dim_municipio WHERE id_subregion = ANY(${bag.add(ids)}::text[]))`;
          break;
        case 'municipio':
          w += ` AND LEFT(l.id_geo,5) = ANY(${bag.add(ids)}::text[])`;
          break;
        case 'zona':
          w += ` AND CHAR_LENGTH(l.id_geo) >= 8 AND LEFT(l.id_geo,8) = ANY(${bag.add(ids)}::text[])`;
          break;
        case 'comuna':
          w += ` AND CHAR_LENGTH(l.id_geo) >= 11 AND LEFT(l.id_geo,11) = ANY(${bag.add(ids)}::text[])`;
          break;
        case 'barrio':
          w += ` AND l.id_geo = ANY(${bag.add(ids)}::text[])`;
          break;
      }
    }
  }

  // Condiciones EAV (preguntas de encuesta)
  if (Array.isArray(f.preguntas)) {
    const conds = [];
    for (const c of f.preguntas) {
      const pid = parseInt(c && c.pregunta_id, 10) || 0;
      if (pid <= 0) continue;
      const op = c.op || 'in';
      const valores = strs(c.valores);
      let sub = `SELECT 1 FROM ${T}.import_log_leads_respuestas r WHERE r.lead_id = l.id_lead AND r.pregunta_id = ${bag.add(pid)}`;
      if (op !== 'answered' && valores.length) sub += ` AND ${ciAny('r.respuesta', bag.add(valores))}`;
      conds.push((op === 'not_in' ? 'NOT EXISTS (' : 'EXISTS (') + sub + ')');
    }
    if (conds.length) {
      const union = (f.preguntas_modo || 'all') === 'any' && conds.length > 1;
      w += union ? ' AND (' + conds.join(' OR ') + ')' : ' AND ' + conds.join(' AND ');
    }
  }

  if (Array.isArray(f.temas)) {
    const ids = f.temas.map((v) => parseInt(v, 10)).filter((v) => v > 0);
    if (ids.length) {
      w += ` AND EXISTS (SELECT 1 FROM ${T}.import_log_leads_respuestas r
                         JOIN ${T}.pregunta_tema pt ON pt.pregunta_id = r.pregunta_id
                         WHERE r.lead_id = l.id_lead AND pt.tema_id = ANY(${bag.add(ids)}::int[]))`;
    }
  }

  return { where: w, params: bag.params };
}
