-- Agrega columna persistida de saldo de cuenta corriente por alumno.
-- Ejecutar una vez por entorno.

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS saldo_cc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER activo;
