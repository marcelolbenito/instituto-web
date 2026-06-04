-- Renombra rol caja → secretaria.

SET NAMES utf8mb4;

UPDATE usuarios SET rol = 'secretaria' WHERE rol = 'caja';

ALTER TABLE usuarios
  MODIFY COLUMN rol ENUM('admin', 'secretaria', 'consulta', 'alumno') NOT NULL DEFAULT 'secretaria';
