"""Adapter AtoM: registros canónicos -> CSV de importación de descripciones archivísticas.

Alcance: produce el CSV que consume `php symfony csv:import` (AtoM 2.10, cultura `es`) para la Sección Independencia:
Fondo -> Serie -> Subserie -> Sección -> Asunto. El CSV de AtoM es la SALIDA de este adapter; el modelo de datos
del proyecto es el canónico (ver migration/canonical/README.md).

Decisiones (acordadas con el Archivo o derivadas del comportamiento verificado de AtoM):
- Título = signatura completa (pedido de la directora el 6-nov-2026); no se generan títulos.
- Los niveles y el estado van en español exacto (`Fondo`, `Serie`, `Subserie`, `Sección`, `Asunto`, `Publicado`): con `culture=es` AtoM
  busca el nombre en español y, si no coincide, crea términos duplicados y deja la descripción en Borrador.
- Remitente y Destinatario son eventos con actor (tipos creados por el reconcile); la fecha va en cada evento.
- La signatura original se conserva en `alternativeIdentifiers` cuando la normalización cambió algo.
- Una signatura ya usada no se carga dos veces: la repetida va al CSV de cuarentena hasta que el Archivo le asigne una.
- "sin información" se migra como campo vacío; nada se inventa.
"""
import csv
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import dates_es  # noqa: E402

COLUMNS = [
    "legacyId", "parentId", "identifier", "title", "levelOfDescription", "extentAndMedium", "repository",
    "scopeAndContent", "generalNote", "placeAccessPoints", "alternativeIdentifiers", "alternativeIdentifierLabels",
    "publicationStatus", "eventDates", "eventTypes", "eventStartDates", "eventEndDates", "eventActors", "culture",
]

# Configuración de la carga; cualquier cambio de nombres de nivel se hace aquí y se vuelve a generar el CSV.
REPOSITORY = "Centro de Investigaciones Históricas José María Llorente"
FONDS_TITLE = "Fondo Antiguo Archivo Central del Cauca"
SECTION_TITLE = "Independencia"
# Jerarquía dada por la directora el 6-nov-2026, tal cual: Fondo, Serie, Subserie, Sección y Asunto. Se asigna por profundidad:
#   Fondo Antiguo (1) > Independencia (2: Serie) > CIVIL, ECLESIÁSTICO... (3: Subserie) > Minas, Tributos... (4: Sección) > ficha (5: Asunto)
# Las claves son la profundidad en el catálogo; cambiar un nombre es editar este diccionario y regenerar el CSV.
LEVELS = {
    "fonds": "Fondo", "section": "Serie", "series": "Subserie", "subseries": "Sección", "document": "Asunto",
}
PUBLISHED = "Publicado"
ORIGINAL_LABEL = "Signatura original del catálogo"

_PLACEHOLDER = re.compile(r"^\s*sin\s+informaci[oó]n\.?\s*$", re.I)
_ABBREVIATION = re.compile(r"\b(?:Sr|Sra|Dr|Dra|Dn|Da|Gral|Cnel|Ilmo|Exmo|Fr|Br|Pbro|Ldo)\.$")


def _clean(value):
    value = re.sub(r"\s+", " ", value or "").strip()
    return "" if _PLACEHOLDER.match(value) else value.replace("|", "/")


def _trim_period(value):
    value = _clean(value)
    return value if _ABBREVIATION.search(value) else value.rstrip(" .;,")


def _extent(folios):
    folios = _trim_period(folios)
    if re.fullmatch(r"\d{1,4}", folios):
        return f"{int(folios)} folio" + ("" if int(folios) == 1 else "s")
    return folios if re.search(r"\d", folios) else ""  # "l" u otro valor sin dígitos no se migra


def _title_case(name):
    return name.capitalize() if name.isupper() else name


def _container(legacy, parent, title, level, repository=""):
    row = {column: "" for column in COLUMNS}
    row.update(legacyId=legacy, parentId=parent, title=title, levelOfDescription=level, repository=repository,
               publicationStatus=PUBLISHED, culture="es")
    return row


def _document(record, parent):
    row = {column: "" for column in COLUMNS}
    original = record["signature_raw"]
    actors, types = [], []
    for name, label in (("remitente", "Remitente"), ("destinatario", "Destinatario")):
        actor = _trim_period(record[name])
        if actor:
            actors.append(actor)
            types.append(label)
    date_text = _trim_period(record["fecha"])
    start, end, _ = dates_es.parse(date_text) if date_text else ("", "", "")
    events = max(len(actors), 1 if date_text else 0)
    row.update(
        legacyId=f"IND-D-{record['seq']:05d}",
        parentId=parent,
        identifier=record["identifier"],
        title=record["identifier"],
        levelOfDescription=LEVELS["document"],
        extentAndMedium=_extent(record["folios"]),
        scopeAndContent=_clean(record["contenido"]),
        generalNote=_clean(record["observaciones"]),
        placeAccessPoints=_trim_period(record["lugar"]),
        publicationStatus=PUBLISHED,
        eventDates="|".join([date_text] * events),
        eventTypes="|".join(types),
        eventStartDates="|".join([start] * events) if start else "",
        eventEndDates="|".join([end] * events) if end else "",
        eventActors="|".join(actors),
        culture="es",
    )
    if original != record["identifier"]:
        row.update(alternativeIdentifiers=original, alternativeIdentifierLabels=ORIGINAL_LABEL)
    return row


def build(records):
    """`(filas, cuarentena)`: contenedores + documentos únicos, y los documentos con signatura repetida."""
    rows = [_container("IND-F", "", FONDS_TITLE, LEVELS["fonds"], REPOSITORY),
            _container("IND-S", "IND-F", SECTION_TITLE, LEVELS["section"])]
    quarantine, seen = [], set()
    series_ids, subseries_ids = {}, {}
    for record in records:
        section, series = record["section"], record["series"]
        if section not in series_ids:
            series_ids[section] = f"IND-SE-{len(series_ids) + 1:02d}"
            rows.append(_container(series_ids[section], "IND-S", _title_case(section), LEVELS["series"]))
        key = (section, series)
        if key not in subseries_ids:
            subseries_ids[key] = f"IND-SS-{len(subseries_ids) + 1:02d}"
            rows.append(_container(subseries_ids[key], series_ids[section], series, LEVELS["subseries"]))
        document = _document(record, subseries_ids[key])
        if record["identifier"] in seen:
            quarantine.append(document)
        else:
            seen.add(record["identifier"])
            rows.append(document)
    return rows, quarantine


def write_csv(path, rows):
    with open(path, "w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
