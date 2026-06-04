-- Instituto: esquema inicial (MySQL/MariaDB 10.5+)
-- Mapeo conceptual desde Fox: CLIENTES->alumnos, PAGOS/cuotas->cuota_mensual, ABONCLIE->alumno_articulo, PORCEN->parametros_cobranza

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS barrios (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_legacy SMALLINT NULL,
  nombre VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_barrios_legacy (codigo_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alumnos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_legacy INT UNSIGNED NULL COMMENT 'CLIENTES.CODIGO en Fox',
  nombre_completo VARCHAR(120) NOT NULL,
  documento VARCHAR(20) NULL,
  curso VARCHAR(120) NULL COMMENT 'Texto libre (ex CLIENTES.OBSERV / ALU.CURSO)',
  barrio_id SMALLINT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alumnos_legacy (codigo_legacy),
  KEY idx_alumnos_activo (activo),
  CONSTRAINT fk_alumnos_barrio FOREIGN KEY (barrio_id) REFERENCES barrios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articulos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_legacy BIGINT NULL COMMENT 'ARTICULO en Fox',
  detalle VARCHAR(200) NOT NULL,
  importe_referencia DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_articulos_legacy (codigo_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué concepto mensual aplica a cada alumno (ex ABONCLIE)
CREATE TABLE IF NOT EXISTS alumno_articulo (
  alumno_id INT UNSIGNED NOT NULL,
  articulo_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (alumno_id, articulo_id),
  CONSTRAINT fk_aa_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE,
  CONSTRAINT fk_aa_art FOREIGN KEY (articulo_id) REFERENCES articulos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parámetros globales de cobranza (ex PORCEN: un registro activo)
CREATE TABLE IF NOT EXISTS parametros_cobranza (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  dia_generacion_cuota TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Ej.: 1 = primer día del mes',
  dia_tope_pronto_pago TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Ex PORCEN.DIA: hasta este día del mes bonificación',
  recargo_coeficiente DECIMAL(12,5) NOT NULL DEFAULT 0 COMMENT 'Base tipo Fox: RECARGO/30 por día de mora (ajustar según regla final)',
  bonificacion_pronto_pago DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Ex PORCEN.BONI1',
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obligación mensual por alumno (ex líneas PAGOS / cupón)
CREATE TABLE IF NOT EXISTS cuota_mensual (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT UNSIGNED NOT NULL,
  anio SMALLINT UNSIGNED NOT NULL,
  mes TINYINT UNSIGNED NOT NULL,
  importe_original DECIMAL(12,2) NOT NULL,
  saldo DECIMAL(12,2) NOT NULL,
  fecha_vencimiento DATE NULL,
  estado ENUM('pendiente','pagada','parcial','anulada') NOT NULL DEFAULT 'pendiente',
  nota VARCHAR(255) NULL,
  UNIQUE KEY uq_cuota_periodo (alumno_id, anio, mes),
  KEY idx_cuota_estado (estado),
  CONSTRAINT fk_cuota_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pago_registrado (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT UNSIGNED NOT NULL,
  fecha_pago DATE NOT NULL,
  importe DECIMAL(12,2) NOT NULL,
  medio VARCHAR(40) NULL,
  referencia VARCHAR(64) NULL,
  nota VARCHAR(255) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pago_alumno_fecha (alumno_id, fecha_pago),
  CONSTRAINT fk_pago_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pago_aplica_cuota (
  pago_id BIGINT UNSIGNED NOT NULL,
  cuota_id BIGINT UNSIGNED NOT NULL,
  importe_aplicado DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (pago_id, cuota_id),
  CONSTRAINT fk_pac_pago FOREIGN KEY (pago_id) REFERENCES pago_registrado (id) ON DELETE CASCADE,
  CONSTRAINT fk_pac_cuota FOREIGN KEY (cuota_id) REFERENCES cuota_mensual (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios del panel (autenticación futura)
CREATE TABLE IF NOT EXISTS usuarios (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  hash_password VARCHAR(255) NOT NULL,
  rol ENUM('admin','secretaria','consulta','alumno') NOT NULL DEFAULT 'consulta',
  alumno_id INT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_login (login),
  KEY idx_usuarios_alumno (alumno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
