-- Parche para instalaciones que ya habian creado staging antes de ajustes.
-- Ejecutar una vez en la BD activa.

ALTER TABLE staging_fox_pagos
  ADD COLUMN IF NOT EXISTS ficha INT NULL AFTER codigo_cliente_legacy;

ALTER TABLE staging_fox_facturas
  ADD COLUMN IF NOT EXISTS ncuenta INT NULL AFTER codigo_cliente_legacy,
  ADD COLUMN IF NOT EXISTS ficha INT NULL AFTER ncuenta,
  ADD COLUMN IF NOT EXISTS tcomp SMALLINT NULL AFTER letra,
  ADD COLUMN IF NOT EXISTS sucu INT NULL AFTER punto_venta;

ALTER TABLE staging_fox_fdetalle
  ADD COLUMN IF NOT EXISTS tcomp SMALLINT NULL AFTER factura_id_legacy,
  ADD COLUMN IF NOT EXISTS letra SMALLINT NULL AFTER tcomp,
  ADD COLUMN IF NOT EXISTS sucu INT NULL AFTER letra,
  ADD COLUMN IF NOT EXISTS nrofac INT NULL AFTER sucu,
  ADD COLUMN IF NOT EXISTS ficha INT NULL AFTER nrofac,
  ADD COLUMN IF NOT EXISTS ncuenta INT NULL AFTER ficha;

ALTER TABLE staging_fox_caja
  ADD COLUMN IF NOT EXISTS ncuenta INT NULL AFTER codigo_cliente_legacy,
  ADD COLUMN IF NOT EXISTS tcomp SMALLINT NULL AFTER ncuenta,
  ADD COLUMN IF NOT EXISTS letra SMALLINT NULL AFTER tcomp,
  ADD COLUMN IF NOT EXISTS succom INT NULL AFTER letra,
  ADD COLUMN IF NOT EXISTS nrocom INT NULL AFTER succom;
