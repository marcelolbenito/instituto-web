#!/usr/bin/env python3
import subprocess
import openpyxl
from datetime import datetime

def q(sql: str) -> str:
    return subprocess.check_output(
        [
            "docker", "exec", "instituto-db", "mariadb",
            "-uinstituto", "-pinstituto", "instituto", "-N", "-e", sql,
        ],
        text=True,
        errors="replace",
    ).strip()

p = r"c:\SYSTABON\instituto-web\ALUMNOS REGULARES (MARCELO) (1).xlsx"
wb = openpyxl.load_workbook(p, read_only=True, data_only=True)
ws = wb["ALUMNOS-CUOTA"]
rows = []
for i, r in enumerate(ws.iter_rows(values_only=True)):
    if i < 3 or not isinstance(r[0], (int, float)):
        continue
    dni = str(int(r[3])) if isinstance(r[3], (int, float)) else str(r[3]).strip()
    rows.append((dni, str(r[1] or "").strip(), str(r[2] or "").strip()))

ok = err = 0
print("DNI\tApellido\tResultado")
for dni, ape, nom in rows:
    out = q(f"SELECT id, documento, nombre_completo, activo FROM alumnos WHERE documento='{dni}' LIMIT 1")
    if out:
        ok += 1
        parts = out.split("\t")
        aid, doc, nombre, activo = parts[0], parts[1], parts[2], parts[3]
        n_cuotas = q(
            f"SELECT COUNT(*) FROM cuota_mensual WHERE alumno_id={aid} AND anio=2026 AND nota LIKE 'Import Excel%'"
        )
        n_art = q(f"SELECT COUNT(*) FROM alumno_articulo aa JOIN articulos ar ON ar.id=aa.articulo_id AND ar.activo=1 WHERE aa.alumno_id={aid}")
        estado = f"OK id={aid} activo={activo} cuotas_excel={n_cuotas} articulos={n_art}"
        print(f"{dni}\t{ape}\t{estado}")
    else:
        err += 1
        ape_esc = ape.replace("'", "''")[:15]
        nom_esc = nom.split()[0].replace("'", "''")[:10] if nom else ""
        out2 = q(
            f"SELECT documento, nombre_completo FROM alumnos WHERE nombre_completo LIKE '%{ape_esc}%' "
            f"AND nombre_completo LIKE '%{nom_esc}%' LIMIT 1"
        )
        sug = f" | candidato: {out2}" if out2 else ""
        print(f"{dni}\t{ape}\tNO EN BD{sug}")

print(f"\nTotal filas: {len(rows)} | DNI en BD: {ok} | Sin BD: {err}")
