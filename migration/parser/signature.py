"""Signaturas del Catálogo General: número, código topográfico y su normalización.

Alcance: la signatura es `<número> (<código>) [sufijo]`, por ejemplo `5640 (Ind. C II -24 mn)`. El código es
`Ind. <sección> <tomo> -<legajo> <serie>` o, en las cajas, `Ind. <sección> <serie> – Caja No. <n>`.

Contrato:
- `normalize_code` solo reescribe lo que reconoce con certeza (espacios, puntuación y los dígitos que el OCR confundió
  con numerales romanos: `1` -> `I`, `11` -> `II`). Lo demás se devuelve intacto y `recognized` queda en `False`.
- La signatura original se conserva aparte (ver el adapter): aquí nunca se pierde información.
"""
import re

# Letra de la sección en el código y su encabezado en el catálogo.
SECTION_LETTER = {"CIVIL": "C", "ECLESIÁSTICO": "E", "JUDICIAL": "J", "MILITAR": "M", "PARTICULAR": "P"}

# Abreviatura de la serie en el código, según el encabezado de serie (derivada del propio catálogo).
SERIES_ABBR = {
    ("CIVIL", "Minas"): "mn", ("CIVIL", "Tributos"): "t", ("CIVIL", "Estanco de Tabaco"): "et",
    ("CIVIL", "Hacienda"): "h", ("CIVIL", "Notarial"): "nt", ("CIVIL", "Alcabalas"): "a",
    ("CIVIL", "Contaduría General"): "cg", ("CIVIL", "Contaduría Provincial"): "cp", ("CIVIL", "Correos"): "c",
    ("CIVIL", "Distrital"): "dt", ("CIVIL", "Estanco de Aguardiente"): "ea",
    ("CIVIL", "Fundición y Casa de Moneda"): "f", ("CIVIL", "Hacienda (Gobierno)"): "g",
    ("CIVIL", "Instrucción Pública y Temporalidades"): "it",
    ("ECLESIÁSTICO", "Oratorios y Culto, fábrica (of)"): "of", ("ECLESIÁSTICO", "Capellanías"): "cap",
    ("ECLESIÁSTICO", "Diezmos"): "d", ("ECLESIÁSTICO", "Estipendios"): "e", ("ECLESIÁSTICO", "Gobierno"): "g",
    ("ECLESIÁSTICO", "Juicios"): "j", ("ECLESIÁSTICO", "Obras Pías"): "op",
    ("JUDICIAL", "Civil"): "cv", ("JUDICIAL", "Correspondencia, Certificaciones, secuestros"): "cs",
    ("JUDICIAL", "Criminal"): "cr", ("JUDICIAL", "Rentas"): "r", ("JUDICIAL", "Sucesiones"): "su",
    ("MILITAR", "Juicios"): "j", ("MILITAR", "Personal"): "p", ("MILITAR", "Administración"): "ad",
    ("MILITAR", "Correspondencia"): "c", ("PARTICULAR", "Negocios"): "ne", ("PARTICULAR", "Varios"): "v",
}

_ROMAN = {"1": "I", "11": "II", "111": "III", "l": "I", "ll": "II", "I": "I", "II": "II", "III": "III", "IV": "IV", "V": "V"}
_REGULAR = re.compile(r"^([CEJMP])\s*(I{1,3}|IV|V|1{1,3}|ll?)?\s*-?\s*(\d{1,2})\s*([a-z]{1,3})$")
_BOX = re.compile(r"^([CEJMP])\s+([a-z]{1,3})\s*-\s*Caja\s*No\.?\s*(\d+)$")


def normalize_code(code):
    """`(código, reconocido)`; si no se reconoce se devuelve el original sin cambios."""
    text = code.replace("–", "-").replace(" ", " ")
    match = re.match(r"^\s*Ind\W*(.*)$", text)
    if not match:
        return code, False
    rest = re.sub(r"[()\[\].,:;]", " ", match.group(1))
    rest = re.sub(r"\s+", " ", rest).strip(" -")
    box = _BOX.match(rest)
    if box:
        return f"Ind. {box.group(1)} {box.group(2)} – Caja No. {box.group(3)}", True
    regular = _REGULAR.match(rest)
    if regular:
        letter, roman, legajo, abbr = regular.groups()
        parts = ["Ind.", letter] + ([_ROMAN[roman]] if roman else []) + [f"-{legajo}", abbr]
        return " ".join(parts), True
    return code, False


def clean_number(number):
    """Quita el punto o la coma finales y los espacios sobrantes: `1428.` -> `1428`."""
    return re.sub(r"\s+", " ", number).strip(" .,;:")


def identifier(number, code, suffix):
    """Código de referencia de la descripción, ya con el código normalizado."""
    base = f"{clean_number(number)} ({code})" if code else clean_number(number)
    return f"{base} {suffix}".strip()


def code_letters(code):
    """`(letra de sección, abreviatura de serie)` del código, o `(None, None)` si no se puede leer."""
    normalized, recognized = normalize_code(code)
    if not recognized:
        return None, None
    box = _BOX.match(re.sub(r"^Ind\.\s*", "", normalized.replace("–", "-")))
    if box:
        return box.group(1), box.group(2)
    tokens = normalized.split()
    return tokens[1], tokens[-1]
