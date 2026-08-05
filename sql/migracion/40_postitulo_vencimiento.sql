-- Vencimiento de postítulo por período (anio/mes): fecha de vencimiento propia y variable.
-- Los alumnos de postítulo (artículos con detalle que empieza con 'POST.') no llevan
-- descuento de pronto pago y su vencimiento se carga manualmente cada mes.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS postitulo_vencimiento (
  anio SMALLINT UNSIGNED NOT NULL,
  mes TINYINT UNSIGNED NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
