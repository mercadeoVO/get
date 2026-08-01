# Cobertura real de geometría — qué JOIN va a devolver NULL

Antes de asumir que un nivel tiene polígono real para toda Colombia, revisa esta tabla.
"Con geometría" = existe fila en `geo_geometry` con `geom IS NOT NULL` para esa entidad.

| Nivel | Cobertura | Detalle |
|---|---|---|
| Región | 5/5 (100%) | Derivado por `ST_Union` de sus departamentos. |
| Departamento | 33/33 (100%) | Fuente directa DANE MGN2025. |
| Subregión | 143/143 (100%) | Derivado por `ST_Union` de sus municipios. |
| Municipio | 1.121/1.123 (99,9%) | Fuente directa DANE MGN2025. Faltan `94663` (Mapiripán, causa no investigada) — `27086` ya fue migrado a `27493` y sí tiene geometría. |
| Zona | Solo zonas urbanas de las 5 ciudades grandes (Bogotá, Medellín, Cali, Barranquilla, Cartagena) | Derivado por `ST_Union` de sus comunas. El resto del país (zonas rurales, municipios sin comuna/barrio en el esquema) no tiene de qué derivar geometría de zona. |
| Comuna/Localidad | 83/84 (100% salvo Sumapaz) | Sumapaz es localidad rural de Bogotá sin barrios legalizados — hueco esperado, no error. |
| Barrio | 2.271 filas, solo 5 ciudades | Bogotá (1.203, vía Sector Catastral), Medellín (330/332), Cali (342/342), Barranquilla (186/191), Cartagena (213). **Ningún otro municipio de Colombia tiene barrio cargado.** |

**Regla práctica:** si vas a hacer un mapa/coropletas a nivel zona/comuna/barrio para un
municipio que no sea una de las 5 ciudades grandes, no va a haber geometría — el mapa quedará
vacío en ese nivel, no es un bug de la query. Departamento y municipio sí tienen cobertura
nacional completa (salvo el caso puntual de Mapiripán).

**Resolución (`low`/`mid`/`high`) por nivel:**

| Nivel | `low` (tol. 0.01°) | `mid` (tol. 0.001°) | `high` (completa) |
|---|---|---|---|
| Región | ✅ | ✅ | ✅ |
| Departamento | ✅ | ✅ | ✅ |
| Subregión | ✅ | ✅ | ✅ |
| Municipio | — (no aporta a zoom nacional con 1.123 polígonos) | ✅ | ✅ |
| Zona / Comuna / Barrio | — | — | ✅ (solo se ven en zoom cercano) |

Si necesitas dibujar el mapa nacional completo, usa `resolution='low'` en departamento y no
intentes cargar `high` de los 1.123 municipios a la vez — el ejemplo real de Antioquia fue
44.385 vértices en `high` vs. 346 en `low`.
