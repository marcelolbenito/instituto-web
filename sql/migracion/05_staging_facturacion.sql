-- Staging para migrar circuito FACTURAS/FDETALLE/CAJA/FACDOS desde Fox (DBF -> CSV).
-- No usa FKs a tablas definitivas para permitir cargas parciales y depuracion.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staging_fox_facturas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_legacy BIGINT NULL,
  codigo_cliente_legacy INT NULL,
  ncuenta INT NULL,
  ficha INT NULL,
  tipo_cbte VARCHAR(20) NULL COMMENT 'FACTURA|NOTA_CREDITO|RECIBO|...',
  letra CHAR(1) NULL COMMENT 'A|B|C|X',
  tcomp SMALLINT NULL,
  punto_venta SMALLINT NULL,
  sucu INT NULL COMMENT 'Sucursal del comprobante (FA_SUCU / SUCU)',
  numero INT NULL,
  fecha_emision DATETIME NULL,
  fecha_vencimiento DATE NULL,
  moneda CHAR(3) NULL,
  cotizacion DECIMAL(12,6) NULL,
  importe_neto DECIMAL(14,2) NULL,
  importe_iva DECIMAL(14,2) NULL,
  importe_exento DECIMAL(14,2) NULL,
  importe_bonificacion DECIMAL(14,2) NULL,
  importe_recargo DECIMAL(14,2) NULL,
  importe_total DECIMAL(14,2) NULL,
  estado VARCHAR(20) NULL COMMENT 'emitido|anulado|borrador',
  observaciones VARCHAR(255) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_sff_proc (procesado),
  KEY idx_sff_cli (codigo_cliente_legacy),
  KEY idx_sff_nro (tipo_cbte, punto_venta, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_fox_fdetalle (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  factura_id_legacy BIGINT NULL,
  tcomp SMALLINT NULL,
  letra SMALLINT NULL,
  sucu INT NULL,
  nrofac INT NULL,
  ficha INT NULL,
  ncuenta INT NULL,
  orden SMALLINT NULL,
  cod_artic BIGINT NULL,
  descripcion VARCHAR(255) NULL,
  cantidad DECIMAL(12,3) NULL,
  precio_unitario DECIMAL(14,4) NULL,
  bonificacion DECIMAL(14,2) NULL,
  recargo DECIMAL(14,2) NULL,
  alicuota_iva DECIMAL(5,2) NULL,
  importe_neto DECIMAL(14,2) NULL,
  importe_iva DECIMAL(14,2) NULL,
  importe_total DECIMAL(14,2) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_sfd_proc (procesado),
  KEY idx_sfd_fact (factura_id_legacy, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_fox_caja (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_legacy BIGINT NULL,
  factura_id_legacy BIGINT NULL,
  codigo_cliente_legacy INT NULL,
  ncuenta INT NULL,
  tcomp SMALLINT NULL,
  letra SMALLINT NULL,
  succom INT NULL,
  nrocom INT NULL,
  fecha_hora DATETIME NULL,
  tipo VARCHAR(10) NULL COMMENT 'ingreso|egreso',
  medio VARCHAR(20) NULL COMMENT 'efectivo|transferencia|tarjeta|cheque|otro',
  referencia VARCHAR(100) NULL,
  importe DECIMAL(14,2) NULL,
  observaciones VARCHAR(255) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_sfc_proc (procesado),
  KEY idx_sfc_fact (factura_id_legacy),
  KEY idx_sfc_cli (codigo_cliente_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_fox_facdos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  factura_id_legacy BIGINT NULL,
  cae VARCHAR(20) NULL,
  fvcae DATE NULL,
  nroelec BIGINT NULL,
  resultado VARCHAR(30) NULL,
  cod_resultado VARCHAR(30) NULL,
  mensaje_error VARCHAR(500) NULL,
  request_json JSON NULL,
  response_json JSON NULL,
  fecha_evento DATETIME NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_sffd_proc (procesado),
  KEY idx_sffd_fact (factura_id_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
