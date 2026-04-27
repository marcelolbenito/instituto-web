-- Vista de cuenta corriente por alumno y período (año/mes)
-- Requiere datos en cuota_mensual y opcionalmente en pago_aplica_cuota.

SET NAMES utf8mb4;

DROP VIEW IF EXISTS vw_cuenta_corriente_alumno;

CREATE VIEW vw_cuenta_corriente_alumno AS
SELECT
  cm.alumno_id,
  a.codigo_legacy AS codigo_alumno_legacy,
  a.nombre_completo,
  cm.id AS cuota_id,
  cm.anio,
  cm.mes,
  cm.importe_original,
  COALESCE(SUM(pac.importe_aplicado), 0.00) AS pagado_periodo,
  cm.saldo AS saldo_periodo,
  cm.estado
FROM cuota_mensual cm
JOIN alumnos a ON a.id = cm.alumno_id
LEFT JOIN pago_aplica_cuota pac ON pac.cuota_id = cm.id
GROUP BY
  cm.alumno_id,
  a.codigo_legacy,
  a.nombre_completo,
  cm.id,
  cm.anio,
  cm.mes,
  cm.importe_original,
  cm.saldo,
  cm.estado;

-- Ejemplos de uso:
-- SELECT * FROM vw_cuenta_corriente_alumno WHERE alumno_id = 123 ORDER BY anio, mes;
-- SELECT alumno_id, nombre_completo, SUM(saldo_periodo) AS saldo_total
-- FROM vw_cuenta_corriente_alumno
-- GROUP BY alumno_id, nombre_completo
-- ORDER BY saldo_total DESC;
