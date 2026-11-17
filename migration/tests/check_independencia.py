"""Comprobaciones de salida de migration/independencia.py sobre el catálogo ficticio (migration/fixtures/)."""
import csv
import sys

out = sys.argv[1]
rows = list(csv.DictReader(open(f"{out}/atom-independencia.csv", encoding="utf-8")))
quarantine = list(csv.DictReader(open(f"{out}/atom-independencia-cuarentena.csv", encoding="utf-8")))
by_id = {row["identifier"]: row for row in rows if row["identifier"]}


def check(condition, message):
    if not condition:
        print(f"FAIL: {message}")
        sys.exit(1)
    print(f"ok: {message}")


levels = [row["levelOfDescription"] for row in rows]
check(levels[:2] == ["Fondo", "Serie"], "Fondo y Serie (Independencia) abren la jerarquía")
check(levels.count("Subserie") == 1 and levels.count("Sección") == 2, "una subserie y dos secciones (orden de la directora)")
check(levels.count("Asunto") == 4, "cuatro unidades documentales cargadas")
check(len(quarantine) == 1 and quarantine[0]["identifier"] == "1 (Ind. C II -24 mn)", "la signatura repetida va a cuarentena")
check(len({row["legacyId"] for row in rows}) == len(rows), "legacyId único")
check(all(row["publicationStatus"] == "Publicado" and row["culture"] == "es" for row in rows), "estado y cultura en español")
first = by_id["1 (Ind. C II -24 mn)"]
check(first["title"] == first["identifier"], "el título es la signatura")
check(first["eventTypes"] == "Remitente|Destinatario" and first["eventStartDates"] == "1813-07-07|1813-07-07", "eventos y fechas ISO")
check(first["extentAndMedium"] == "30 folios", "folios como extensión")
second = by_id["2 (Ind. C II -24 mn)"]
check(second["eventActors"] == "Persona Ficticia Tres|Persona Ficticia Cuatro", "etiqueta mal escrita (Destinario) leída")
check(second["placeAccessPoints"] == "Ciudad Ficticia" and second["extentAndMedium"] == "1 folio", "Lugar del Procedencia y Folio leídos")
third = by_id["3 (Ind. C II -24 mn)"]
check(third["scopeAndContent"].startswith("Contenido pegado"), "etiqueta Contenido en la misma línea")
check(third["placeAccessPoints"] == "" and third["eventStartDates"] == "", "'sin información' y fecha vacía quedan vacíos")
tribute = by_id["10 (Ind. C I -10 t)"]
check(tribute["alternativeIdentifiers"] == "10 (Ind. C 1- 10 t)", "código OCR normalizado y original conservado")
check(tribute["eventStartDates"] == "1819-02-15|1819-02-15" and tribute["eventEndDates"] == "1819-02-17|1819-02-17", "rango de días en un mes")
check(quarantine[0]["eventStartDates"] == "", "rango invertido no se convierte a fechas")
print("PASS")

# Comparación con el catálogo anterior (migration/independencia.py): las correcciones de OCR no cuentan como cambios.
sys.path.insert(0, __file__.rsplit("/tests/", 1)[0])
import independencia  # noqa: E402

key = independencia._key
check([key(n) for n in ("l010", "14 39", "6312 -", "7370 - 1", "1428.", "1428 bis", "")] == ["1010", "1439", "6312", "7370", "1428", "1428bis", ""],
      "signaturas con OCR o ruido se leen con la misma clave")
rec = lambda *numbers: [{"number": n} for n in numbers]
added, removed = independencia.changes_against(rec("l010", "14 39", "6312 -", "7370 - 1", "", "6923", "100"),
                                               rec("1010", "1439", "6312", "7370", "6423", "100", "7540"), {"6423"})
check(added == ["7540"] and removed == ["6923"], "solo cuentan la ficha realmente nueva y la realmente desaparecida")

# Sección 5 del informe: los campos vacíos coinciden con hallazgos.csv.
report_text = open(f"{out}/verificacion.md", encoding="utf-8").read()
check("## 5. Campos vacíos en el catálogo" in report_text, "el informe trae la sección de campos vacíos")
findings = list(csv.DictReader(open(f"{out}/hallazgos.csv", encoding="utf-8")))
dest = sum(1 for f in findings if f["category"] in ("vacio_destinatario", "sin_informacion_destinatario"))
check(f"| Destinatario | " in report_text and f"| {dest} |" in report_text.split("| Destinatario |", 1)[1].split("\n", 1)[0],
      "el total de fichas sin destinatario del informe coincide con hallazgos.csv")

# Cruce remitente/destinatario: las cuatro situaciones suman todas las fichas leídas.
cross = report_text.split("### Cruce remitente / destinatario", 1)[1].split("\n\n", 2)[1]
total = sum(int(line.rsplit("|", 2)[1]) for line in cross.splitlines()[2:])
check(total == sum(1 for _ in open(f"{out}/canonical.jsonl", encoding="utf-8")), "el cruce remitente/destinatario suma todas las fichas")

