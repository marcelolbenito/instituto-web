-- Extiende el esquema actual con comprobantes y facturacion electronica.
-- Disenado para convivir con el legado Fox durante migracion.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS talonario (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL COMMENT 'Identificador interno del talonario',
  tipo ENUM('FAC_A','FAC_B','NC_A','NC_B','RECIBO','OTRO') NOT NULL,
  punto_venta SMALLINT UNSIGNED NOT NULL,
  descripcion VARCHAR(120) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_talonario_codigo (codigo),
  UNIQUE KEY uq_talonario_tipo_pv (tipo, punto_venta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talonario_ultimo_numero (
  talonario_id SMALLINT UNSIGNED PRIMARY KEY,
  ultimo_numero INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tun_talonario FOREIGN KEY (talonario_id) REFERENCES talonario (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprobante (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_legacy BIGINT NULL COMMENT 'Identificador original en Fox/DBF si existe',
  origen ENUM('fox','web') NOT NULL DEFAULT 'web',
  alumno_id INT UNSIGNED NOT NULL,
  talonario_id SMALLINT UNSIGNED NOT NULL,
  tipo ENUM('FACTURA','NOTA_CREDITO','RECIBO','PROFORMA','AJUSTE') NOT NULL,
  letra ENUM('A','B','C','X') NOT NULL DEFAULT 'B',
  punto_venta SMALLINT UNSIGNED NOT NULL,
  numero INT UNSIGNED NOT NULL,
  fecha_emision DATETIME NOT NULL,
  fecha_vencimiento DATE NULL,
  moneda CHAR(3) NOT NULL DEFAULT 'ARS',
  cotizacion DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
  importe_neto DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_exento DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_bonificacion DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_recargo DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_total DECIMAL(14,2) NOT NULL,
  estado ENUM('borrador','emitido','anulado') NOT NULL DEFAULT 'emitido',
  observaciones VARCHAR(255) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comp_tipo_pv_num (tipo, punto_venta, numero),
  UNIQUE KEY uq_comp_legacy (id_legacy),
  KEY idx_comp_alumno_fecha (alumno_id, fecha_emision),
  KEY idx_comp_estado (estado),
  CONSTRAINT fk_comp_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id),
  CONSTRAINT fk_comp_talonario FOREIGN KEY (talonario_id) REFERENCES talonario (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprobante_detalle (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comprobante_id BIGINT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  articulo_id INT UNSIGNED NULL,
  codigo_articulo_legacy BIGINT NULL,
  descripcion VARCHAR(255) NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  precio_unitario DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  bonificacion DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  recargo DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  alicuota_iva DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  importe_neto DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  importe_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  KEY idx_cd_comp_orden (comprobante_id, orden),
  KEY idx_cd_articulo (articulo_id),
  CONSTRAINT fk_cd_comp FOREIGN KEY (comprobante_id) REFERENCES comprobante (id) ON DELETE CASCADE,
  CONSTRAINT fk_cd_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprobante_relacion (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comprobante_id BIGINT UNSIGNED NOT NULL COMMENT 'Comprobante actual (por ejemplo NC)',
  comprobante_referenciado_id BIGINT UNSIGNED NOT NULL COMMENT 'Comprobante origen referenciado',
  tipo_relacion ENUM('anula_total','anula_parcial','referencia') NOT NULL DEFAULT 'referencia',
  importe_relacionado DECIMAL(14,2) NULL,
  UNIQUE KEY uq_comp_relacion (comprobante_id, comprobante_referenciado_id, tipo_relacion),
  CONSTRAINT fk_cr_comp FOREIGN KEY (comprobante_id) REFERENCES comprobante (id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_ref FOREIGN KEY (comprobante_referenciado_id) REFERENCES comprobante (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprobante_electronico (
  comprobante_id BIGINT UNSIGNED PRIMARY KEY,
  proveedor ENUM('afip_wsfe','infodos_bridge','otro') NOT NULL DEFAULT 'infodos_bridge',
  estado ENUM('pendiente','autorizado','rechazado','error') NOT NULL DEFAULT 'pendiente',
  cae VARCHAR(20) NULL,
  cae_vencimiento DATE NULL,
  numero_electronico BIGINT NULL,
  codigo_resultado VARCHAR(30) NULL,
  mensaje_error VARCHAR(500) NULL,
  request_json JSON NULL,
  response_json JSON NULL,
  autorizado_en DATETIME NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ce_comp FOREIGN KEY (comprobante_id) REFERENCES comprobante (id) ON DELETE CASCADE,
  CONSTRAINT chk_ce_cae_estado CHECK (
    (estado <> 'autorizado') OR (cae IS NOT NULL AND cae_vencimiento IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprobante_electronico_evento (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comprobante_id BIGINT UNSIGNED NOT NULL,
  fecha_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tipo_evento ENUM('solicitud','respuesta','error','retry') NOT NULL,
  detalle VARCHAR(500) NULL,
  payload_json JSON NULL,
  KEY idx_cee_comp_fecha (comprobante_id, fecha_evento),
  CONSTRAINT fk_cee_comp FOREIGN KEY (comprobante_id) REFERENCES comprobante (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caja_movimiento (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_legacy BIGINT NULL,
  comprobante_id BIGINT UNSIGNED NULL,
  alumno_id INT UNSIGNED NULL,
  fecha_hora DATETIME NOT NULL,
  tipo ENUM('ingreso','egreso') NOT NULL,
  medio ENUM('efectivo','transferencia','tarjeta','cheque','otro') NOT NULL DEFAULT 'efectivo',
  referencia VARCHAR(100) NULL,
  importe DECIMAL(14,2) NOT NULL,
  observaciones VARCHAR(255) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_caja_fecha (fecha_hora),
  KEY idx_caja_comp (comprobante_id),
  KEY idx_caja_alumno (alumno_id),
  CONSTRAINT fk_caja_comp FOREIGN KEY (comprobante_id) REFERENCES comprobante (id),
  CONSTRAINT fk_caja_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill opcional: crea estado electronico por defecto para comprobantes emitidos.
-- INSERT INTO comprobante_electronico (comprobante_id)
-- SELECT c.id FROM comprobante c
-- LEFT JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
-- WHERE c.estado = 'emitido' AND ce.comprobante_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
