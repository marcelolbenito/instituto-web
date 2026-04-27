# Matriz de migracion Fox -> SQL (facturacion y electronica)

Este documento define un punto de partida para migrar el circuito de facturacion
legacy (Fox/DBF) a MariaDB sin perder trazabilidad operativa.

## 1) Tablas legacy principales del circuito

- `CLIENTES`
- `PAGOS`
- `HI_PAGO`
- `ABONCLIE`
- `ARTICULO`
- `PORCEN`
- `FACTURAS`
- `FDETALLE`
- `CAJA`
- `NCOMPRO`
- `NUMERO`
- `FACDOS`
- `ITEMFAC`

## 2) Mapeo propuesto a tablas SQL nuevas

- `CLIENTES` -> `alumnos` (ya existe)
- `ARTICULO` -> `articulos` (ya existe)
- `ABONCLIE` -> `alumno_articulo` (ya existe)
- `PORCEN` -> `parametros_cobranza` (ya existe)
- `PAGOS` / `HI_PAGO` -> `cuota_mensual`, `pago_registrado`, `pago_aplica_cuota` (ya existe)
- `NCOMPRO` / `NUMERO` -> `talonario`, `talonario_ultimo_numero` (nuevo)
- `FACTURAS` -> `comprobante` (nuevo)
- `FDETALLE` -> `comprobante_detalle` (nuevo)
- `CAJA` -> `caja_movimiento` (nuevo)
- `FACDOS` / `ITEMFAC` -> `comprobante_electronico`, `comprobante_electronico_evento` (nuevo)

## 3) Campos clave sugeridos (trazabilidad)

### CLIENTES -> alumnos
- `CLIENTES.CODIGO` -> `alumnos.codigo_legacy`
- `CLIENTES.RAZON` -> `alumnos.nombre_completo`
- `CLIENTES.NDOC`/`NROCUIT` -> `alumnos.documento`
- `CLIENTES.OBSERV` -> `alumnos.curso`

### FACTURAS -> comprobante
- Identificador Fox (si existe) -> `comprobante.id_legacy`
- Tipo (Factura, NC, Recibo, etc.) -> `comprobante.tipo`
- Punto de venta -> `comprobante.punto_venta`
- Numero -> `comprobante.numero`
- Fecha -> `comprobante.fecha_emision`
- Cliente -> `comprobante.alumno_id`
- Neto/IVA/Total -> `comprobante.importe_neto`, `importe_iva`, `importe_total`
- Estado/anulado -> `comprobante.estado`

### FDETALLE -> comprobante_detalle
- FK comprobante -> `comprobante_detalle.comprobante_id`
- Articulo -> `comprobante_detalle.articulo_id` o texto legado
- Cantidad -> `comprobante_detalle.cantidad`
- Precio unitario -> `comprobante_detalle.precio_unitario`
- Bonificacion/recargo -> `comprobante_detalle.bonificacion`, `recargo`
- Importe -> `comprobante_detalle.importe_total`

### FACDOS/ITEMFAC -> comprobante_electronico
- CAE -> `comprobante_electronico.cae`
- Vto CAE -> `comprobante_electronico.cae_vencimiento`
- Numero electronico -> `comprobante_electronico.numero_electronico`
- Resultado/errores -> `comprobante_electronico.resultado`, `mensaje_error`
- Payload/request/response (opcional) -> JSON en `request_json`, `response_json`

### CAJA -> caja_movimiento
- Fecha/hora -> `caja_movimiento.fecha_hora`
- Monto -> `caja_movimiento.importe`
- Medio -> `caja_movimiento.medio`
- Referencia -> `caja_movimiento.referencia`
- Comprobante asociado -> `caja_movimiento.comprobante_id`

## 4) Reglas de negocio minimas a conservar

- No duplicar numeracion por (`tipo`, `punto_venta`, `numero`).
- No permitir comprobante en estado `emitido` sin al menos un detalle.
- Para electronica:
  - si `estado='autorizado'`, `cae` y `cae_vencimiento` son obligatorios.
  - registrar cada intento (evento) para auditar fallas.
- Mantener puente con legacy: guardar `id_legacy` y `origen='fox'` durante transicion.

## 5) Orden recomendado de implementacion

1. Crear tablas nuevas de comprobantes/electronica (sin afectar tablas actuales).
2. Cargar maestros (`alumnos`, `articulos`, `alumno_articulo`).
3. Migrar historico de comprobantes (`FACTURAS` + `FDETALLE`).
4. Migrar CAE/FVCAE y estado electronico (`FACDOS`).
5. Integrar numeradores (`NCOMPRO`/`NUMERO`) y bloquear duplicados.
6. Activar circuito nuevo para comprobantes recientes, dejando consulta legacy solo lectura.
