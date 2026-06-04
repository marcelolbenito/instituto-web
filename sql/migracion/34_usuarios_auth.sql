-- Extiende tabla usuarios del init (login) y usuario admin por defecto.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'nombre_completo'
    ),
    'SELECT ''nombre_completo ya existe'' AS info',
    'ALTER TABLE usuarios ADD COLUMN nombre_completo VARCHAR(120) NOT NULL DEFAULT '''' AFTER hash_password'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO usuarios (login, hash_password, nombre_completo, rol, activo)
SELECT 'admin', '$2y$10$Aqky2wapccsjHrbVLbzPPuyFcuBi/O5AmIVBe/Rvao7guhxJrrgFe', 'Administrador', 'admin', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE login = 'admin');
