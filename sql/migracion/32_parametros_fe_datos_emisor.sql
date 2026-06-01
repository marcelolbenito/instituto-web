-- Datos del emisor para impresión ARCA/AFIP (razón social, domicilio, IIBB, etc.).
-- Ejecutar después de 31_parametros_factura_electronica*.sql

SET NAMES utf8mb4;

ALTER TABLE parametros_factura_electronica
  ADD COLUMN IF NOT EXISTS razon_social VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Denominación / razón social emisor' AFTER cuit_emisor,
  ADD COLUMN IF NOT EXISTS nombre_fantasia VARCHAR(120) NULL COMMENT 'Nombre de fantasía (opcional)' AFTER razon_social,
  ADD COLUMN IF NOT EXISTS domicilio_comercial VARCHAR(200) NOT NULL DEFAULT '' AFTER nombre_fantasia,
  ADD COLUMN IF NOT EXISTS localidad VARCHAR(80) NULL AFTER domicilio_comercial,
  ADD COLUMN IF NOT EXISTS provincia VARCHAR(60) NULL AFTER localidad,
  ADD COLUMN IF NOT EXISTS codigo_postal VARCHAR(12) NULL AFTER provincia,
  ADD COLUMN IF NOT EXISTS telefono VARCHAR(40) NULL AFTER codigo_postal,
  ADD COLUMN IF NOT EXISTS email_contacto VARCHAR(120) NULL AFTER telefono,
  ADD COLUMN IF NOT EXISTS condicion_iva_emisor ENUM(
    'responsable_inscripto', 'monotributo', 'exento', 'no_inscripto'
  ) NOT NULL DEFAULT 'monotributo' AFTER email_contacto,
  ADD COLUMN IF NOT EXISTS inicio_actividades DATE NULL COMMENT 'Fecha inicio actividades AFIP' AFTER condicion_iva_emisor,
  ADD COLUMN IF NOT EXISTS ingresos_brutos VARCHAR(40) NULL COMMENT 'Nº inscripción ingresos brutos' AFTER inicio_actividades,
  ADD COLUMN IF NOT EXISTS jurisdiccion_iibb VARCHAR(80) NULL COMMENT 'Ej. Tucumán, Convenio Multilateral' AFTER ingresos_brutos,
  ADD COLUMN IF NOT EXISTS actividad_principal VARCHAR(120) NULL COMMENT 'Actividad / rubro (referencia impresión)' AFTER jurisdiccion_iibb;
