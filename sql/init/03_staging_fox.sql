-- Tablas de *staging* para importar desde Fox (CSV, export DBF, etc.).
-- Sin FK a tablas definitivas: se validan y luego se pasan a alumnos/cuota_mensual con script.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staging_fox_clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo INT NULL,
  razon VARCHAR(200) NULL,
  orden VARCHAR(30) NULL,
  ndoc VARCHAR(20) NULL,
  nrocuit VARCHAR(20) NULL,
  observ VARCHAR(255) NULL,
  codbarrio SMALLINT NULL,
  barrio VARCHAR(80) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_st_cli_proc (procesado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_fox_pagos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_cliente_legacy INT NULL,
  ficha INT NULL,
  ncuenta INT NULL,
  anio SMALLINT NULL,
  mes TINYINT NULL,
  saldo DECIMAL(12,2) NULL,
  s_cuota DECIMAL(12,2) NULL,
  debe DECIMAL(12,2) NULL,
  haber DECIMAL(12,2) NULL,
  fecha DATE NULL,
  pago CHAR(1) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_st_pag_proc (procesado),
  KEY idx_st_pag_cli (codigo_cliente_legacy, anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_fox_abonclie (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codclie INT NULL,
  cod_artic BIGINT NULL,
  detartic VARCHAR(200) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  error_msg VARCHAR(255) NULL,
  KEY idx_st_abo_proc (procesado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
