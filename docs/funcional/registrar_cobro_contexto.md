# Registrar cobro — contexto técnico (cierre de sesión)

Documento de continuidad: qué quedó implementado en **instituto-web** respecto a cobro por cuotas, listado alineado a cuenta corriente y criterio de “impagas”.

## Archivos principales

| Área | Ruta |
|------|------|
| Pantalla y consultas | `public/registrar_cobro.php` |
| Reglas de saldo, SQL, badges, estado visual | `src/Cobranza.php` |
| Saldo global alumno (otro flujo) | `src/Saldos.php` |
| Cuenta corriente (referencia UX) | `public/cuenta_corriente.php` |

## Migraciones que el cobro exige en runtime

- `14_pagos_componentes_interes_descuento.sql` — columnas de componentes en `pago_registrado`.
- `16_pago_aplica_cuota_detalle.sql` — detalle por cuota en `pago_aplica_cuota`.

La pantalla valida que existan esas columnas antes de confirmar cobro.

## Período operativo

- Solo cuotas con **`anio >=`** valor de `cobranza_anio_operativo_desde()` (por defecto **2026**).
- Variable de entorno opcional: **`OPERATIVO_ANIO_DESDE`**.

## Saldo impago (qué fila entra a “cobrar”)

Para cuotas con **`importe_original > 0`**:

```text
saldo_impago = max(0, importe_original − SUM(pago_aplica_cuota.importe_aplicado) − haber_legacy_mismo_período)
```

**Haber legacy**: filas en `pago_registrado` con `medio = 'legacy'`, `referencia` tipo `PAGOS:ncuenta=%:%` y sufijo **`YYYY-MM`** igual al período de la cuota (`anio` + `mes`). Suma `COALESCE(NULLIF(importe_capital,0), importe)` (misma idea que en `Saldos.php` para no arrastrar legacy fuera de período).

Si **`importe_original`** no aplica (legacy), se usa **`cuota_mensual.saldo`**.

Motivo: en migraciones puede figurar **`estado = 'pagada'`** y **`saldo = 0`** sin filas en `pago_aplica_cuota`, pero **sin** pago legacy de ese mes (ej. marzo/abril 2026 con febrero sí pagado). Sin restar legacy por período, esas cuotas no aparecían; con la resta, sí.

## Cobro vs `SALDO_CORTE_DESDE`

El listado de **registrar cobro** **no** aplica el filtro por fecha de `SALDO_CORTE_DESDE` (el corte sigue siendo relevante para CC / `recalcular_saldo_alumnos`, no para armar la grilla de caja con cuotas 2026+).

## UX en pantalla

- Tabla principal: columnas tipo cuenta corriente + **Pagar** (checkbox), **Marca** (**Q** = abonos por app con detalle y aún hay saldo), **Estado (cobro)**.
- Si en DB dice **`pagada`** pero **`saldo_impago > 0`**, la columna muestra badge **A cobrar** (no el texto “pagada” crudo).
- Segunda tabla: cuotas **liquidadas** en esta lógica, marca **L**, columna **Legacy período**.
- Paso 2 del flujo: la **P** es solo **pronto pago** (descuento), distinta de **Q** / **L**.

## Funciones útiles en `Cobranza.php` (nombre aproximado)

- `cobranza_anio_operativo_desde()`
- `cobranza_sql_join_legacy_haber_por_periodo()`
- `cobranza_sql_expr_saldo_impago()`
- `cobranza_saldo_impago_cuota()`
- `cobranza_calcular_linea_cuota()` — usa saldo impago.
- `cobranza_badge_abono_parcial_html()`, `cobranza_badge_liquidada_html()`
- `cobranza_estado_visual_cobro_html()`

## Pendientes naturales para otra iteración

- Corregir en datos **`cuota_mensual.estado`** donde quedó `pagada` inconsistente.
- Cobro parcial por cuota / comprobante fiscal si se define producto.
- Opcional: unificar criterio legacy-período en **cuenta corriente** al 100% con cobro.
