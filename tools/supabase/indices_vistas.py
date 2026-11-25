#!/usr/bin/env python3
"""Crea índices y vistas en Supabase a partir de la estructura de MariaDB local (solo lectura).
Se omiten: FULLTEXT (solo dim_geo, deprecada) y longitudes de prefijo (Postgres indexa la columna completa)."""
import time
from conexion import conectar_mysql, conectar_pg

pg = conectar_pg()
pc = pg.cursor()
my = conectar_mysql().cursor()

my.execute(r"""select table_schema,table_name,index_name,non_unique,index_type,
  group_concat(column_name order by seq_in_index)
  from information_schema.statistics
  where table_schema in ('c98_get','c98_geo') and index_name<>'PRIMARY'
    and table_name not like '%bkp%' and table_name not like 'bak\_%'
  group by 1,2,3,4,5 order by 1,2,3""")
for s, t, idx, nonuniq, itype, cols in my.fetchall():
    if itype == "FULLTEXT":
        print(f"omitido FULLTEXT {s}.{t}.{idx}"); continue
    name = f"{t}_{idx}"[:60]
    colsql = ",".join('"%s"' % c for c in cols.split(","))
    t0 = time.time()
    pc.execute(f'create {"" if nonuniq else "unique "}index if not exists "{name}" on {s}."{t}" ({colsql})')
    print(f"índice {s}.{name} ({time.time()-t0:.1f}s)")

pc.execute('create index if not exists geo_geometry_geom_gist on c98_geo.geo_geometry using gist (geom)')
print("índice GiST geo_geometry.geom")

pc.execute("""create or replace view c98_get.v_participacion_historica as
select p.id_participacion, p.id_geo, g.cod_mpio, g.nom_mpio as municipio, g.nom_dpto as departamento,
       g.nivel as nivel_geo, p.anio, p.tipo_eleccion, p.vuelta, p.potencial, p.total_votantes, p.pct_participacion
from c98_get.dim_participacion p left join c98_get.dim_geo g on g.id = p.id_geo""")
pc.execute("""create or replace view c98_get.v_import_resumen as
select il.id, il.archivo, il.hoja, il.importado_en, il.filas_proc, il.filas_ins, il.filas_dup, il.filas_err,
       il.notas, il.etapa, il.url_carpeta, il.url_archivo, coalesce(il.tipo,'leads') as tipo,
       count(distinct l.id) as leads_en_bd, count(distinct ie.id) as registros_electorales
from c98_get.import_log il
left join c98_get.import_log_leads l on l.import_id = il.id
left join c98_get.import_electoral ie on ie.import_id = il.id
group by il.id order by il.importado_en desc""")
# Estimación del municipio por subregión (misma definición que ensureEstimacionGeo() en app/leads_importer.php):
# los leads con municipio cuentan 1; los que solo traen subregión se reparten con los pesos de geo_peso_estimacion.
pc.execute("""create or replace view c98_get.v_leads_municipio_estimado as
select l.id_lead, l.import_id, left(l.id_geo, 5) as id_municipio, 1.000000000::numeric(10,9) as peso, 'real'::text as origen
from c98_get.import_log_leads l
where char_length(l.id_geo) >= 5
union all
select l.id_lead, l.import_id, w.id_municipio, w.peso, 'estimado'::text as origen
from c98_get.import_log_leads l
join c98_get.geo_peso_estimacion w on w.grupo = l.grupo_estimacion
where (l.id_geo is null or char_length(l.id_geo) < 5) and l.grupo_estimacion is not null""")
print("vistas creadas")
