# Modelo canónico

Representación intermedia **propia del proyecto**, independiente de AtoM. La produce `parser/catalog.py` a partir del
catálogo en Word y la consume `adapters/atom/csv_adapter.py`. Un registro por ficha del catálogo.

| Campo | Contenido |
| --- | --- |
| `seq` | Posición de la ficha en el catálogo (1..N); base del `legacyId` (`IND-D-<seq>`) |
| `marker` | Número `-N-` que precede a la ficha en el catálogo, si lo trae |
| `section`, `series` | Encabezados del catálogo (por ejemplo `CIVIL` / `Minas`) |
| `signature_raw` | Signatura tal como está escrita (`5640 (Ind. C II -24 mn) b`) |
| `number`, `code`, `suffix` | Partes de la signatura: número, código topográfico entre paréntesis y sufijo posterior |
| `identifier` | Signatura con el código normalizado; lo añade `checks/anomalies.py` |
| `remitente`, `destinatario`, `contenido`, `lugar`, `fecha`, `folios`, `observaciones` | Texto de cada campo, con espacios colapsados y sin más cambios |

Reglas del modelo:

- Lo ausente es cadena vacía; "sin información" se conserva tal cual aquí y se migra como vacío en el adapter.
- No se infiere ni se completa ningún dato. Lo dudoso se registra como hallazgo (`checks/anomalies.py`), no se corrige.
- Los archivos con datos reales (`canonical.jsonl`) viven en `migration/local/`, fuera de Git.
