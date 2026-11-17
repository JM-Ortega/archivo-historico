"""Migración de la Sección Independencia: catálogo corregido -> verificación -> CSV de AtoM.

Uso (desde la raíz del repo, dentro del contenedor `atom`; ver migration/README.md):

    python3 -I migration/independencia.py --catalog <GLOBAL INDEPENDENCIA.docx> --informe <Informe_anomalias.docx> \\
        --out migration/local/independencia [--previous <catálogo anterior .docx>]

Entradas: catálogo en Word (o `.txt`) e informe de anomalías con la columna "Corrección".
Salidas en `--out` (todas con datos reales: viven en migration/local/, fuera de Git):
- canonical.jsonl: un registro por ficha en el modelo canónico del proyecto.
- hallazgos.csv: todas las anomalías residuales, con su nivel.
- verificacion.md: informe legible (qué se cumplió del informe, qué cambió sin declararse, qué falta).
- atom-independencia.csv y atom-independencia-cuarentena.csv: la entrada de `csv:import` de AtoM.
Códigos de salida: 0 todo generado; 2 error de uso.
"""
import argparse
import collections
import csv
import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
for sub in ("parser", "checks", "adapters/atom"):
    sys.path.insert(0, str(ROOT / sub))
import anomalies  # noqa: E402
import catalog  # noqa: E402
import csv_adapter  # noqa: E402
import informe  # noqa: E402


def load(path):
    records, issues = catalog.parse(catalog.read_lines(path))
    findings = anomalies.analyze(records, issues)
    return records, findings


def _key(number):
    """Clave comparable de una signatura, para no confundir una corrección de OCR con una ficha nueva o desaparecida.

    Lee la «l» del OCR como 1 y une los dígitos separados por espacios: `l010` -> `1010`, `14 39` -> `1439`,
    `7370 - 1` -> `7370`, `6312 -` -> `6312`. Conserva el sufijo `bis` (1428 y 1428 bis son fichas distintas).
    Una signatura sin dígitos devuelve `""` y no se compara (su corrección la declara el informe).
    """
    text = number.strip(" .").lower().replace(" ", "")
    match = re.match(r"[l\d]+", text)
    if not match:
        return ""
    digits = match.group(0).replace("l", "1")
    return digits + ("bis" if text[match.end():].startswith("bis") else "")


def changes_against(previous, current, mentioned):
    """Fichas que aparecieron o desaparecieron (por número de signatura) sin que el informe las cite.

    Compara por `_key`: una signatura que solo cambió por una corrección de OCR (`l010` -> `1010`) no cuenta como cambio.
    """
    def numbers(records):
        return collections.Counter(k for k in (_key(r["number"]) for r in records) if k)

    before, after = numbers(previous), numbers(current)
    mentioned = {_key(number) for number in mentioned} | set(mentioned)
    added = sorted(set(after) - set(before) - mentioned, key=lambda n: (len(n), n))
    removed = sorted(set(before) - set(after) - mentioned, key=lambda n: (len(n), n))
    return added, removed


_FIELD_LABELS = {"remitente": "Remitente", "destinatario": "Destinatario", "contenido": "Contenido (alcance y contenido)",
                 "lugar": "Lugar", "fecha": "Fecha", "folios": "Folios", "observaciones": "Observaciones (notas)"}


def _blank(value):
    """Vacío o «sin información»: el catálogo no trae el dato."""
    text = value.strip(" .").lower()
    return text == "" or text.startswith("sin información") or text.startswith("sin informacion")


def sender_recipient_section(records):
    """Tabla de cruce remitente/destinatario: cuántas fichas tienen uno, otro, ambos o ninguno (informativo)."""
    pairs = collections.Counter()
    anonymous = [0, 0]  # [con destinatario, sin destinatario]
    for r in records:
        has_sender, has_recipient = not _blank(r["remitente"]), not _blank(r["destinatario"])
        pairs[(has_sender, has_recipient)] += 1
        if has_sender and re.search(r"an[oó]nim", r["remitente"].lower()):
            anonymous[0 if has_recipient else 1] += 1
    rows = [("Con remitente y con destinatario", pairs[(True, True)]),
            ("Con remitente, **sin destinatario**", pairs[(True, False)]),
            ("**Sin remitente**, con destinatario", pairs[(False, True)]),
            ("Sin remitente y sin destinatario", pairs[(False, False)])]
    lines = ["### Cruce remitente / destinatario", "", "| Situación | Fichas |", "| --- | --- |"]
    lines += [f"| {label} | {count} |" for label, count in rows]
    lines += ["", f"Remitente «Anónimo» (se migra como un actor con ese nombre, tal como lo escribió el Archivo): "
              f"{sum(anonymous)} fichas ({anonymous[0]} con destinatario y {anonymous[1]} sin él). Están contadas arriba como «con remitente».", ""]
    return lines


