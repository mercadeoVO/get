#!/usr/bin/env python3
"""
MEDFUT Excel Reader
Uso:
  python excel_reader.py get_sheets <filepath>
  python excel_reader.py read       <filepath> <sheet> [max_rows]
Salida: JSON a stdout
Soporta: .xlsx / .xlsm (openpyxl)  |  .xls (xlrd)
"""
import sys
import io
import json
import os
from datetime import datetime, date
from decimal import Decimal, InvalidOperation

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')


def is_xls(filepath):
    return os.path.splitext(filepath)[1].lower() == '.xls'


# ──────────────────────────────────────────────────────────────────────────────
# OPENPYXL backend  (.xlsx / .xlsm)
# ──────────────────────────────────────────────────────────────────────────────
def normalize_cell(cell):
    v = cell.value
    if v is None:
        return None
    if isinstance(v, (datetime, date)):
        return v.strftime('%Y-%m-%d %H:%M:%S') if isinstance(v, datetime) else v.strftime('%Y-%m-%d')
    if isinstance(v, bool):
        return v
    if isinstance(v, int):
        return str(v) if abs(v) > 2**31 else v
    if isinstance(v, float):
        if v == int(v) and abs(v) > 1e6:
            raw = getattr(cell, '_value', None)
            if raw is not None and isinstance(raw, str):
                try:
                    d = Decimal(raw.strip())
                    if d == d.to_integral_value():
                        return str(int(d))
                except InvalidOperation:
                    pass
            return str(int(v))
        return v
    if isinstance(v, str):
        return v
    return str(v)


def get_sheets_xlsx(filepath):
    import openpyxl
    wb = openpyxl.load_workbook(filepath, data_only=True, read_only=True)
    sheets = []
    for name in wb.sheetnames:
        ws = wb[name]
        sheets.append({'name': name, 'rows': (ws.max_row or 1) - 1, 'cols': ws.max_column or 0})
    wb.close()
    return sheets


def read_sheet_xlsx(filepath, sheet_name, max_rows=0):
    import openpyxl
    wb = openpyxl.load_workbook(filepath, data_only=True)
    ws = wb[sheet_name]
    rows = []
    for i, row in enumerate(ws.iter_rows(values_only=False)):
        if max_rows and i >= max_rows:
            break
        cells = [normalize_cell(c) for c in row]
        while cells and cells[-1] is None:
            cells.pop()
        if any(c is not None and str(c).strip() != '' for c in cells):
            rows.append(cells)
    wb.close()
    return rows


# ──────────────────────────────────────────────────────────────────────────────
# XLRD backend  (.xls)
# ──────────────────────────────────────────────────────────────────────────────
def normalize_cell_xls(cell, wb):
    import xlrd
    ctype = cell.ctype
    v     = cell.value
    if ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
        return None
    if ctype == xlrd.XL_CELL_TEXT:
        return v if v.strip() != '' else None
    if ctype == xlrd.XL_CELL_NUMBER:
        # IDs numéricos largos → string
        if v == int(v) and abs(v) > 1e6:
            return str(int(v))
        if v == int(v):
            return int(v)
        return v
    if ctype == xlrd.XL_CELL_DATE:
        try:
            dt = xlrd.xldate_as_datetime(v, wb.datemode)
            return dt.strftime('%Y-%m-%d %H:%M:%S') if dt.hour or dt.minute else dt.strftime('%Y-%m-%d')
        except Exception:
            return v
    if ctype == xlrd.XL_CELL_BOOLEAN:
        return bool(v)
    if ctype == xlrd.XL_CELL_ERROR:
        return None
    return str(v)


def get_sheets_xls(filepath):
    import xlrd
    wb = xlrd.open_workbook(filepath)
    sheets = []
    for name in wb.sheet_names():
        ws = wb.sheet_by_name(name)
        sheets.append({'name': name, 'rows': max(ws.nrows - 1, 0), 'cols': ws.ncols})
    return sheets


def read_sheet_xls(filepath, sheet_name, max_rows=0):
    import xlrd
    wb   = xlrd.open_workbook(filepath)
    ws   = wb.sheet_by_name(sheet_name)
    rows = []
    for i in range(ws.nrows):
        if max_rows and i >= max_rows:
            break
        cells = [normalize_cell_xls(ws.cell(i, c), wb) for c in range(ws.ncols)]
        while cells and cells[-1] is None:
            cells.pop()
        if any(c is not None and str(c).strip() != '' for c in cells):
            rows.append(cells)
    return rows


# ──────────────────────────────────────────────────────────────────────────────
# Dispatcher
# ──────────────────────────────────────────────────────────────────────────────
def get_sheets(filepath):
    return get_sheets_xls(filepath) if is_xls(filepath) else get_sheets_xlsx(filepath)


def read_sheet(filepath, sheet_name, max_rows=0):
    return read_sheet_xls(filepath, sheet_name, max_rows) if is_xls(filepath) else read_sheet_xlsx(filepath, sheet_name, max_rows)


if __name__ == '__main__':
    try:
        action   = sys.argv[1] if len(sys.argv) > 1 else ''
        filepath = sys.argv[2] if len(sys.argv) > 2 else ''
        if action == 'get_sheets':
            result = {'ok': True, 'sheets': get_sheets(filepath)}
        elif action == 'read':
            sheet    = sys.argv[3] if len(sys.argv) > 3 else None
            max_rows = int(sys.argv[4]) if len(sys.argv) > 4 else 0
            if not sheet:
                raise ValueError('Se requiere nombre de hoja.')
            result = {'ok': True, 'rows': read_sheet(filepath, sheet, max_rows)}
        else:
            result = {'ok': False, 'error': 'Accion desconocida: ' + action}
        print(json.dumps(result, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False))
