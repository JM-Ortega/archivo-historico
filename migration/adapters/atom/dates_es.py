"""Fechas del catálogo (texto libre en español) -> fecha inicial y final en formato ISO.

Alcance: convierte lo que escribió el catalogador ("27 de Marzo - 5 de Diciembre de 1816.", "Febrero 15 de 1818",
"15 y 17 de Febrero de 1819") en `(inicio, fin)` con forma `AAAA-MM-DD`. El texto original se conserva aparte, en
`eventDates`; esto solo alimenta la búsqueda por rango de AtoM.

Contrato: `parse(text)` devuelve `(inicio, fin, problema)`. Si no se puede resolver sin adivinar (falta el año, día
imposible), devuelve `("", "", <motivo>)`; nunca inventa un año. Con solo mes se usa el mes completo y con solo año,
el año completo.
"""
import calendar
import re

_MONTHS = {
    "enero": 1, "febrero": 2, "marzo": 3, "abril": 4, "mayo": 5, "junio": 6, "julio": 7, "agosto": 8,
    "septiembre": 9, "setiembre": 9, "octubre": 10, "noviembre": 11, "diciembre": 12,
}
_TOKEN = re.compile(r"\d{4}|\d{1,2}|[a-záéíóúñ]+")


def _mentions(text):
    """Lista de menciones `{d, m, y}` en orden de aparición; el año se reparte hacia atrás."""
    found = []
    for token in _TOKEN.findall(text.lower().replace("º", "").replace("°", "")):
        if re.fullmatch(r"\d{4}", token):
            pending = [mention for mention in found if mention["y"] is None]
            for mention in pending:
                mention["y"] = int(token)
            if not pending:
                found.append({"d": None, "m": None, "y": int(token)})  # solo año: "1819", "1817 - 1818"
        elif token == "mismo":
            known = [mention["y"] for mention in found if mention["y"] is not None]
            if known:  # "31 de diciembre del mismo año"
                for mention in found:
                    if mention["y"] is None:
                        mention["y"] = known[-1]
        elif token.isdigit():
            day = int(token)
            if found and found[-1]["m"] is not None and found[-1]["d"] is None and found[-1]["y"] is None:
                found[-1]["d"] = day  # "Febrero 15": el mes abre la mención y el día la completa
            else:
                found.append({"d": day, "m": None, "y": None})
        elif token in _MONTHS:
            pending = [item for item in found if item["m"] is None and item["d"] is not None and item["y"] is None]
            if pending:
                for item in pending:
                    item["m"] = _MONTHS[token]  # "15 y 17 de Febrero": un mes para varios días
            else:
                found.append({"d": None, "m": _MONTHS[token], "y": None})
    return found


def _bounds(mention):
    year, month, day = mention["y"], mention["m"], mention["d"]
    if month is None:
        return f"{year:04d}-01-01", f"{year:04d}-12-31"
    last = calendar.monthrange(year, month)[1]
    if day is None:
        return f"{year:04d}-{month:02d}-01", f"{year:04d}-{month:02d}-{last:02d}"
    if not 1 <= day <= last:
        raise ValueError("dia_imposible")
    return f"{year:04d}-{month:02d}-{day:02d}", f"{year:04d}-{month:02d}-{day:02d}"


def parse(text):
    """`(inicio, fin, problema)`; `problema` queda vacío cuando la conversión fue completa."""
    text = (text or "").strip()
    if not text:
        return "", "", ""
    mentions = _mentions(text)
    if not mentions:
        return "", "", "sin_fecha_reconocible"
    if any(item["y"] is None for item in mentions):
        return "", "", "falta_el_ano"
    try:
        spans = [_bounds(item) for item in mentions]
    except ValueError as error:
        return "", "", str(error)
    starts = [span[0] for span in spans]
    if re.search(r"[-–]|\sa\s", text) and len(spans) > 1 and starts[0] > starts[-1]:
        return "", "", "rango_invertido"  # no se adivina cuál de los dos extremos está mal
    return min(span[0] for span in spans), max(span[1] for span in spans), ""
