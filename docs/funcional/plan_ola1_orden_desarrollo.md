# Plan Ola 1 - funcionalidades y orden de desarrollo

Objetivo: convertir el relevamiento funcional de SYSTABON en un plan ejecutable para `instituto-web`, manteniendo continuidad operativa y evitando rehacer trabajo.

Referencias base:

- `docs/funcional/modo_operativo_sysabon.md`
- `docs/funcional/matriz_estado_funcional_sysabon.md`
- `docs/funcional/tracking_reingenieria_sysabon.md`
- `docs/funcional/registrar_cobro_contexto.md`

## 1) Funcionalidades minimas Ola 1 (MVP operativo)

Estas funcionalidades son las necesarias para operar dia a dia en administracion/caja.

| ID | Funcionalidad | Estado actual | Criterio de cierre |
|---|---|---|---|
| F-01 | Alta/edicion alumno-cliente | Implementado | ABM usable con validaciones minimas y estado operativo. |
| F-02 | Asignacion de conceptos/cuotas | Parcial | Flujo claro de asignacion + generacion por periodo + validacion en UI. |
| F-03 | Consulta cuenta corriente por alumno | Parcial | Pantalla estable con saldo, movimientos y filtros funcionales. |
| F-04 | Registrar cobro/recibo (sin electronica) | Parcial | Cobro confirma, aplica a cuota(s), deja trazabilidad y actualiza saldo. |
| F-05 | Aplicacion de pago con detalle (capital/recargo/descuento) | Parcial | Migracion + persistencia + visualizacion coherente en cobro/cc. |
| F-06 | Informe morosos/saldos operativos | Parcial | Pantalla de consulta con filtros basicos y exportable simple. |

## 2) Orden recomendado de desarrollo (secuencial)

Regla: no avanzar al siguiente bloque sin cerrar validacion funcional del bloque actual.

### Bloque A - Base de cobranza consistente

1. Cerrar esquema de datos de aplicacion por cuota:
   - `sql/migracion/16_pago_aplica_cuota_detalle.sql`.
2. Verificar runtime de `registrar_cobro` con migraciones requeridas (`14` y `16`).
3. Homologar calculo de saldo impago entre cobro y cuenta corriente.
4. Definir casos de prueba base: cuota al dia, cuota parcial, cuota con legacy periodo.

Salida del bloque:

- saldo impago consistente en SQL y UI;
- cobro no rompe por columnas faltantes;
- 3 casos reales validados punta a punta.

### Bloque B - Flujo operativo de caja (MVP)

1. Ajustar UX final de `public/registrar_cobro.php` para operatoria diaria.
2. Confirmar estados visuales unificados (`A cobrar`, `Q`, `L`).
3. Registrar evidencia de cobro por 5 alumnos representativos.
4. Documentar reglas de excepcion (pago parcial, saldo remanente, pronto pago).

Salida del bloque:

- caja puede cobrar sin consultas SQL manuales;
- trazabilidad del cobro en `pago_registrado` + `pago_aplica_cuota`.

### Bloque C - Consulta y control operativo

1. Cerrar pantalla de cuenta corriente por alumno como vista de control.
2. Implementar informe morosos/saldos con filtros minimos (estado, periodo, deuda).
3. Definir check diario de control (cobrado hoy vs deuda pendiente).

Salida del bloque:

- administracion valida deuda y morosidad sin salir de la app.

### Bloque D - Cierre funcional Ola 1

1. Ejecutar checklist diario (`docs/funcional/checklist_seguimiento_migracion_sysabon.md`).
2. Ejecutar validacion Go/No-Go con usuarios clave.
3. Congelar backlog de Ola 2 (NC/anulaciones, cierre de caja formal, facturacion completa).

Salida del bloque:

- modulo operativo estable para salida asistida.

## 3) Acciones concretas para seguir orden (backlog accionable)

Estados sugeridos: `Pendiente`, `En curso`, `Bloqueado`, `Listo para validar`, `Validado`.

| Orden | Accion | Responsable | Estado |
|---|---|---|---|
| A1 | Ejecutar migracion `16_pago_aplica_cuota_detalle.sql` en ambiente local/prueba | Dev | Validado |
| A2 | Validar que `registrar_cobro` detecta columnas nuevas y confirma cobro | Dev | Validado |
| A3 | Comparar 10 casos de saldo impago entre cobro y cuenta corriente | Dev + Usuario clave | Pendiente |
| B1 | Ajustar textos/estados visuales de cobro para operador de caja | Dev | En curso |
| B2 | Probar cobro completo, parcial y con pronto pago (3 escenarios) | Dev | En curso |
| B3 | Registrar evidencia funcional (IDs, periodo, resultado esperado/real) | Dev | Pendiente |
| C1 | Cerrar pantalla de cuenta corriente como consulta principal de deuda | Dev | En curso |
| C2 | Implementar informe de morosos/saldos (filtros minimos) | Dev | Pendiente |
| C3 | Validar informe con muestra real de 10 alumnos | Admin + Caja | Pendiente |
| D1 | Correr checklist diario y semaforo semanal | Dev + Admin | En curso |
| D2 | Hacer validacion final Go/No-Go de Ola 1 | Responsable funcional | Pendiente |

