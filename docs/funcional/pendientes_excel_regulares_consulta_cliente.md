# Pendientes Excel regulares — consulta con cliente

Fecha de corte: 27/05/2026  
Fuente: `ALUMNOS REGULARES (MARCELO) (1).xlsx`  
Estado import: **242 activos OK** · **5 pendientes**

Usar esta lista en reunión con administración para confirmar DNI, alta en sistema y asignación de conceptos.

---

## 1) No están en la base (alta pendiente)

| DNI (Excel) | Apellido | Nombre | Mes abonado (Excel) | Carrera |
|-------------|----------|--------|---------------------|---------|
| 46524163 | MALDONADO | FEDERICO | BECA | SOFT 2° A |
| 46154070 | MURA | ELIAS | BECA | SOFT 2° B |

**Preguntas para el cliente:** ¿Son alumnos nuevos? ¿DNI correcto? ¿Van como beca sin cuota automática?

---

## 2) Están en la base pero sin conceptos asignados

Sin artículos en `alumno_articulo` → no se puede generar importe de cuota.

| DNI | Apellido | Nombre | Mes abonado (Excel) | Carrera | Id en BD |
|-----|----------|--------|---------------------|---------|----------|
| 46251700 | RECALDE | RENZO FANTINO | 2026-05 | SOFT 3º B | 4973 |
| 44924133 | CARDOZO | TIAGO BENJAMIN | MATRICULA | IA | 4932 |
| 42051499 | Roque Araujo | Agostina Julieta | 2026-05 | PROF. LENG | 4982 |

**Preguntas para el cliente:** ¿Qué artículo/concepto de cuota corresponde a cada uno? ¿CARDOZO es solo matrícula?

---

## 3) DNI corregidos en import (ya resueltos — referencia)

Estos figuraban mal en el Excel y se importaron con el DNI de la ficha:

| DNI Excel (erróneo) | DNI en sistema | Alumno |
|---------------------|----------------|--------|
| 42634242 | 42634424 | DUARTE, LOURDES MICAELA |
| 46155010 | 40155010 | JOJOT, FRANCO JOSE |
| 37588607 | 37588637 | BARBIERI, CARLA ESTEFANIA |
| 44924357 | 44924375 | BLASICH, SEBASTIAN DAVID |
| 40211309 | 40211038 | MARTINEZ, MARISEL DE LOS A |
| 45899843 | 46899843 | SOSA, PATRICIO JAVIER |

---

## Después de la reunión

```bash
python tools/importar_regulares_excel.py --solo-faltantes --corregir-dni-conocidos
php tools/recalcular_saldos_cli.php
```

Guía completa: `docs/tecnicos/importar_regulares_desde_excel.md`
