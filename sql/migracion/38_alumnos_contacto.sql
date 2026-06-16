-- Contacto alumno: email y teléfono WhatsApp (notificaciones futuras).

SET NAMES utf8mb4;

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL COMMENT 'Email para notificaciones' AFTER documento,
  ADD COLUMN IF NOT EXISTS telefono_whatsapp VARCHAR(40) NULL COMMENT 'Teléfono con WhatsApp (solo dígitos o +549...)' AFTER email;
