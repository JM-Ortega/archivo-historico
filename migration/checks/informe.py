"""Verifica contra el catálogo las correcciones que el Archivo declaró en el informe de anomalías.

Alcance: lee las tablas del informe (.docx) con la columna "Corrección" y comprueba, ficha por ficha, que lo declarado
esté realmente en el catálogo corregido. No corrige nada: solo informa cuáles declaraciones se cumplen y cuáles no.

Contrato: `verify(records, informe_path)` devuelve una lista de `(sección, signatura, declarado, resultado, detalle)` con
`resultado` en `{"ok", "revisar"}`. Las reglas de cada sección están en `_RULES`; una declaración que no se puede
comprobar de forma automática (por ejemplo "Si tiene contenido") se valida contra el campo que menciona.
"""
import html
import re
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "adapters" / "atom"))
import dates_es  # noqa: E402

_FIELD_WORDS = {
    "contenido": "contenido", "fecha": "fecha", "folios": "folios", "folio": "folios", "lugar": "lugar",
    "procedencia": "lugar", "remitente": "remitente", "destinatario": "destinatario", "destinario": "destinatario",
}


def read_tables(path):
    """Tablas del `.docx` como listas de filas de texto."""
    with zipfile.ZipFile(path) as docx:
        xml = docx.read("word/document.xml").decode("utf-8")
    tables = []
    for table in re.findall(r"<w:tbl>.*?</w:tbl>", xml, re.S):
        rows = []
        for row in re.findall(r"<w:tr[ >].*?</w:tr>", table, re.S):
            cells = []
            for cell in re.findall(r"<w:tc>.*?</w:tc>", row, re.S):
                text = "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", cell))
                cells.append(re.sub(r"\s+", " ", html.unescape(text)).strip())
            rows.append(cells)
        tables.append(rows)
    return tables


def mentioned_numbers(path):
    """Todos los números de signatura que el informe cita en cualquier celda."""
    return {number for table in read_tables(path) for row in table for cell in row for number in re.findall(r"\d+", cell)}


def _index(records):
    from collections import defaultdict

    by_number = defaultdict(list)
    for record in records:
        by_number[record["number"].strip(" .")].append(record)
    return by_number


def _fields_in(text):
    words = re.findall(r"[a-záéíóúñ]+", text.lower())
    return {_FIELD_WORDS[word] for word in words if word in _FIELD_WORDS}


def verify(records, informe_path):
    by_number = _index(records)
    results = []

    def add(section, number, declared, ok, detail=""):
        results.append((section, number, declared, "ok" if ok else "revisar", detail))

    def exists(number, code_part=None):
        return [r for r in by_number.get(number, []) if code_part is None or code_part in r["code"]]

    for rows in read_tables(informe_path):
        if not rows or len(rows[0]) < 3 or rows[0][-1] != "Corrección":
            continue
        header = rows[0]
        for row in rows[1:]:
            if len(row) < 3:
                continue
            number, declared = row[0], row[-1]
            low = declared.lower()
            if header[1] == "Código":  # 3. Signaturas repetidas
                found = exists(number)
                dup = [r for r in found if r["code"].replace(" ", "") == row[1].replace(" ", "")]
                ok = len(dup) <= 1
                if "3554" in declared:  # una ficha se eliminó y la otra pasó a ser 3554
                    ok = bool(exists("3554", "E I")) and not exists("3454", "E I")
                add("3. Repetidas", number, declared, ok, f"{len(dup)} fichas con esa signatura y código")
            elif header[1] == "Anotación encontrada":  # 4. Notas de corrección
                found = exists(number)
                if "eliminó esta signatura" in low:
                    add("4. Notas", number, declared, not found, "la ficha ya no debería existir")
                elif not found:
                    add("4. Notas", number, declared, False, "no se encuentra la ficha")
                else:
                    record = found[0]
                    text = " ".join([record["signature_raw"], record["observaciones"]]).lower()
                    leftover = re.search(r"pendiente por corregir|falta destin|ojo:|por error de catalog|figura también", text)
                    add("4. Notas", number, declared, not leftover, "queda la nota en el texto" if leftover else "")
                    if "destinatario" in low:
                        add("4. Notas", number, declared, bool(record["destinatario"]), "destinatario vacío")
            elif header[1] == "Como aparece":  # 5. Número ilegible o código incompleto
                checks = {"l010": ("1010", "E I -9"), "14 39": ("1439", "C I -7"), "(sin número)": ("6423", "M I -11"),
                          "5462": ("5462", "JI 11"), "7370": ("7370", "E I -1"), "6312": ("6312", "M I -11"),
                          "900": ("900", "C I -et"), "4547": ("4547", "14 et"), "4383": ("4383", "C II - 13"),
                          "6293": ("6293", "E I - 14"), "487": ("487", "M I -2")}
                want = checks.get(number)
                found = exists(want[0], want[1]) if want else []
                add("5. Códigos", number, declared, bool(found), f"se esperaba {want[0]} ({want[1]})" if want else "")
            elif header[1] == "Campos a completar":  # 6. Campos ausentes
                found = exists(number)
                wanted = _fields_in(row[1]) | (_fields_in(declared) - {"fecha", "folios", "lugar"} if "agreg" in low else set())
                if "no se registra en el catálogo" in low:
                    add("6. Ausentes", number, declared, True, "el Archivo indica que la ficha no está en el catálogo físico")
                    continue
                if not found:
                    add("6. Ausentes", number, declared, False, "no se encuentra la ficha")
                    continue
                missing = sorted({f for f in wanted if not all(r[f] for r in found)})
                add("6. Ausentes", number, declared, not missing, f"campos aún vacíos: {', '.join(missing)}" if missing else "")
            elif header[1] == "Fecha registrada":  # 7. Fechas
                found = exists(number)
                problems = []
                for record in found:
                    start, end, problem = dates_es.parse(record["fecha"])
                    if problem and "no se registra" not in low:
                        problems.append(f"{problem}: {record['fecha'][:60]}")
                add("7. Fechas", number, declared, bool(found) and not problems, "; ".join(problems) or ("" if found else "no se encuentra"))
            elif header[1] == "Valor registrado":  # 7. Folios
                found = exists(number)
                bad = [r["folios"] for r in found if not re.fullmatch(r"\d{1,4}\.?", r["folios"])]
                add("7. Folios", number, declared, bool(found) and not bad, f"folios actuales: {bad}" if bad else "")
    return results
