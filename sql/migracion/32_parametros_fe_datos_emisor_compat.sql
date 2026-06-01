-- Datos del emisor para impresión ARCA/AFIP — compatible MySQL/MariaDB sin IF NOT EXISTS en columnas.
-- Ejecutar después de 31_parametros_factura_electronica_compat.sql

SET NAMES utf8mb4;

-- razon_social
SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'razon_social'
    ),
    'SELECT ''razon_social ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN razon_social VARCHAR(200) NOT NULL DEFAULT '''' COMMENT ''Denominación emisor'' AFTER cuit_emisor'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'nombre_fantasia'
    ),
    'SELECT ''nombre_fantasia ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN nombre_fantasia VARCHAR(120) NULL AFTER razon_social'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'domicilio_comercial'
    ),
    'SELECT ''domicilio_comercial ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN domicilio_comercial VARCHAR(200) NOT NULL DEFAULT '''' AFTER nombre_fantasia'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'localidad'
    ),
    'SELECT ''localidad ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN localidad VARCHAR(80) NULL AFTER domicilio_comercial'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'provincia'
    ),
    'SELECT ''provincia ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN provincia VARCHAR(60) NULL AFTER localidad'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'codigo_postal'
    ),
    'SELECT ''codigo_postal ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN codigo_postal VARCHAR(12) NULL AFTER provincia'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'telefono'
    ),
    'SELECT ''telefono ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN telefono VARCHAR(40) NULL AFTER codigo_postal'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'email_contacto'
    ),
    'SELECT ''email_contacto ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN email_contacto VARCHAR(120) NULL AFTER telefono'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'condicion_iva_emisor'
    ),
    'SELECT ''condicion_iva_emisor ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN condicion_iva_emisor ENUM(
      ''responsable_inscripto'', ''monotributo'', ''exento'', ''no_inscripto''
    ) NOT NULL DEFAULT ''monotributo'' AFTER email_contacto'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'inicio_actividades'
    ),
    'SELECT ''inicio_actividades ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN inicio_actividades DATE NULL COMMENT ''Inicio actividades AFIP'' AFTER condicion_iva_emisor'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'ingresos_brutos'
    ),
    'SELECT ''ingresos_brutos ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN ingresos_brutos VARCHAR(40) NULL AFTER inicio_actividades'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'jurisdiccion_iibb'
    ),
    'SELECT ''jurisdiccion_iibb ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN jurisdiccion_iibb VARCHAR(80) NULL AFTER ingresos_brutos'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'actividad_principal'
    ),
    'SELECT ''actividad_principal ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN actividad_principal VARCHAR(120) NULL AFTER jurisdiccion_iibb'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
