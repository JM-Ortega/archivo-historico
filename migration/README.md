# Migration

Frontera del pipeline y tooling de migración hacia AtoM. Contexto general: [docs/repository-layout.md](../docs/repository-layout.md).

## Flujo

```text
fuente real  →  raw / local  →  canonical  →  adapter AtoM
```

1. **Fuente real:** los originales del Archivo (PDF, Word, Excel, …). No se versionan.
2. **Raw / local:** copia de trabajo o extracción en bruto. Vive en `local/` y **no** entra en Git.
3. **Canonical:** representación intermedia **propia del proyecto**, independiente de AtoM.
4. **Adapter AtoM:** transforma el canonical al formato que consume AtoM.

**AtoM CSV/XML ≠ canonical del proyecto.** Los CSV/XML de importación de AtoM son la *salida* de un adapter, no el
modelo de datos del proyecto.

## Estructura

```text
migration/
├── README.md
├── independencia.py       comando: catálogo -> verificación -> CSV de AtoM
├── parser/                catalog.py (lee el Word), signature.py (signaturas y su normalización)
├── canonical/             modelo canónico (README.md)
├── checks/                anomalies.py (anomalías residuales), informe.py (cumplimiento del informe del Archivo)
├── adapters/
│   └── atom/              csv_adapter.py (CSV de importación de AtoM), dates_es.py (fechas en español -> ISO)
├── fixtures/              solo datos ficticios o sanitizados y aprobados
├── tests/                 test-independencia.sh y check_independencia.py
└── local/                 datos reales locales; NO Git
```

Las herramientas exclusivas de migración (parsers, validadores, etc.) viven aquí, no en `scripts/`.

## Sección Independencia (piloto)

Pipeline: catálogo corregido por el Archivo (`.docx`) → `parser` → canónico → `checks` → `adapter AtoM` → CSV →
tareas **nativas** de AtoM (`csv:check-import`, `csv:import`). No se modifica `upstream/atom`; los términos que la carga
necesita (niveles «Sección» y «Asunto», eventos «Remitente» y «Destinatario») los declara el reconcile: «Sección», «Remitente» y «Destinatario» en `config/atom/reconcile-terms.php` (sin cambios) y «Asunto» en la copia `config/atom/reconcile-terms-v2-asunto.php`, que se ejecuta ANTES de importar (`php symfony tools:run …`) para que el término nazca con su `code` y la importación no cree uno suelto.

```bash
# 1. Generar y verificar (Python 3 de la imagen `atom`; sin dependencias nuevas en el host)
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD/migration:/migration:ro" -v "$PWD/migration/local:/out" \
  -v "<carpeta con los .docx>:/in:ro" --entrypoint python3 archivo-historico/atom:2.10.2 -I /migration/independencia.py \
  --catalog "/in/GLOBAL INDEPENDENCIA.docx" --informe /in/Informe_anomalias_Independencia.docx --out /out/independencia \
  --previous "/in/GLOBAL INDEPENDENCIA_ANTIGUO-v1-2026-09-24.docx"

# 2. Validar con AtoM y cargar (el CSV se copia al contenedor; --source-name evita mezclar cargas)
docker compose cp migration/local/independencia/atom-independencia.csv atom:/tmp/ind.csv
docker compose exec -T atom php symfony csv:check-import -i /tmp/ind.csv
docker compose exec -T atom php symfony csv:import --index --no-confirmation --source-name=independencia \
  --error-log=/tmp/independencia-error.log /tmp/ind.csv
```

`--previous` es el catálogo anterior (el renombrado `…_ANTIGUO-v1-<fecha>`): sin él `verificacion.md` no trae la sección 3 («Cambios entre el catálogo anterior y el actual», fichas nuevas o desaparecidas sin declarar) y los demás archivos salen iguales. La comparación lee la «l» del OCR como 1 y une dígitos separados (`l010` = `1010`, `14 39` = `1439`), así que una corrección de OCR no cuenta como ficha desaparecida.

Salidas (todas en `migration/local/independencia/`): `atom-independencia.csv` (carga), `atom-independencia-cuarentena.csv`
(signaturas repetidas, a la espera de que el Archivo asigne una), `hallazgos.csv`, `verificacion.md` y `canonical.jsonl`.
Deshacer una carga en DEV: `php symfony tools:delete-description -B <slug del fondo>` (tarea nativa de AtoM).

Contrato de la carga (derivado del comportamiento verificado de AtoM 2.10 con `culture=es`):

- Niveles y estado en español exacto (`Fondo`, `Serie`, `Subserie`, `Sección`, `Asunto`, `Publicado`; orden dado por la directora); con otro
  nombre AtoM crea términos duplicados y deja la descripción en Borrador.
- Título = signatura completa. `identifier` conserva la signatura; si la normalización la cambió, la original va en
  `alternativeIdentifiers`.
- Remitente y Destinatario son eventos con actor; la fecha va en `eventDates` (texto original) y en
  `eventStartDates`/`eventEndDates` (ISO, solo si se pudo convertir sin adivinar).
- Una signatura no se carga dos veces: las repetidas van al CSV de cuarentena.

Prueba: `migration/tests/test-independencia.sh` (catálogo ficticio de `fixtures/`; no toca la base de datos).

## `local/`: datos reales, fuera de Git

`migration/local/` es el workspace local para datos reales del Archivo. Está ignorado por `.gitignore`
(`/migration/local/`), por lo que su contenido no entra mediante el staging normal. Es una frontera deliberadamente
no versionable: **no uses `git add -f`** para incorporar datos reales desde allí.

Va ahí: PDF/Word/Excel reales, corpus completo, extractos con datos personales, volcados y salidas intermedias
con datos reales. **No** pongas datos reales fuera de `local/`.

Lo que se versiona son solo `fixtures/` **sanitizados y aprobados** (sin datos personales ni contenido real sin
autorización). En caso de duda, va a `local/`.

Portabilidad: se evitan dependencias obligatorias del host cuando Docker pueda proporcionar razonablemente el tooling.
El stack y el flujo concretos los decidirá la WU de migración; esta foundation no obliga a ejecutar exclusivamente
dentro de contenedores.

**Fuentes vigentes (regla para no cargar el archivo equivocado):** se migra solo con `GLOBAL INDEPENDENCIA.docx` e
`Informe_anomalias_Independencia.docx` de `Documentos Rhizome Proyecto 1/Documentación/` (6-nov-2026). Las copias anteriores
con el mismo nombre se renombraron `…_ANTIGUO-v1-<fecha>`. `verificacion.md` registra la huella SHA-256 de cada entrada.

**Carga y rollback en un paso:** `migration/load-independencia.sh load` (reconcile v2 → comprueba los cuatro términos → `csv:check-import` →
`csv:import` → `search:populate`, con parada al primer error) y `migration/load-independencia.sh rollback` (borra el fondo por slug solo si
tiene los descendientes esperados; usa `memory_limit=-1`).
