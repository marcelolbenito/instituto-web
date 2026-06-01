-- Elimina pagos y cuotas migrados desde Fox (PAGOS / staging_fox_pagos).
-- NO toca cobros hechos en la app (medio distinto de legacy, nota distinta).
--
-- OBLIGATORIO antes de producción: backup completo de la base.
--   docker exec instituto-db mariadb-dump -u root -p... instituto > backup_antes_purge.sql
--
-- Post-ejecución: importar Excel con tools/importar_regulares_excel.py
-- y recalcular saldos (tools/recalcular_saldos_cli.php).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- Conteo previo (visible en cliente SQL)
SELECT 'cuota_mensual fox' AS concepto, COUNT(*) AS filas
FROM cuota_mensual WHERE nota LIKE 'Migrado desde PAGOS%';

SELECT 'pago_registrado fox' AS concepto, COUNT(*) AS filas
FROM pago_registrado
WHERE medio = 'legacy' OR nota = 'Migrado desde PAGOS';

-- 1) Aplicaciones ligadas a pagos Fox
DELETE pac
FROM pago_aplica_cuota pac
INNER JOIN pago_registrado pr ON pr.id = pac.pago_id
WHERE pr.medio = 'legacy' OR pr.nota = 'Migrado desde PAGOS';

-- 2) Aplicaciones ligadas a cuotas Fox (por si quedaron huérfanas)
DELETE pac
FROM pago_aplica_cuota pac
INNER JOIN cuota_mensual cm ON cm.id = pac.cuota_id
WHERE cm.nota LIKE 'Migrado desde PAGOS%';

-- 3) Pagos Fox
DELETE FROM pago_registrado
WHERE medio = 'legacy' OR nota = 'Migrado desde PAGOS';

-- 4) Cuotas Fox
DELETE FROM cuota_mensual
WHERE nota LIKE 'Migrado desde PAGOS%';

-- Opcional: limpiar staging Fox (no afecta operación, libera espacio)
-- TRUNCATE TABLE staging_fox_pagos;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'cuota_mensual restantes' AS concepto, COUNT(*) AS filas FROM cuota_mensual;
SELECT 'pago_registrado restantes' AS concepto, COUNT(*) AS filas FROM pago_registrado;
