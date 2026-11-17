"""Conexiones compartidas para los scripts de copia a Supabase.

- Origen: MariaDB local (127.0.0.1:3307, usuario root). Los scripts solo LEEN de aquí.
- Destino: Postgres de Supabase, URL en SUPABASE_DB_URL (variable de entorno o archivo .env.supabase).

El archivo .env.supabase NO se versiona (está en .gitignore). Ver .env.supabase.example.
"""
import os
import sys

import psycopg2
import pymysql

_AQUI = os.path.dirname(os.path.abspath(__file__))
_RAIZ = os.path.abspath(os.path.join(_AQUI, "..", ".."))


def _buscar_env():
    """.env.supabase en la raíz del repo; si se ejecuta desde un worktree, también 3 niveles más arriba."""
    for base in (_RAIZ, os.path.abspath(os.path.join(_RAIZ, "..", "..", ".."))):
        p = os.path.join(base, ".env.supabase")
        if os.path.exists(p):
            return p
    return None


def supabase_url():
    url = os.environ.get("SUPABASE_DB_URL")
    if url:
        return url.strip()
    p = _buscar_env()
    if not p:
        sys.exit("Falta SUPABASE_DB_URL: defínela o crea .env.supabase (ver .env.supabase.example)")
    for linea in open(p, encoding="utf-8-sig"):
        if linea.startswith("SUPABASE_DB_URL="):
            return linea.split("=", 1)[1].strip()
    sys.exit(f"{p} no contiene SUPABASE_DB_URL=")


def conectar_pg(autocommit=True):
    """Conexión a Supabase sin límite de tiempo por sentencia (cargas grandes)."""
    cn = psycopg2.connect(supabase_url(), connect_timeout=20)
    cn.autocommit = autocommit
    cn.cursor().execute("set statement_timeout = 0")
    return cn


def conectar_mysql(**extra):
    """Conexión de solo lectura (por convención) a la MariaDB local."""
    return pymysql.connect(host="127.0.0.1", port=3307, user="root", password="",
                           charset="utf8mb4", autocommit=True, **extra)
