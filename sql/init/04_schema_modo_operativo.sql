-- Extensión alineada con docs/funcional/modo_operativo_sysabon.md
-- Ficha cliente (campos faltantes) + rubros + ficha artículo (listas, tipo abono).
-- Se ejecuta después de 01_schema / 02_seed / 03_staging en init de Docker.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS rubros (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_legacy SMALLINT UNSIGNED NULL COMMENT 'RUBRO en Fox',
  nombre VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_rubros_legacy (codigo_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alumnos / clientes: datos de la ficha legacy (INGCLIE / modo operativo)
ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS direccion VARCHAR(200) NULL AFTER nombre_completo,
  ADD COLUMN IF NOT EXISTS condicion_iva ENUM(
    'inscripto','no_inscripto','exento','consumidor_final','monotributo'
  ) NOT NULL DEFAULT 'consumidor_final' AFTER documento,
  ADD COLUMN IF NOT EXISTS cuit VARCHAR(13) NULL COMMENT 'Solo dígitos o formato con guiones' AFTER condicion_iva,
  ADD COLUMN IF NOT EXISTS fecha_ingreso DATE NULL AFTER cuit,
  ADD COLUMN IF NOT EXISTS fecha_inactivacion DATE NULL COMMENT 'Baja o desconexión' AFTER fecha_ingreso,
  ADD COLUMN IF NOT EXISTS estado_cuenta ENUM('activo','desconectado','inactivo') NOT NULL DEFAULT 'activo' AFTER fecha_inactivacion,
  ADD COLUMN IF NOT EXISTS observaciones VARCHAR(500) NULL AFTER estado_cuenta,
  ADD COLUMN IF NOT EXISTS orden_referencia VARCHAR(12) NULL COMMENT 'Ex ORDEN Fox' AFTER observaciones,
  ADD COLUMN IF NOT EXISTS hace_factura TINYINT(1) NOT NULL DEFAULT 0 AFTER orden_referencia,
  ADD COLUMN IF NOT EXISTS saldo_cc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER activo;

-- CUIT único cuando está informado (varios NULL permitidos)
ALTER TABLE alumnos ADD UNIQUE KEY uq_alumnos_cuit (cuit);

ALTER TABLE articulos
  ADD COLUMN IF NOT EXISTS rubro_id SMALLINT UNSIGNED NULL AFTER codigo_legacy,
  ADD COLUMN IF NOT EXISTS es_abono TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=concepto cuota/abono' AFTER detalle,
  ADD COLUMN IF NOT EXISTS medida_venta ENUM('unidad','fraccion') NOT NULL DEFAULT 'unidad' AFTER es_abono,
  ADD COLUMN IF NOT EXISTS precio_lista_2 DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_referencia,
  ADD COLUMN IF NOT EXISTS precio_lista_3 DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_lista_2,
  ADD COLUMN IF NOT EXISTS precio_lista_4 DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_lista_3;

ALTER TABLE articulos
  ADD CONSTRAINT fk_articulos_rubro FOREIGN KEY (rubro_id) REFERENCES rubros (id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Rubro por defecto para artículos sin clasificar (opcional)
INSERT INTO rubros (nombre)
SELECT 'General' WHERE NOT EXISTS (SELECT 1 FROM rubros LIMIT 1);

SET FOREIGN_KEY_CHECKS = 1;
