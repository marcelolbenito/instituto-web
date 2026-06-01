-- Permite agregar ítems de artículos en cobro/recibo y registrar contramovimiento en CC (debe).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pago_item_articulo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pago_id BIGINT UNSIGNED NOT NULL,
  articulo_id INT UNSIGNED NOT NULL,
  descripcion VARCHAR(200) NOT NULL,
  cantidad DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  importe_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  importe_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_pia_pago FOREIGN KEY (pago_id) REFERENCES pago_registrado (id) ON DELETE CASCADE,
  CONSTRAINT fk_pia_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_ajuste_debe (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT UNSIGNED NOT NULL,
  fecha_mov DATE NOT NULL,
  concepto VARCHAR(200) NOT NULL,
  debe DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  referencia VARCHAR(80) NULL,
  pago_id BIGINT UNSIGNED NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ccad_alumno_fecha (alumno_id, fecha_mov),
  CONSTRAINT fk_ccad_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos (id) ON DELETE CASCADE,
  CONSTRAINT fk_ccad_pago FOREIGN KEY (pago_id) REFERENCES pago_registrado (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
