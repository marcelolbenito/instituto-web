-- ETL staging -> tablas definitivas para facturacion/electronica.
-- Idempotente a nivel de comprobantes por UNIQUE(id_legacy) y UNIQUE(tipo,pv,numero).
-- Ejecutar despues de importar CSV en tablas staging_fox_*.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- 0) Normalizaciones basicas en staging
UPDATE staging_fox_facturas
SET
  tipo_cbte = UPPER(TRIM(tipo_cbte)),
  letra = UPPER(TRIM(letra)),
  moneda = COALESCE(NULLIF(UPPER(TRIM(moneda)), ''), 'ARS'),
  estado = LOWER(TRIM(COALESCE(NULLIF(estado, ''), 'emitido')))
WHERE procesado = 0;

UPDATE staging_fox_caja
SET
  tipo = LOWER(TRIM(COALESCE(NULLIF(tipo, ''), 'ingreso'))),
  medio = LOWER(TRIM(COALESCE(NULLIF(medio, ''), 'efectivo')))
WHERE procesado = 0;

-- 1) Marcar errores de cabecera antes de insertar comprobantes
UPDATE staging_fox_facturas s
SET s.error_msg = 'Cliente inexistente en alumnos (codigo_legacy)'
WHERE s.procesado = 0
  AND s.error_msg IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM alumnos a WHERE a.codigo_legacy = s.codigo_cliente_legacy
  );

UPDATE staging_fox_facturas s
SET s.error_msg = 'Falta tipo/punto_venta/numero'
WHERE s.procesado = 0
  AND s.error_msg IS NULL
  AND (s.tipo_cbte IS NULL OR s.punto_venta IS NULL OR s.numero IS NULL);

-- 2) Alta de talonarios detectados en staging
INSERT INTO talonario (codigo, tipo, punto_venta, descripcion, activo)
SELECT DISTINCT
  CONCAT(
    CASE
      WHEN s.tipo_cbte = 'FACTURA' AND COALESCE(s.letra, 'B') = 'A' THEN 'FAC_A'
      WHEN s.tipo_cbte = 'FACTURA' THEN 'FAC_B'
      WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') AND COALESCE(s.letra, 'B') = 'A' THEN 'NC_A'
      WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') THEN 'NC_B'
      WHEN s.tipo_cbte = 'RECIBO' THEN 'RECIBO'
      ELSE 'OTRO'
    END,
    '-',
    LPAD(s.punto_venta, 4, '0')
  ) AS codigo,
  CASE
    WHEN s.tipo_cbte = 'FACTURA' AND COALESCE(s.letra, 'B') = 'A' THEN 'FAC_A'
    WHEN s.tipo_cbte = 'FACTURA' THEN 'FAC_B'
    WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') AND COALESCE(s.letra, 'B') = 'A' THEN 'NC_A'
    WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') THEN 'NC_B'
    WHEN s.tipo_cbte = 'RECIBO' THEN 'RECIBO'
    ELSE 'OTRO'
  END AS tipo,
  s.punto_venta,
  'Generado por ETL desde staging_fox_facturas',
  1
FROM staging_fox_facturas s
WHERE s.procesado = 0
  AND s.error_msg IS NULL
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), activo = 1;

-- 3) Insertar comprobantes
INSERT INTO comprobante (
  id_legacy, origen, alumno_id, talonario_id, tipo, letra, punto_venta, numero,
  fecha_emision, fecha_vencimiento, moneda, cotizacion, importe_neto, importe_iva,
  importe_exento, importe_bonificacion, importe_recargo, importe_total, estado, observaciones
)
SELECT
  s.id_legacy,
  'fox' AS origen,
  a.id AS alumno_id,
  t.id AS talonario_id,
  CASE
    WHEN s.tipo_cbte = 'FACTURA' THEN 'FACTURA'
    WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') THEN 'NOTA_CREDITO'
    WHEN s.tipo_cbte = 'RECIBO' THEN 'RECIBO'
    WHEN s.tipo_cbte = 'PROFORMA' THEN 'PROFORMA'
    ELSE 'AJUSTE'
  END AS tipo,
  CASE WHEN s.letra IN ('A', 'B', 'C', 'X') THEN s.letra ELSE 'B' END AS letra,
  s.punto_venta,
  s.numero,
  COALESCE(s.fecha_emision, NOW()) AS fecha_emision,
  s.fecha_vencimiento,
  s.moneda,
  COALESCE(s.cotizacion, 1.000000),
  COALESCE(s.importe_neto, 0.00),
  COALESCE(s.importe_iva, 0.00),
  COALESCE(s.importe_exento, 0.00),
  COALESCE(s.importe_bonificacion, 0.00),
  COALESCE(s.importe_recargo, 0.00),
  COALESCE(s.importe_total, 0.00),
  CASE
    WHEN s.estado IN ('borrador', 'emitido', 'anulado') THEN s.estado
    ELSE 'emitido'
  END AS estado,
  s.observaciones
