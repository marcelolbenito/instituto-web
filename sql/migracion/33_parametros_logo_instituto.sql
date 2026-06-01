-- Logo del instituto para impresiones (recibo, factura FE, cierre de caja, etc.).

SET NAMES utf8mb4;

ALTER TABLE parametros_factura_electronica
  ADD COLUMN IF NOT EXISTS logo_path VARCHAR(200) NULL COMMENT 'Ruta relativa bajo public/ (ej. uploads/instituto/logo.png)' AFTER observaciones;
