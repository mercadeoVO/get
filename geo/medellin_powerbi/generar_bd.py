import json, csv

COMUNAS = {
    '01': 'Popular',
    '02': 'Santa Cruz',
    '03': 'Manrique',
    '04': 'Aranjuez',
    '05': 'Castilla',
    '06': 'Doce de Octubre',
    '07': 'Robledo',
    '08': 'Villa Hermosa',
    '09': 'Buenos Aires',
    '10': 'La Candelaria',
    '11': 'Laureles-Estadio',
    '12': 'La América',
    '13': 'San Javier',
    '14': 'El Poblado',
    '15': 'Guayabal',
    '16': 'Belén',
    '50': 'San Sebastián de Palmitas',
    '60': 'San Cristóbal',
    '70': 'Altavista',
    '80': 'San Antonio de Prado',
    '90': 'Santa Elena',
    'SN01': 'Sin Comuna Asignada 1',
    'SN02': 'Sin Comuna Asignada 2',
}

TIPO_DIVISION = {
    '01': 'Comuna', '02': 'Comuna', '03': 'Comuna', '04': 'Comuna',
    '05': 'Comuna', '06': 'Comuna', '07': 'Comuna', '08': 'Comuna',
    '09': 'Comuna', '10': 'Comuna', '11': 'Comuna', '12': 'Comuna',
    '13': 'Comuna', '14': 'Comuna', '15': 'Comuna', '16': 'Comuna',
    '50': 'Corregimiento', '60': 'Corregimiento', '70': 'Corregimiento',
    '80': 'Corregimiento', '90': 'Corregimiento',
    'SN01': 'Sin Asignar', 'SN02': 'Sin Asignar',
}

SUBTIPO = {1: 'Barrio', 2: 'Vereda'}

with open(r'C:\Users\impag\Desktop\medellin_powerbi\medellin_barrios_simple.geojson', 'r', encoding='utf-8') as f:
    geo = json.load(f)

rows = []
for feat in geo['features']:
    p = feat['properties']
    comuna_id = str(p.get('limitecomu', '')).strip()
    rows.append({
        'codigo_barrio':    p.get('codigo', ''),
        'nombre_barrio':    p.get('nombre', ''),
        'tipo_barrio':      SUBTIPO.get(p.get('subtipo_ba'), ''),
        'codigo_comuna':    comuna_id,
        'nombre_comuna':    COMUNAS.get(comuna_id, comuna_id),
        'tipo_division':    TIPO_DIVISION.get(comuna_id, ''),
        'municipio':        'Medellín',
        'departamento':     'Antioquia',
        'pais':             'Colombia',
    })

rows.sort(key=lambda x: (x['codigo_comuna'], x['nombre_barrio']))

out = r'C:\Users\impag\Desktop\medellin_powerbi\bd_medellin_barrios.csv'
with open(out, 'w', newline='', encoding='utf-8-sig') as f:
    w = csv.DictWriter(f, fieldnames=rows[0].keys())
    w.writeheader()
    w.writerows(rows)

print(f'BD guardada: {out}')
print(f'Total registros: {len(rows)}')
print()

# Resumen por tipo de division
from collections import Counter
resumen = Counter(r['tipo_division'] for r in rows)
for tipo, n in sorted(resumen.items()):
    print(f'  {tipo}: {n} registros')

print()
subtipo = Counter(r['tipo_barrio'] for r in rows)
for tipo, n in sorted(subtipo.items()):
    print(f'  {tipo}: {n}')

print()
print('Muestra (primeros 8):')
print(f"{'codigo_barrio':<15} {'nombre_barrio':<35} {'tipo_barrio':<8} {'codigo_comuna':<6} {'nombre_comuna'}")
print('-' * 90)
for r in rows[:8]:
    print(f"{r['codigo_barrio']:<15} {r['nombre_barrio']:<35} {r['tipo_barrio']:<8} {r['codigo_comuna']:<6} {r['nombre_comuna']}")
