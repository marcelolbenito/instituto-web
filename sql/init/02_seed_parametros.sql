SET NAMES utf8mb4;

INSERT INTO parametros_cobranza (id, dia_generacion_cuota, dia_tope_pronto_pago, recargo_coeficiente, bonificacion_pronto_pago)
VALUES (1, 1, 5, 0.00000, 0.00)
ON DUPLICATE KEY UPDATE
  dia_generacion_cuota = VALUES(dia_generacion_cuota),
  dia_tope_pronto_pago = VALUES(dia_tope_pronto_pago);
