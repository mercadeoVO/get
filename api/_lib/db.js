// Conexión a Supabase (Postgres) para las funciones de Vercel.
// DATABASE_URL = cadena del Transaction pooler (puerto 6543) en Vercel;
// en local sirve la del Session pooler (5432). Siempre se califica el schema en el SQL
// (c98_get.* / c98_geo.*) porque el pooler en modo transacción no conserva search_path.
import pg from 'pg';

const { Pool, types } = pg;

// Mismos formatos que devolvía PDO/MariaDB: enteros como número, fechas como texto.
types.setTypeParser(20, (v) => parseInt(v, 10));     // bigint (COUNT, SUM)
types.setTypeParser(1700, (v) => parseFloat(v));     // numeric
types.setTypeParser(1082, (v) => v);                 // date  -> 'YYYY-MM-DD'
types.setTypeParser(1114, (v) => v);                 // timestamp -> 'YYYY-MM-DD HH:MM:SS'

let pool;
export function getPool() {
  if (!pool) {
    const url = process.env.DATABASE_URL;
    if (!url) throw new Error('Falta la variable de entorno DATABASE_URL');
    pool = new Pool({
      connectionString: url,
      max: 4,
      idleTimeoutMillis: 10_000,
      connectionTimeoutMillis: 10_000,
      ssl: { rejectUnauthorized: false },
    });
  }
  return pool;
}

export async function query(sql, params = []) {
  const r = await getPool().query(sql, params);
  return r.rows;
}

export async function queryOne(sql, params = []) {
  const rows = await query(sql, params);
  return rows[0] ?? null;
}

/** Acumulador de parámetros: add(v) devuelve el marcador $n correspondiente. */
export function paramBag() {
  const params = [];
  return {
    params,
    add(v) {
      params.push(v);
      return `$${params.length}`;
    },
  };
}