def empty_fields_section(findings):
    """Sección 5 del informe: campos que el catálogo trae vacíos o con «sin información» (se migran vacíos, no se inventa nada)."""
    counts = {}
    for f in findings:
        for prefix, column in (("vacio_", 0), ("sin_informacion_", 1)):
            if f["category"].startswith(prefix):
                counts.setdefault(f["category"][len(prefix):], [0, 0])[column] += 1
    rows = sorted(((field, empty, filler) for field, (empty, filler) in counts.items()), key=lambda r: -(r[1] + r[2]))
    lines = ["## 5. Campos vacíos en el catálogo (informativo)", "",
             "Estas fichas no tienen el dato en el catálogo del Archivo; se migran con el campo vacío y no se inventa nada. "
             "Si el Archivo puede completarlos, se cargan en la próxima entrega. El detalle ficha por ficha está en "
             "hallazgos.csv (categorías `vacio_*` y `sin_informacion_*`, nivel `info`).", "",
             "| Campo | Vacío | «Sin información» | Total de fichas |", "| --- | --- | --- | --- |"]
    for field, empty, filler in rows:
        lines.append(f"| {_FIELD_LABELS.get(field, field)} | {empty} | {filler} | {empty + filler} |")
    return lines + [""]


def report(records, findings, declared, quarantine, rows, previous_changes, sources=()):
    ok = sum(1 for item in declared if item[3] == "ok")
    lines = ["# Verificación del catálogo de Independencia", ""]
    lines += ["## 1. Resumen", "",
              *[f"- Entrada `{name}`: SHA-256 `{digest[:16]}`…" for name, digest in sources],
              f"- Fichas leídas: {len(records)}.",
              f"- Declaraciones del informe comprobadas: {len(declared)}; se cumplen {ok}; por revisar {len(declared) - ok}.",
              f"- Descripciones en el CSV principal: {sum(1 for r in rows if r['legacyId'].startswith('IND-D-'))}; "
              f"en cuarentena (signatura repetida): {len(quarantine)}.", ""]
    lines += ["## 2. Declaraciones del informe que NO se cumplen en el catálogo", ""]
    pending = [item for item in declared if item[3] != "ok"]
    lines += [f"- [{s}] signatura {n}: «{d[:90]}» → {detail}" for s, n, d, _, detail in pending] or ["- Ninguna."]
    lines.append("")
    if previous_changes:
        added, removed = previous_changes
        lines += ["## 3. Cambios entre el catálogo anterior y el actual", "",
                  "Solo se listan las signaturas que el informe NO cita: son cambios que el Archivo no declaró.", "",
                  f"- Signaturas nuevas ({len(added)}): " + (", ".join(added) or "ninguna"),
                  f"- Signaturas que desaparecieron ({len(removed)}): " + (", ".join(removed) or "ninguna"), ""]
    lines += ["## 4. Anomalías residuales (nivel «revisar»)", "", "| Categoría | Fichas |", "| --- | --- |"]
    counts = collections.Counter(f["category"] for f in findings if f["level"] == "revisar")
    for category, count in counts.most_common():
        lines.append(f"| {category} | {count} |")
    lines += ["", "Ejemplos (los 8 primeros de cada categoría; el detalle completo está en hallazgos.csv):", ""]
    for category in counts:
        lines.append(f"**{category}**")
        for f in [x for x in findings if x["category"] == category][:8]:
            lines.append(f"- ficha {f['seq']}, {f['signature'][:48]}: {f['detail'][:110]}")
        lines.append("")
    lines += empty_fields_section(findings)
    lines += sender_recipient_section(records)
    return "\n".join(lines) + "\n"


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--catalog", required=True)
    parser.add_argument("--informe")
    parser.add_argument("--previous")
    parser.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    records, findings = load(args.catalog)
    declared = informe.verify(records, args.informe) if args.informe else []
    previous_changes = None
    if args.previous:
        previous_records, _ = load(args.previous)
        previous_changes = changes_against(
            previous_records, records, informe.mentioned_numbers(args.informe) if args.informe else set()
        )

    sources = [(Path(p).name, hashlib.sha256(Path(p).read_bytes()).hexdigest()) for p in (args.catalog, args.informe) if p]
    rows, quarantine = csv_adapter.build(records)
    csv_adapter.write_csv(out / "atom-independencia.csv", rows)
    csv_adapter.write_csv(out / "atom-independencia-cuarentena.csv", quarantine)
    with open(out / "canonical.jsonl", "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    with open(out / "hallazgos.csv", "w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["seq", "signature", "category", "level", "detail"], lineterminator="\n")
        writer.writeheader()
        writer.writerows(findings)
    (out / "verificacion.md").write_text(report(records, findings, declared, quarantine, rows, previous_changes, sources), encoding="utf-8")
    print(f"fichas={len(records)} filas_csv={len(rows)} cuarentena={len(quarantine)} "
          f"declaraciones_ok={sum(1 for d in declared if d[3] == 'ok')}/{len(declared)} hallazgos_revisar="
          f"{sum(1 for f in findings if f['level'] == 'revisar')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
