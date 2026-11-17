"""Detección de anomalías residuales en el catálogo canónico.

Alcance: vuelve a pasar sobre el catálogo (ya corregido por el Archivo) las mismas revisiones del informe de anomalías y
algunas más, para decidir qué puede migrarse tal cual y qué debe confirmar el Archivo. No modifica ni descarta nada.

Contrato: `analyze(records, parser_issues)` devuelve una lista de hallazgos
`{"seq", "signature", "category", "level", "detail"}`. `level` es `revisar` (lo confirma el Archivo) o `info` (el equipo
ya lo resuelve en la transformación y se deja constancia).
"""
import collections
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "parser"))
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "adapters" / "atom"))
import dates_es  # noqa: E402
import signature as sig  # noqa: E402

PLACEHOLDER = re.compile(r"^\s*sin\s+informaci[oó]n\.?\s*$", re.I)
NOTE = re.compile(r"\b(ojo|pendiente|por corregir|error de catalog\w+)\b", re.I)  # notas de corrección del catalogador
MAX_NUMBER = 7570  # la numeración llega a 7565 (Caja No. 4); por encima hay que revisar


def _finding(record, category, level, detail):
    return {"seq": record["seq"], "signature": record["signature_raw"], "category": category, "level": level, "detail": detail}


def analyze(records, parser_issues=()):
    found = []
    by_identifier = collections.defaultdict(list)
    by_number = collections.defaultdict(list)
    for record in records:
        number = sig.clean_number(record["number"])
        code, recognized = sig.normalize_code(record["code"])
        record["identifier"] = sig.identifier(number, code, record["suffix"])
        by_identifier[record["identifier"]].append(record)
        by_number[number].append(record)

        if not number:
            found.append(_finding(record, "firma_sin_numero", "revisar", "la ficha no trae número de signatura"))
        elif not re.fullmatch(r"\d{1,4}( bis)?", number):
            found.append(_finding(record, "numero_irregular", "revisar", f"número '{number}'"))
        elif int(number.split()[0]) > MAX_NUMBER:
            found.append(_finding(record, "numero_fuera_de_rango", "revisar", f"número {number} (> {MAX_NUMBER})"))
        if not record["code"]:
            found.append(_finding(record, "firma_sin_codigo", "revisar", "falta el código entre paréntesis"))
        elif not recognized:
            found.append(_finding(record, "codigo_irreconocible", "revisar", record["code"]))
        elif code != record["code"]:
            found.append(_finding(record, "codigo_normalizado", "info", f"{record['code']} -> {code}"))
        if record["suffix"]:
            found.append(_finding(record, "firma_con_sufijo", "revisar", f"sufijo '{record['suffix']}' tras el código"))

        letter, abbr = sig.code_letters(record["code"])
        if letter and letter != sig.SECTION_LETTER.get(record["section"]):
            found.append(_finding(record, "seccion_distinta_del_codigo", "revisar",
                                  f"el código dice '{letter}' y la ficha está en {record['section']}"))
        expected = sig.SERIES_ABBR.get((record["section"], record["series"]))
        if abbr and expected and abbr != expected:
            found.append(_finding(record, "serie_distinta_del_codigo", "revisar",
                                  f"el código dice '{abbr}' y la ficha está en {record['series']} ({expected})"))

        for name in ("remitente", "destinatario", "contenido", "lugar", "fecha", "folios", "observaciones"):
            value = record[name]
            if not value:
                level = "revisar" if name == "contenido" else "info"
                found.append(_finding(record, f"vacio_{name}", level, "campo vacío"))
            elif PLACEHOLDER.match(value):
                found.append(_finding(record, f"sin_informacion_{name}", "info", "'sin información' se migra como vacío"))
        for name in ("contenido", "observaciones", "remitente", "destinatario"):
            if record[name] and NOTE.search(record[name]):
                found.append(_finding(record, "nota_en_el_texto", "revisar", f"{name}: ...{NOTE.search(record[name]).group(0)}..."))
        if NOTE.search(record["signature_raw"].replace(record["code"], "")):
            found.append(_finding(record, "nota_en_la_firma", "revisar", record["signature_raw"]))

        if record["fecha"] and not PLACEHOLDER.match(record["fecha"]):
            start, end, problem = dates_es.parse(record["fecha"])
            if problem:
                found.append(_finding(record, f"fecha_{problem}", "revisar", record["fecha"][:100]))
        folios = record["folios"]
        if folios and not PLACEHOLDER.match(folios) and not re.fullmatch(r"\d{1,4}\.?", folios):
            found.append(_finding(record, "folios_no_numerico", "revisar", folios[:60]))

    for ident, group in by_identifier.items():
        if len(group) > 1:
            for record in group:
                found.append(_finding(record, "firma_repetida", "revisar", f"{ident} aparece {len(group)} veces"))
    for number, group in by_number.items():
        if number and len(group) > 1 and len({r["identifier"] for r in group}) == len(group):
            for record in group:
                found.append(_finding(record, "numero_repetido_codigo_distinto", "info",
                                      f"el número {number} aparece con códigos distintos"))
    for seq, kind, detail in parser_issues:
        found.append({"seq": seq, "signature": "", "category": f"lectura_{kind}", "level": "info", "detail": detail})
    return found


def summary(findings):
    counter = collections.Counter((f["level"], f["category"]) for f in findings)
    return sorted(counter.items(), key=lambda item: (item[0][0], -item[1]))
