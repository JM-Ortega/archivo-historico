"""Parser del Catálogo General del Archivo Central del Cauca.

Alcance: lee el catálogo en Word (.docx) o en texto plano (una línea por párrafo) y devuelve los registros en el modelo
canónico del proyecto (ver migration/canonical/README.md). No conoce AtoM: el formato de importación lo produce el
adapter (migration/adapters/atom/).

Contrato:
- Entrada: ruta a `.docx` o `.txt`. Solo biblioteca estándar de Python 3.
- Salida: `(records, issues)`. Cada registro es un `dict` con las claves de `FIELDS`; no se inventa ningún dato:
  lo ausente queda como cadena vacía y lo dudoso se anota en `issues`.
- Se normalizan únicamente espacios y etiquetas mal escritas ("Lugar del Procedencia", "Destinario", ...). El texto de los
  campos se conserva tal cual lo escribió el catalogador.

Límites: asume la estructura del catálogo de Independencia (encabezado de sección y serie, ficha con `Signatura:`).
Los datos reales nunca se versionan: viven en migration/local/ (ver migration/README.md).
"""
import html
import re
import zipfile

FIELDS = ("remitente", "destinatario", "contenido", "lugar", "fecha", "folios", "observaciones")

# Etiquetas por campo. Se aceptan las erratas más frecuentes del catálogo; la etiqueta siempre abre la línea.
_LABELS = (
    ("signatura", re.compile(r"^(?:Signatura|Sig\.)\s*[:.]?\s*(.*)$")),
    ("remitente", re.compile(r"^Remitentes?\s*[:.]?\s*(.*)$")),
    ("destinatario", re.compile(r"^(?:Destinatari[oa]s?|Destinario|Destintario)\s*[:.]?\s*(.*)$")),
    ("contenido", re.compile(r"^Contenido\s*[:.]?\s*(.*)$")),
    ("lugar", re.compile(r"^(?:Lugar|Lunar)\b(?:\s+\w+\.?){0,3}?\s+[Pp]roc\w*\.?\s*[:.]?\s*(.*)$")),
    ("lugar", re.compile(r"^[Pp]rocedencia\s*[:.]?\s*(.*)$")),
    ("lugar", re.compile(r"^Lugar(?:\s+de)?\s*:\s*(.*)$")),
    ("fecha", re.compile(r"^Fecha\s*[:.]?\s*(.*)$")),
    ("folios", re.compile(r"^(?:Folios?|folios)\s*[:.]?\s*(.*)$")),
    ("observaciones", re.compile(r"^Obs\w*\s*[:.]?\s*(.*)$")),
)
_MARKER = re.compile(r"^(?:-\s*(\d+)\s*-?|(\d+)\s*-)$")
# Etiqueta que aparece en medio de una línea (varios campos pegados): exige mayúscula inicial y dos puntos.
_INLINE = re.compile(
    r"[\s\-]+(?P<label>Remitentes?|Destinatari[oa]s?|Contenido|[Ll]ugar de [Pp]rocedencia|Fecha|Folios?|Observaciones)\s*:\s*"
)
_INLINE_FIELD = {
    "Remitente": "remitente", "Remitentes": "remitente", "Destinatario": "destinatario", "Destinataria": "destinatario",
    "Destinatarios": "destinatario", "Contenido": "contenido", "Lugar de Procedencia": "lugar", "lugar de procedencia": "lugar", "Lugar de procedencia": "lugar", "Fecha": "fecha",
    "Folio": "folios", "Folios": "folios", "Observaciones": "observaciones",
}
_PLACE_DATE = re.compile(r"^(?P<lugar>[^:]{2,40}?)\.\s+Fecha\s*:\s*(?P<fecha>.*)$")
_SIGNATURE = re.compile(r"^(?P<number>[^()]*?)\s*\((?P<code>[^)]*)\)\s*(?P<suffix>.*)$")
_HEADER = "ARCHIVO CENTRAL DEL CAUCA"


def squash(text):
    """Colapsa espacios (incluidos los no separables) y recorta."""
    return re.sub(r"\s+", " ", text.replace(" ", " ")).strip()