FROM staging_fox_facturas s
JOIN alumnos a
  ON a.codigo_legacy = COALESCE(s.codigo_cliente_legacy, s.ficha, s.ncuenta)
JOIN talonario t
  ON t.punto_venta = s.punto_venta
 AND t.tipo = CASE
   WHEN s.tipo_cbte = 'FACTURA' AND COALESCE(s.letra, 'B') = 'A' THEN 'FAC_A'
   WHEN s.tipo_cbte = 'FACTURA' THEN 'FAC_B'
   WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') AND COALESCE(s.letra, 'B') = 'A' THEN 'NC_A'
   WHEN s.tipo_cbte IN ('NOTA_CREDITO', 'NC') THEN 'NC_B'
   WHEN s.tipo_cbte = 'RECIBO' THEN 'RECIBO'
   ELSE 'OTRO'
 END
WHERE s.procesado = 0
  AND s.error_msg IS NULL
ON DUPLICATE KEY UPDATE
  actualizado_en = CURRENT_TIMESTAMP,
  alumno_id = VALUES(alumno_id),
  talonario_id = VALUES(talonario_id),
  fecha_emision = VALUES(fecha_emision),
  fecha_vencimiento = VALUES(fecha_vencimiento),
  moneda = VALUES(moneda),
  cotizacion = VALUES(cotizacion),
  importe_neto = VALUES(importe_neto),
  importe_iva = VALUES(importe_iva),
  importe_exento = VALUES(importe_exento),
  importe_bonificacion = VALUES(importe_bonificacion),
  importe_recargo = VALUES(importe_recargo),
  importe_total = VALUES(importe_total),
  estado = VALUES(estado),
  observaciones = VALUES(observaciones);

-- 4) Insertar detalle
INSERT INTO comprobante_detalle (
  comprobante_id, orden, articulo_id, codigo_articulo_legacy, descripcion,
  cantidad, precio_unitario, bonificacion, recargo, alicuota_iva,
  importe_neto, importe_iva, importe_total
)
SELECT
  c.id AS comprobante_id,
  COALESCE(d.orden, 1) AS orden,
  ar.id AS articulo_id,
  d.cod_artic AS codigo_articulo_legacy,
  COALESCE(NULLIF(d.descripcion, ''), CONCAT('Item legacy ', d.cod_artic)) AS descripcion,
  COALESCE(d.cantidad, 1.000),
  COALESCE(d.precio_unitario, 0.0000),
  COALESCE(d.bonificacion, 0.00),
  COALESCE(d.recargo, 0.00),
  COALESCE(d.alicuota_iva, 0.00),
  COALESCE(d.importe_neto, 0.00),
  COALESCE(d.importe_iva, 0.00),
  COALESCE(d.importe_total, 0.00)
FROM staging_fox_fdetalle d
JOIN comprobante c
  ON (
      d.factura_id_legacy IS NOT NULL
      AND c.id_legacy = d.factura_id_legacy
     )
  OR (
      d.factura_id_legacy IS NULL
      AND d.sucu IS NOT NULL
      AND d.nrofac IS NOT NULL
      AND c.punto_venta = d.sucu
      AND c.numero = d.nrofac
     )
LEFT JOIN articulos ar ON ar.codigo_legacy = d.cod_artic
WHERE d.procesado = 0
  AND d.error_msg IS NULL;

-- Marcar filas de detalle que no encontraron cabecera
UPDATE staging_fox_fdetalle d
LEFT JOIN comprobante c
  ON (
      d.factura_id_legacy IS NOT NULL
      AND c.id_legacy = d.factura_id_legacy
     )
  OR (
      d.factura_id_legacy IS NULL
      AND d.sucu IS NOT NULL
      AND d.nrofac IS NOT NULL
      AND c.punto_venta = d.sucu
      AND c.numero = d.nrofac
     )
