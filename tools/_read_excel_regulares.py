#!/usr/bin/env python3
"""Lectura rápida del Excel de alumnos regulares y cruce con BD."""
import subprocess
import sys
from collections import Counter
from datetime import datetime

try:
    import openpyxl
except ImportError:
    print("Falta openpyxl: pip install openpyxl")
    sys.exit(1)

XLSX = r"c:\SYSTABON\instituto-web\ALUMNOS REGULARES (MARCELO) (1).xlsx"


def load_excel():
    wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)
    ws = wb["ALUMNOS-CUOTA"]
    rows = []
    for i, r in enumerate(ws.iter_rows(values_only=True)):
        if i < 3 or not r or not isinstance(r[0], (int, float)):
            continue
        dni = r[3]
        if dni is None:
            continue
        dni_s = str(int(dni)) if isinstance(dni, (int, float)) else str(dni).strip()
        mes = r[5]
        if isinstance(mes, datetime):
            mes_s = mes.strftime("%Y-%m")
        elif mes:
            mes_s = str(mes).strip()
        else:
            mes_s = ""
        rows.append(
            {
                "ape": str(r[1] or "").strip(),
                "nom": str(r[2] or "").strip(),
                "dni": dni_s,
                "carrera": str(r[4] or "").strip(),
                "mes": mes_s,
            }
        )
    return rows


def load_bd():
    sql = (
        "SELECT id, documento, nombre_completo, activo, COALESCE(tipo_alumno,'regular') "
        "FROM alumnos WHERE documento IS NOT NULL AND TRIM(documento) <> ''"
    )
    out = subprocess.check_output(
        [
            "docker",
            "exec",
            "instituto-db",
            "mariadb",
            "-uinstituto",
            "-pinstituto",
            "instituto",
            "-N",
            "-e",
            sql,
        ],
        text=True,
        errors="replace",
    )
    bd = {}
    for line in out.strip().splitlines():
        parts = line.split("\t")
        if len(parts) < 5:
            continue
        doc = parts[1].strip()
        bd[doc] = {
            "id": parts[0],
            "nombre": parts[2],
            "activo": parts[3],
            "tipo": parts[4],
        }
    return bd


def main():
    rows = load_excel()
    bd = load_bd()
    dnis = {r["dni"] for r in rows}
    meses = Counter(r["mes"] for r in rows)

    print("=== Excel ALUMNOS REGULARES ===")
    print(f"Filas alumnos: {len(rows)}")
    print(f"DNI únicos: {len(dnis)}")
    print("Mes abonado:")
    for m, c in sorted(meses.items(), key=lambda x: (x[0] == "BECA", x[0] == "MATRICULA", x[0])):
        print(f"  {m}: {c}")

    match = [d for d in dnis if d in bd]
    solo_excel = [d for d in dnis if d not in bd]
    activos_bd = {d: v for d, v in bd.items() if v["activo"] == "1"}
    fuera_excel = [d for d in activos_bd if d not in dnis]

    print("\n=== Cruce con BD (por DNI) ===")
    print(f"Coinciden Excel ↔ BD: {len(match)}")
    print(f"Solo en Excel: {len(solo_excel)}")
    print(f"Activos en BD no están en Excel: {len(fuera_excel)}")

    if solo_excel:
        print("\nEjemplos solo en Excel:")
        for d in solo_excel[:5]:
            r = next(x for x in rows if x["dni"] == d)
            print(f"  DNI {d} — {r['ape']}, {r['nom']}")

    if fuera_excel:
        print("\nEjemplos activos en BD fuera del Excel:")
        for d in fuera_excel[:5]:
            print(f"  DNI {d} — {activos_bd[d]['nombre'][:50]} (tipo {activos_bd[d]['tipo']})")


if __name__ == "__main__":
    main()
