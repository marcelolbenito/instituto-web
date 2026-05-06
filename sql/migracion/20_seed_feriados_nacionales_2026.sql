-- Seed inicial de feriados nacionales Argentina 2026
-- Fuente de referencia: calendario oficial publicado en argentina.gob.ar
-- Se cargan como ámbito "nacional" para el cálculo de días hábiles.

SET NAMES utf8mb4;

INSERT IGNORE INTO feriados (fecha, ambito, descripcion) VALUES
  ('2026-01-01', 'nacional', 'Año Nuevo'),
  ('2026-02-16', 'nacional', 'Carnaval'),
  ('2026-02-17', 'nacional', 'Carnaval'),
  ('2026-03-23', 'nacional', 'Día no laborable con fines turísticos'),
  ('2026-03-24', 'nacional', 'Día Nacional de la Memoria por la Verdad y la Justicia'),
  ('2026-04-02', 'nacional', 'Día del Veterano y de los Caídos en la Guerra de Malvinas'),
  ('2026-04-03', 'nacional', 'Viernes Santo'),
  ('2026-05-01', 'nacional', 'Día del Trabajador'),
  ('2026-05-25', 'nacional', 'Día de la Revolución de Mayo'),
  ('2026-06-17', 'nacional', 'Paso a la Inmortalidad del General Martín Miguel de Güemes'),
  ('2026-06-20', 'nacional', 'Paso a la Inmortalidad del General Manuel Belgrano'),
  ('2026-07-09', 'nacional', 'Día de la Independencia'),
  ('2026-07-10', 'nacional', 'Día no laborable con fines turísticos'),
  ('2026-08-17', 'nacional', 'Paso a la Inmortalidad del General José de San Martín'),
  ('2026-10-12', 'nacional', 'Día del Respeto a la Diversidad Cultural'),
  ('2026-11-20', 'nacional', 'Día de la Soberanía Nacional'),
  ('2026-12-07', 'nacional', 'Día no laborable con fines turísticos'),
  ('2026-12-08', 'nacional', 'Inmaculada Concepción de María'),
  ('2026-12-25', 'nacional', 'Navidad');
