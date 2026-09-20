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

Solo existe este `README.md`. El resto se crea cuando haya el primer artefacto real (no se crean carpetas vacías):

```text
migration/
├── README.md
├── canonical/        primer artefacto canonical real
├── adapters/
│   └── atom/         primer adapter AtoM real
├── fixtures/         solo datos sanitizados y aprobados
└── local/            datos reales locales; NO Git
```

Las herramientas exclusivas de migración (parsers, validadores, etc.) viven aquí, no en `scripts/`.

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
