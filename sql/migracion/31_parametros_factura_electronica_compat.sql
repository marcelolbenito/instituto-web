-- Parámetros de facturación electrónica (Gesis / AFIP) — fila única id=1.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS parametros_factura_electronica (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  gesis_url VARCHAR(200) NOT NULL DEFAULT 'https://servicios.gesis2.com',
  gesis_email VARCHAR(120) NOT NULL DEFAULT '',
  gesis_password VARCHAR(255) NOT NULL DEFAULT '',
  cuit_emisor VARCHAR(13) NULL COMMENT 'CUIT del instituto (referencia; emisión vía usuario Gesis)',
  punto_venta SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  cbte_tipo SMALLINT UNSIGNED NOT NULL DEFAULT 11 COMMENT 'AFIP: 11=Factura C, 6=B, 1=A',
  concepto TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=productos, 2=servicios, 3=ambos',
  production TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=homologación, 1=producción AFIP',
  observaciones VARCHAR(255) NULL,
  actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO parametros_factura_electronica (id) VALUES (1);
