-- Caso controlado de prueba: BECA y pago fuera del 5to día hábil.
-- Uso:
-- 1) Ejecutar este script.
-- 2) Ir a registrar_cobro.php y buscar alumno por "PRUEBA BECA 5H".
-- 3) Probar con fecha <= 5to hábil (sin diferencia) y fecha > 5to hábil (con diferencia).
-- 4) Revisar consultas de verificación del final.
-- 5) Opcional: ejecutar sección de limpieza.

SET NAMES utf8mb4;

-- Parámetros del caso de prueba
SET @TEST_LEGACY := 990005;
SET @TEST_NOMBRE := 'PRUEBA BECA 5H';
SET @TEST_ANIO := 2026;
SET @TEST_MES := 6;

-- Resolver artículo BECA y artículo "abono completo" de referencia
SET @ART_BECA := (
  SELECT id
  FROM articulos
  WHERE activo = 1
    AND es_abono = 1
    AND UPPER(detalle) LIKE '%BECA%'
  ORDER BY importe_referencia DESC, id
  LIMIT 1
);

SET @ART_ABONO := (
  SELECT id
  FROM articulos
  WHERE activo = 1
    AND es_abono = 1
    AND importe_referencia > 0
    AND UPPER(detalle) NOT LIKE '%BECA%'
    AND UPPER(detalle) NOT LIKE '%DESCUENTO%'
  ORDER BY importe_referencia DESC, id
  LIMIT 1
);

SET @IMP_BECA := COALESCE((SELECT importe_referencia FROM articulos WHERE id = @ART_BECA), 0);
SET @IMP_ABONO := COALESCE((SELECT importe_referencia FROM articulos WHERE id = @ART_ABONO), 0);

-- Validaciones mínimas de precondición (si dan 0, no continuar)
SELECT
  @ART_BECA AS articulo_beca_id,
  @IMP_BECA AS importe_beca,
  @ART_ABONO AS articulo_abono_id,
  @IMP_ABONO AS importe_abono_completo;

-- Crear / actualizar alumno de prueba
INSERT INTO alumnos (
  codigo_legacy, nombre_completo, condicion_iva, estado_cuenta, activo, observaciones
)
VALUES (
  @TEST_LEGACY, @TEST_NOMBRE, 'consumidor_final', 'activo', 1, 'Caso controlado BECA 5to habil'
)
ON DUPLICATE KEY UPDATE
  nombre_completo = VALUES(nombre_completo),
  activo = 1,
  estado_cuenta = 'activo',
  observaciones = VALUES(observaciones);

SET @ALUMNO_ID := (
  SELECT id FROM alumnos WHERE codigo_legacy = @TEST_LEGACY LIMIT 1
);

-- Dejar asignado solo BECA para que la cuota se genere con valor becado
DELETE FROM alumno_articulo WHERE alumno_id = @ALUMNO_ID;
INSERT INTO alumno_articulo (alumno_id, articulo_id)
SELECT @ALUMNO_ID, @ART_BECA
WHERE @ART_BECA IS NOT NULL;

-- Crear o resetear cuota del período de prueba (importe becado)
INSERT INTO cuota_mensual (
  alumno_id, anio, mes, importe_original, saldo, fecha_vencimiento, estado, nota, importe_diferencia_beca
)
VALUES (
  @ALUMNO_ID, @TEST_ANIO, @TEST_MES, @IMP_BECA, @IMP_BECA,
  STR_TO_DATE(CONCAT(@TEST_ANIO, '-', LPAD(@TEST_MES, 2, '0'), '-30'), '%Y-%m-%d'),
  'pendiente', 'Caso prueba BECA 5H', 0.00
)
ON DUPLICATE KEY UPDATE
  importe_original = VALUES(importe_original),
  saldo = VALUES(saldo),
  estado = 'pendiente',
  nota = VALUES(nota),
  importe_diferencia_beca = 0.00;

SET @CUOTA_ID := (
  SELECT id
  FROM cuota_mensual
  WHERE alumno_id = @ALUMNO_ID
    AND anio = @TEST_ANIO
    AND mes = @TEST_MES
  LIMIT 1
);

-- Limpiar pagos previos del caso para re-ejecutar limpio
DELETE pac
FROM pago_aplica_cuota pac
JOIN pago_registrado pr ON pr.id = pac.pago_id
WHERE pr.alumno_id = @ALUMNO_ID
  AND pr.referencia LIKE CONCAT('COBRO:%:', @CUOTA_ID);

DELETE FROM pago_registrado
WHERE alumno_id = @ALUMNO_ID
  AND referencia LIKE CONCAT('COBRO:%:', @CUOTA_ID);

-- Revalidar estado de cuota tras limpieza
UPDATE cuota_mensual
SET saldo = importe_original, estado = 'pendiente', importe_diferencia_beca = 0.00
WHERE id = @CUOTA_ID;

-- Datos finales para abrir en UI
SELECT
  @ALUMNO_ID AS alumno_id,
  @CUOTA_ID AS cuota_id,
  @TEST_ANIO AS anio,
  @TEST_MES AS mes,
  @IMP_BECA AS importe_becado,
  @IMP_ABONO AS importe_abono_completo,
  GREATEST(0, ROUND(@IMP_ABONO - @IMP_BECA, 2)) AS diferencia_esperada;

-- Verificación (ejecutar luego del cobro en UI):
-- SELECT id, alumno_id, fecha_pago, importe, importe_capital, importe_interes, importe_beca_perdida, importe_descuento, referencia
-- FROM pago_registrado
-- WHERE alumno_id = @ALUMNO_ID
-- ORDER BY id DESC;
--
-- SELECT pac.pago_id, pac.cuota_id, pac.importe_aplicado, pac.importe_capital, pac.importe_recargo, pac.importe_beca_perdida, pac.importe_descuento
-- FROM pago_aplica_cuota pac
-- WHERE pac.cuota_id = @CUOTA_ID
-- ORDER BY pac.pago_id DESC;
--
-- SELECT id, alumno_id, anio, mes, importe_original, saldo, estado, importe_diferencia_beca
-- FROM cuota_mensual
-- WHERE id = @CUOTA_ID;

-- Limpieza opcional total (descomentar si querés borrar el caso):
-- DELETE pac FROM pago_aplica_cuota pac JOIN pago_registrado pr ON pr.id = pac.pago_id WHERE pr.alumno_id = @ALUMNO_ID;
-- DELETE FROM pago_registrado WHERE alumno_id = @ALUMNO_ID;
-- DELETE FROM cuota_mensual WHERE alumno_id = @ALUMNO_ID AND anio = @TEST_ANIO AND mes = @TEST_MES;
-- DELETE FROM alumno_articulo WHERE alumno_id = @ALUMNO_ID;
-- DELETE FROM alumnos WHERE id = @ALUMNO_ID;