SET d.error_msg = 'No se encontro cabecera en comprobante (id_legacy)'
WHERE d.procesado = 0
  AND d.error_msg IS NULL
  AND c.id IS NULL;

-- 5) Insertar movimientos de caja
INSERT INTO caja_movimiento (
  id_legacy, comprobante_id, alumno_id, fecha_hora, tipo, medio, referencia, importe, observaciones
)
SELECT
  s.id_legacy,
  c.id AS comprobante_id,
  a.id AS alumno_id,
  COALESCE(s.fecha_hora, NOW()) AS fecha_hora,
  CASE WHEN s.tipo IN ('ingreso', 'egreso') THEN s.tipo ELSE 'ingreso' END AS tipo,
  CASE
    WHEN s.medio IN ('efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro') THEN s.medio
    ELSE 'efectivo'
  END AS medio,
  s.referencia,
  COALESCE(s.importe, 0.00),
  s.observaciones
FROM staging_fox_caja s
LEFT JOIN comprobante c
  ON (
      s.factura_id_legacy IS NOT NULL
      AND c.id_legacy = s.factura_id_legacy
     )
  OR (
      s.factura_id_legacy IS NULL
      AND s.succom IS NOT NULL
      AND s.nrocom IS NOT NULL
      AND c.punto_venta = s.succom
      AND c.numero = s.nrocom
     )
LEFT JOIN alumnos a ON a.codigo_legacy = COALESCE(s.codigo_cliente_legacy, s.ncuenta)
WHERE s.procesado = 0
  AND s.error_msg IS NULL;

-- 6) Insertar estado electronico / eventos desde FACDOS
INSERT INTO comprobante_electronico (
  comprobante_id, proveedor, estado, cae, cae_vencimiento, numero_electronico,
  codigo_resultado, mensaje_error, request_json, response_json, autorizado_en
)
SELECT
  c.id AS comprobante_id,
  'infodos_bridge' AS proveedor,
  CASE
    WHEN f.cae IS NOT NULL AND f.cae <> '' THEN 'autorizado'
    WHEN LOWER(COALESCE(f.resultado, '')) IN ('rechazado', 'reject') THEN 'rechazado'
    WHEN LOWER(COALESCE(f.resultado, '')) IN ('error', 'err') THEN 'error'
    ELSE 'pendiente'
  END AS estado,
  NULLIF(f.cae, ''),
  f.fvcae,
  f.nroelec,
  f.cod_resultado,
  f.mensaje_error,
  f.request_json,
  f.response_json,
  CASE WHEN f.cae IS NOT NULL AND f.cae <> '' THEN COALESCE(f.fecha_evento, NOW()) ELSE NULL END
FROM staging_fox_facdos f
JOIN comprobante c ON c.id_legacy = f.factura_id_legacy
WHERE f.procesado = 0
  AND f.error_msg IS NULL
ON DUPLICATE KEY UPDATE
  estado = VALUES(estado),
  cae = VALUES(cae),
  cae_vencimiento = VALUES(cae_vencimiento),
  numero_electronico = VALUES(numero_electronico),
  codigo_resultado = VALUES(codigo_resultado),
  mensaje_error = VALUES(mensaje_error),
  request_json = VALUES(request_json),
  response_json = VALUES(response_json),
  autorizado_en = VALUES(autorizado_en),
  actualizado_en = CURRENT_TIMESTAMP;

INSERT INTO comprobante_electronico_evento (
  comprobante_id, fecha_evento, tipo_evento, detalle, payload_json
)
SELECT
  c.id,
  COALESCE(f.fecha_evento, NOW()),
  CASE
    WHEN f.cae IS NOT NULL AND f.cae <> '' THEN 'respuesta'
    WHEN LOWER(COALESCE(f.resultado, '')) IN ('error', 'err') THEN 'error'
    ELSE 'solicitud'
  END AS tipo_evento,
  COALESCE(f.mensaje_error, f.resultado, 'Evento importado desde FACDOS'),
  f.response_json
FROM staging_fox_facdos f
JOIN comprobante c ON c.id_legacy = f.factura_id_legacy
WHERE f.procesado = 0
  AND f.error_msg IS NULL;

-- 7) Marcar como procesado
UPDATE staging_fox_facturas SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;
UPDATE staging_fox_fdetalle SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;
UPDATE staging_fox_caja SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;
UPDATE staging_fox_facdos SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
