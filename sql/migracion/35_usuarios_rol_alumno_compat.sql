-- Rol alumno + alumno_id — compatible MySQL/MariaDB antiguos (idempotente).

SET NAMES utf8mb4;

ALTER TABLE usuarios
  MODIFY COLUMN rol ENUM('admin', 'secretaria', 'consulta', 'alumno') NOT NULL DEFAULT 'secretaria';

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'alumno_id'
    ),
    'SELECT ''alumno_id ya existe'' AS info',
    'ALTER TABLE usuarios ADD COLUMN alumno_id INT UNSIGNED NULL COMMENT ''Ficha alumno para rol alumno'' AFTER rol, ADD KEY idx_usuarios_alumno (alumno_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos'
    )
    AND EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'alumno_id'
    )
    AND NOT EXISTS (
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_NAME = 'fk_usuarios_alumno'
    ),
    'ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL',
    'SELECT ''fk_usuarios_alumno omitido o ya existe'' AS info'
  )
);
PREPARE stmt FROM @fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;