def read_lines(path):
    """Párrafos del `.docx` (o líneas del `.txt`) ya sin saltos internos."""
    if str(path).lower().endswith(".docx"):
        with zipfile.ZipFile(path) as docx:
            xml = docx.read("word/document.xml").decode("utf-8")
        lines = []
        for paragraph in re.findall(r"<w:p[ >].*?</w:p>", xml, re.S):
            text = "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", paragraph))
            lines.append(html.unescape(text))
        return lines
    with open(path, encoding="utf-8") as handle:
        return handle.read().splitlines()


def _label(line):
    for name, pattern in _LABELS:
        match = pattern.match(line)
        if match:
            return name, match.group(1)
    return None


def split_signature(raw):
    """`5640 (Ind. C II -24 mn) b` -> número, código entre paréntesis y sufijo posterior."""
    match = _SIGNATURE.match(raw)
    if not match:
        return squash(raw), "", ""
    return squash(match.group("number")), squash(match.group("code")), squash(match.group("suffix")).strip(" .,;:")


def parse(lines):
    """Devuelve `(records, issues)`; `issues` es una lista de `(seq, tipo, detalle)`."""
    records, issues = [], []
    section = series = ""
    marker = None
    current = None
    field = None

    def close():
        nonlocal current
        if current is not None:
            for key in FIELDS:
                current[key] = squash(current[key])
            current["signature_raw"] = squash(current["signature_raw"])
            number, code, suffix = split_signature(current["signature_raw"])
            current.update(number=number, code=code, suffix=suffix)
            records.append(current)
        current = None

    def feed(name, text):
        """Asigna `text` al campo `name`; si trae etiquetas en línea, reparte el resto en sus campos."""
        nonlocal field
        parts = list(_INLINE.finditer(text))
        head = text[: parts[0].start()] if parts else text
        if head:
            current[name] = (current[name] + " " + head) if current[name] else head
        field = name
        for position, part in enumerate(parts):
            end = parts[position + 1].start() if position + 1 < len(parts) else len(text)
            target = _INLINE_FIELD[part.group("label")]
            issues.append((current["seq"], "etiqueta_en_linea", target))
            value = text[part.end() : end]
            current[target] = (current[target] + " " + value) if current[target] else value
            field = target

    index = 0
    total = len(lines)
    while index < total:
        line = squash(lines[index])
        index += 1
        if not line:
            continue
        if line == _HEADER:
            close()
            context = []
            while index < total and len(context) < 3:
                candidate = squash(lines[index])
                index += 1
                if candidate:
                    context.append(candidate)
            if len(context) == 3:
                section, series = context[1], context[2]
            continue
        found = _MARKER.match(line)
        if found:
            marker = int(found.group(1) or found.group(2))
            continue
        label = _label(line)
        if label and label[0] == "signatura":
            close()
            current = {key: "" for key in FIELDS}
            current.update(
                seq=len(records) + 1,
                marker=marker,
                section=section,
                series=series,
                signature_raw=label[1],
            )
            field = "signatura"
            continue
        if current is None:
            issues.append((None, "linea_fuera_de_ficha", line[:80]))
            continue
        if label:
            field = label[0]
            if current[field] and label[1]:
                issues.append((current["seq"], "campo_repetido", field))
            feed(field, label[1])
            continue
        place = _PLACE_DATE.match(line)
        if place and not current["lugar"] and not current["fecha"]:
            current["lugar"], current["fecha"] = place.group("lugar"), place.group("fecha")
            field = "fecha"
            issues.append((current["seq"], "lugar_y_fecha_en_una_linea", line[:80]))
            continue
        if field in ("fecha", "folios", "lugar") and not current["observaciones"] and field != "fecha":
            # Texto suelto tras Folios/Lugar: en el catálogo es la nota sin etiqueta ("Manuscrito. Original.").
            current["observaciones"] = line
            field = "observaciones"
            issues.append((current["seq"], "observaciones_sin_etiqueta", line[:80]))
            continue
        if field == "signatura":
            current["signature_raw"] += " " + line
        else:
            feed(field, line)
    close()
    return records, issues