## 4) Sprint 1 sugerido (corto, 1 a 2 dias)

Objetivo del sprint: dejar cerrada la base operativa de cobranza y control minimo sin desviar alcance.

| Tarea | Toma de backlog | Entregable |
|---|---|---|
| S1-T1 | A1 | Migracion `16` aplicada en ambiente local/prueba con verificacion de columnas nuevas. |
| S1-T2 | A2 | Cobro de prueba confirmado en UI sin error de esquema (columnas `14` y `16`). |
| S1-T3 | A3 | Matriz de 10 casos comparando saldo impago entre cobro y cuenta corriente. |
| S1-T4 | B2 | Pruebas funcionales: cobro completo, parcial y pronto pago (resultado esperado vs real). |
| S1-T5 | B3 + D1 | Evidencia documentada en checklist diario + semaforo semanal actualizado. |

Criterio de cierre del sprint:

1. S1-T1 a S1-T5 en `Validado` o, como maximo, una en `Listo para validar`.
2. Sin diferencias criticas de saldo en los 10 casos comparados.
3. Sin bloqueos tecnicos abiertos para continuar Bloque B.

## 5) Secuencia diaria recomendada (orden de ejecucion)

1. Ejecutar SQL de esquema (A1).
2. Probar cobro real en app (A2, B2).
3. Comparar saldos cobro vs cuenta corriente (A3).
4. Registrar evidencia en checklist (B3, D1).
5. Cerrar dia con estado de backlog y siguiente accion puntual.

## 6) Validacion rapida de 3 casos (2026-05-06)

Resultado resumido:

| Caso | Cuota | Resultado |
|---|---|---|
| Al dia | `285297` | `saldo_impago = 0` (ok en logica de cobro). |
| Parcial | `284961` | `saldo_impago = 42700` con aplicado parcial (ok para seguir cobrando). |
| Legacy por periodo | `285295` | Cobertura legacy detectada; sin saldo pendiente en cobro. |

Hallazgo para seguimiento (importante):

- Existen pagos `legacy` duplicados sin sufijo `:YYYY-MM` en `referencia` y con `importe_capital = 0`, ademas del registro correcto con periodo.
- Esto puede distorsionar lectura en cuenta corriente (dependiendo del periodo inferido por fecha) y generar diferencias aparentes entre vistas.

Accion recomendada:

- Agregar tarea de saneamiento de duplicados legacy antes de cerrar `A3` en `Validado`.
- Regla minima: conservar registro legacy con `referencia` periodizada (`PAGOS:ncuenta=...:YYYY-MM`) y excluir/marcar duplicado sin periodo.

## 7) Definicion de listo (Definition of Done) para cada funcionalidad

Una funcionalidad se considera cerrada solo si cumple todo:

1. UI usable por usuario no tecnico.
2. Regla de negocio documentada y aplicada.
3. Datos consistentes en BD (sin correcciones manuales post proceso).
4. Evidencia funcional guardada (caso + resultado).
5. Aprobacion de usuario clave (administracion/caja).

## 8) Limites de Ola 1 (para mantener foco)

Queda fuera de Ola 1, salvo urgencia operativa:

- facturacion electronica completa;
- nota de credito y anulaciones avanzadas;
- cierre de caja formal con reimpresion;
- modulo contable;
- seguridad por roles fina.

Esto evita desorden y permite llegar a operacion asistida primero.

## 9) Requisito funcional nuevo (R7 - Beca con vencimiento)

Regla funcional acordada:

- Si el alumno tiene BECA y paga la cuota del período hasta el día 5 inclusive, mantiene el importe becado.
- Si paga después del día 5, pierde BECA para ese período y se debe cobrar el abono completo.
- La diferencia entre importe becado y abono completo debe quedar registrada en el detalle de cuota para trazabilidad.

Definiciones operativas propuestas (para cerrar en validación funcional):

1. Día de corte: día 5 calendario del mes del período.
2. Alcance: la pérdida de BECA aplica solo al período cobrado fuera de término (no arrastra automáticamente a meses siguientes).
3. Trazabilidad: la diferencia de BECA debe quedar separada de mora/interés para no mezclar conceptos.

Criterios de aceptación mínimos:

1. Caso A (al día): alumno con BECA paga el día 5 o antes y el total respeta importe becado.
2. Caso B (fuera de término): alumno con BECA paga después del día 5 y el total pasa a abono completo.
3. En ambos casos, el recibo y la aplicación por cuota muestran claramente capital, recargo/interés, descuento y diferencia BECA (cuando aplique).
4. Cuenta corriente refleja el mismo criterio sin diferencias respecto a registrar cobro.
