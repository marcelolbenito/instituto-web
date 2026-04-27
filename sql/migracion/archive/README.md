# Archivo técnico de migración

Esta carpeta guarda scripts y documentos históricos/de transición.

## Criterio

- No se eliminan de inmediato porque pueden aportar contexto en reinstalaciones.
- No deben usarse como runbook principal sin validar vigencia.

## Contenido archivado

- `03b_patch_staging_columns.sql`: parche one-off para columnas faltantes en staging.
- `leeme.txt`: guía temprana de migración DBF->CSV->staging (reemplazada por runbooks actuales).
- `matriz_fox_sql_facturacion.md`: matriz de diseño inicial para facturación/electrónica.

## Runbook vigente recomendado

- Maestros y vínculos: `docs/tecnicos/migracion_articulos_fox_a_instituto.md`
- Pagos históricos y cuenta corriente: `docs/tecnicos/migracion_pagos_historico_y_cuenta_corriente.md`
- Vista de resumen: `docs/tecnicos/mapa_migracion_fox.md`
