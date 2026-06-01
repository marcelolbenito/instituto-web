-- Tabla de staging para importación del Excel de alumnos regulares (operación / producción).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staging_excel_regulares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dni VARCHAR(20) NOT NULL,
  apellido VARCHAR(120) NULL,
  nombre VARCHAR(120) NULL,
  carrera VARCHAR(120) NULL,
  mes_abonado_raw VARCHAR(40) NULL,
  mes_abonado_anio SMALLINT UNSIGNED NULL,
  mes_abonado_mes TINYINT UNSIGNED NULL,
  alumno_id INT UNSIGNED NULL,
  importe_cuota DECIMAL(12,2) NULL,
  error_msg VARCHAR(255) NULL,
  procesado TINYINT(1) NOT NULL DEFAULT 0,
  procesado_en TIMESTAMP NULL,
  KEY idx_ser_dni (dni),
  KEY idx_ser_proc (procesado),
  KEY idx_ser_alumno (alumno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
