// Port de app/c98geo_api.php — jerarquía geográfica completa (Región → … → Barrio).
import { query } from './_lib/db.js';
import { requireUser } from './_lib/auth.js';

const G = 'c98_geo';
const cmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
const sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
function agrupar(rows, key) {
  const m = new Map();
  for (const r of rows) {
    const k = r[key];
    if (!m.has(k)) m.set(k, []);
    m.get(k).push(r);
  }
  return m;
}

export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-cache');
  if (!(await requireUser(req, res))) return;
  try {
    const [regiones, deptos, subregiones, municipios, zonas, comunas, barrios] = await Promise.all([
      query(`SELECT id, nombre FROM ${G}.dim_region ORDER BY nombre`),
      query(`SELECT id, nombre, id_region FROM ${G}.dim_departamento ORDER BY nombre`),
      query(`SELECT id, nombre, id_departamento FROM ${G}.dim_subregion ORDER BY nombre`),
      query(`SELECT id, nombre, abreviatura, id_departamento, id_subregion FROM ${G}.dim_municipio ORDER BY nombre`),
      query(`SELECT id, nombre, id_municipio FROM ${G}.dim_zona ORDER BY nombre`),
      query(`SELECT id, nombre, id_zona FROM ${G}.dim_comuna ORDER BY nombre`),
      query(`SELECT id, nombre, id_comuna FROM ${G}.dim_barrio ORDER BY nombre`),
    ]);

    const deptosPorRegion = agrupar(deptos, 'id_region');
    const subrPorDepto = agrupar(subregiones, 'id_departamento');
    const mpioPorSubr = agrupar(municipios, 'id_subregion');
    const zonaPorMpio = agrupar(zonas, 'id_municipio');
    const comunaPorZona = agrupar(comunas, 'id_zona');
    const barrioPorComuna = agrupar(barrios, 'id_comuna');

    const buildComuna = (c) => {
      const bars = [...(barrioPorComuna.get(c.id) || [])]
        .sort((a, b) => cmp(a.nombre, b.nombre))
        .map((b) => ({ id: b.id, n: b.nombre }));
      return { id: c.id, c: c.nombre, n_bar: bars.length, bars };
    };
    const buildZona = (z) => {
      const coms = (comunaPorZona.get(z.id) || []).map(buildComuna);
      return { id: z.id, z: z.nombre.toUpperCase(), n_com: coms.length, coms, _n_bar: sum(coms, (c) => c.n_bar) };
    };
    const buildMunicipio = (m) => {
      const hijas = zonaPorMpio.get(m.id) || [];
      const detail = hijas.map(buildZona);
      const nComunas = sum(detail, (z) => z.n_com);
      const nBarrios = sum(detail, (z) => z._n_bar);
      for (const z of detail) delete z._n_bar;
      return {
        id: m.id, m: m.nombre, abr: m.abreviatura ?? '',
        zonas: hijas.length, comunas: nComunas, barrios: nBarrios,
        detail: nComunas > 0 ? detail : null,
      };
    };

    const result = regiones.map((reg) => {
      const dSalida = (deptosPorRegion.get(reg.id) || []).map((dpto) => {
        const sSalida = (subrPorDepto.get(dpto.id) || []).map((subr) => {
          const mSalida = (mpioPorSubr.get(subr.id) || []).map(buildMunicipio);
          return {
            id: subr.id, s: subr.nombre.toUpperCase(), n: mSalida.length,
            barrios: sum(mSalida, (m) => m.barrios), comunas: sum(mSalida, (m) => m.comunas), mpios: mSalida,
          };
        });
        return {
          id: dpto.id, d: dpto.nombre, n_subrs: sSalida.length,
          n_mpios: sum(sSalida, (s) => s.n), barrios: sum(sSalida, (s) => s.barrios),
          comunas: sum(sSalida, (s) => s.comunas), subrs: sSalida,
        };
      }).sort((a, b) => cmp(a.d, b.d));
      return {
        id: reg.id, r: reg.nombre, n_dptos: dSalida.length,
        n_subrs: sum(dSalida, (d) => d.n_subrs), n_mpios: sum(dSalida, (d) => d.n_mpios),
        barrios: sum(dSalida, (d) => d.barrios), comunas: sum(dSalida, (d) => d.comunas), dptos: dSalida,
      };
    });

    const ord = ['Andina', 'Eje Cafetero', 'Caribe', 'Pacífico', 'Orinoquía', 'Amazonía'];
    const pos = (r) => { const p = ord.indexOf(r); return p >= 0 ? p : 99; };
    result.sort((a, b) => pos(a.r) - pos(b.r));

    res.json({ ok: true, ts: Math.floor(Date.now() / 1000), data: result });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
}
