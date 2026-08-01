# Convención de códigos DIVIPOLA

DIVIPOLA es la llave universal del proyecto. **Siempre `VARCHAR`, nunca `INT`** — un `INT`
destruye el cero a la izquierda (`05` de Antioquia se vuelve `5` y dos entidades distintas
pueden colisionar).

| Nivel | Longitud | Ejemplo | Tabla |
|---|---|---|---|
| Departamento | 2 dígitos | `05` (Antioquia) | `dim_departamento.id` |
| Municipio | 5 dígitos | `05001` (Medellín) | `dim_municipio.id` |
| Zona | 8 caracteres | `05001Z01` | `dim_zona.id` |
| Comuna/Localidad | 11 caracteres | `05001Z01C01` | `dim_comuna.id` |
| Barrio | 15 caracteres | `05001Z01C01B001` | `dim_barrio.id` |

**Invariante que nunca debe violarse:** `LEFT(id_municipio, 2) = id_departamento`. Verificado
0 violaciones sobre 1.123 municipios en la última auditoría — si una query cross-nivel da
resultados raros, es lo primero a chequear.

**Padding silencioso:** códigos de localidad/comuna en fuentes externas (ej. `codigo_local` sin
padear a 2 dígitos) generan 0 matches sin error visible al hacer JOIN — no falla, simplemente no
encuentra nada. Validar siempre con `LPAD(codigo, N, '0')` antes de comparar contra `c98_geo`.
