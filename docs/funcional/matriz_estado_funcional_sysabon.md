# Matriz de estado funcional vs modo operativo SYStabon

Referencia base: `docs/funcional/modo_operativo_sysabon.md`.

Convención de estado:

- Implementado: usable en la app actual.
- Parcial: existe algo, pero falta cobertura funcional.
- Pendiente: no implementado en la app actual.

## 1) Archivos (maestros y mantenimiento)

| Funcionalidad legacy | Estado | Evidencia en app | Observación |
|---|---|---|---|
| Artículos (ficha maestra) | Implementado | `public/articulos.php` | ABM base + listas de precio. |
| Rubros | Implementado | `public/rubros.php` | ABM base operativo. |
| Barrios | Implementado | `public/barrios.php` | ABM base operativo. |
| Clientes / Alumnos (ficha) | Implementado | `public/alumnos.php` | Campos clave del modo operativo incorporados. |
| Clientes -> Cambiar estado | Implementado | `public/alumnos.php` | Manejo por `estado_cuenta` en ficha/edición. |
| Clientes -> Cuenta corriente deudora | Parcial | BD: `vw_cuenta_corriente_alumno` | Falta pantalla dedicada desde Clientes. |
| Clientes -> Histórica cta cte | Parcial | BD y ETL históricos (`07`, `08`) | Falta UI de consulta histórica por alumno. |
| Clientes -> Resumen saldos / morosos | Parcial | Se puede obtener por SQL | Falta informe UI directo. |
| Clientes -> Listado de clientes | Implementado | `public/alumnos.php` | Tabla con búsqueda/orden/paginado. |

## 2) Comprobantes (operación diaria)

| Funcionalidad legacy | Estado | Evidencia en app | Observación |
|---|---|---|---|
| Facturas (impresa/manual) | Pendiente | - | No hay módulo comprobantes aún. |
| Nota de crédito | Pendiente | - | No implementado. |
| Recibos | Pendiente | - | No implementado como pantalla/proceso. |
| Proforma | Pendiente | - | No implementado. |
| Anulación | Pendiente | - | No implementado. |
| Generación de abonos | Parcial | `public/generar_cuotas.php` | Existe generación de cuotas por período. |
| Anular última generación de abonos | Pendiente | - | No implementado. |
| Cierre caja / reimpresión cierre | Pendiente | - | No implementado. |

## 3) Informes

| Funcionalidad legacy | Estado | Evidencia en app | Observación |
|---|---|---|---|
| Resumen de recibos | Pendiente | - | Sin módulo de recibos/informes aún. |
| Lista de precio | Parcial | `public/articulos.php` | Hay listado de artículos con precios; falta informe dedicado/imprimible. |
| IVA ventas | Pendiente | - | Depende de facturación/comprobantes. |
| Morosos (listado) | Parcial | SQL posible con `vw_cuenta_corriente_alumno` | Falta pantalla de informe. |

## 4) Contable / Utilitarios / Salida

| Funcionalidad legacy | Estado | Evidencia en app | Observación |
|---|---|---|---|
| Contable | Pendiente | - | No abordado en esta fase. |
| Control de números | Pendiente | - | Requiere definición de comprobantes/talonarios. |
| Porcentajes (cobranza) | Parcial | Tabla `parametros_cobranza` | Falta UI de parametrización. |
| Clave de acceso / seguridad | Pendiente | - | No hay módulo de login/roles en uso. |
| Porc. rec-tarj | Pendiente | - | No implementado. |
| Ficha de tarjetas | Pendiente | - | No implementado. |
| Salida (cerrar sistema) | N/A | App web | En web no aplica igual que en desktop legacy. |

## 5) Flujo principal observado (modo operativo)

| Paso del flujo | Estado | Observación |
|---|---|---|
| 1. Identificar alumno/cliente | Implementado | `alumnos.php`. |
| 2. Verificar deuda/cuota | Parcial | Datos migrados y vista SQL; falta pantalla operativa de cta cte. |
| 3. Emitir comprobante | Pendiente | Falta módulo comprobantes. |
| 4. Circuito electrónica | Pendiente | Falta módulo comprobantes/electrónica. |
| 5. Impacto caja y cta cte | Parcial | Cta cte en BD; falta caja operativa en UI. |
| 6. Reporte de control | Parcial | Hay base de datos para reportar; faltan pantallas de informes. |

## 6) Prioridad recomendada (siguiente ola)

1. Pantalla de **Cuenta Corriente por Alumno** (desde `alumnos.php`).
2. Pantalla de **Morosos / Saldos** (informe operativo).
3. Módulo mínimo de **Recibos/Cobros** para operación diaria.
4. Módulo mínimo de **Facturas** + numeración.
5. **Cierre de caja** y reportes diarios.

## 7) Criterio de cierre de funcionalidad

Una funcionalidad se considera cerrada cuando:

- tiene pantalla operativa en web;
- usa datos reales migrados/actualizados;
- tiene validaciones mínimas del modo operativo;
- fue probada con casos reales del instituto.
