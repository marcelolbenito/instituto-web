-- Contacto alumno: email y teléfono WhatsApp — compatible MySQL/MariaDB antiguos.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos' AND COLUMN_NAME = 'email'
    ),
    'SELECT ''email ya existe'' AS info',
    'ALTER TABLE alumnos ADD COLUMN email VARCHAR(120) NULL COMMENT ''Email para notificaciones'' AFTER documento'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos' AND COLUMN_NAME = 'telefono_whatsapp'
    ),
    'SELECT ''telefono_whatsapp ya existe'' AS info',
    'ALTER TABLE alumnos ADD COLUMN telefono_whatsapp VARCHAR(40) NULL COMMENT ''Teléfono con WhatsApp'' AFTER email'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
