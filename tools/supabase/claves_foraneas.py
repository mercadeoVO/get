#!/usr/bin/env python3
"""Crea en Supabase las claves foráneas que existen en MariaDB local (solo lectura en el local)."""
import time
from conexion import conectar_mysql, conectar_pg

pg = conectar_pg()
pc = pg.cursor()
my = conectar_mysql().cursor()

my.execute(r"""select k.table_schema,k.table_name,k.constraint_name,group_concat(k.column_name order by k.ordinal_position),
  k.referenced_table_schema,k.referenced_table_name,group_concat(k.referenced_column_name order by k.ordinal_position),
  r.delete_rule,r.update_rule
  from information_schema.key_column_usage k
  join information_schema.referential_constraints r on r.constraint_schema=k.table_schema
   and r.constraint_name=k.constraint_name and r.table_name=k.table_name
  where k.table_schema in ('c98_get','c98_geo') and k.table_name not like '%bkp%' and k.table_name not like 'bak\_%'
  group by 1,2,3,5,6,8,9 order by 1,2,3""")
ok = bad = 0
for s, t, name, cols, rs, rt, rcols, dele, upd in my.fetchall():
    c = ",".join('"%s"' % x for x in cols.split(","))
    rc = ",".join('"%s"' % x for x in rcols.split(","))
    pc.execute(f'alter table {s}."{t}" drop constraint if exists "{name}"')
    t0 = time.time()
    try:
        pc.execute(f'alter table {s}."{t}" add constraint "{name}" foreign key ({c}) '
                   f'references {rs}."{rt}" ({rc}) on delete {dele} on update {upd}')
        ok += 1; print(f"OK   {s}.{t}.{name} ({time.time()-t0:.1f}s)")
    except Exception as e:
        bad += 1; print(f"FALLA {s}.{t}.{name}: {str(e).splitlines()[0]}")
print(f"{ok} creadas, {bad} con error")
