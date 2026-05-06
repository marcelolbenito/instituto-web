# Tracking de reingenieria SYSTABON (funcional -> desarrollo)

## Como usar esta planilla

Cada fila representa un proceso real del usuario. No se arranca por tablas ni por
codigo. Se arranca por "que hace hoy la persona" y se define como quedara en el
sistema nuevo.

Plan de ejecucion recomendado (Ola 1, ordenado por bloques y acciones):
`docs/funcional/plan_ola1_orden_desarrollo.md`.

Estados sugeridos:

- Pendiente
- Relevado
- Acordado
- Diseñado
- En desarrollo
- Validado usuario
- Implementado

## Tablero de procesos

| ID | Proceso | Area | Estado | Prioridad | Responsable | Evidencia actual | Regla clave | Riesgo | Fecha objetivo |
|---|---|---|---|---|---|---|---|---|---|
| P-01 | Alta/edicion de alumno-cliente | Administracion | Pendiente | Alta |  |  |  |  |  |
| P-02 | Asignacion de conceptos/cuotas | Administracion | Pendiente | Alta |  |  |  |  |  |
| P-03 | Emision de factura | Facturacion | Pendiente | Alta |  |  |  |  |  |
| P-04 | Emision de recibo/cobro | Caja | Pendiente | Alta |  |  |  |  |  |
| P-05 | Aplicacion de pago a deuda | Caja | Pendiente | Alta |  |  |  |  |  |
| P-06 | Nota de credito/anulacion | Facturacion | Pendiente | Alta |  |  |  |  |  |
| P-07 | Electronica (autorizacion) | Facturacion | Pendiente | Media |  |  |  |  |  |
| P-08 | Cierre diario de caja | Caja | Pendiente | Alta |  |  |  |  |  |
| P-09 | Control de morosos/deuda | Administracion | Pendiente | Media |  |  |  |  |  |
| P-10 | Reportes contables/IVA | Administracion | Pendiente | Media |  |  |  |  |  |

## Ficha de relevamiento por proceso

Copiar este bloque por cada proceso (P-01, P-02, etc.).

### Proceso: [ID - nombre]

- Objetivo de negocio:
- Actor principal:
- Disparador (cuando empieza):
- Datos que usa:
- Pasos actuales (hoy):
  1.
  2.
  3.
- Excepciones frecuentes:
- Controles/validaciones actuales:
- Dolor del usuario:
- Riesgo si falla:
- Resultado esperado:
- Propuesta sistema nuevo (simple):
- Criterio de aceptacion (como sabemos que quedo bien):

## Mapa de priorizacion (recomendado)

### Ola 1 (salida minima operativa)
- P-01 Alta alumno-cliente
- P-02 Asignacion cuota/concepto
- P-03 Emision factura
- P-04 Cobro/recibo
- P-05 Aplicacion de pago

### Ola 2 (control y estabilidad)
- P-06 NC/anulaciones
- P-08 Cierre caja
- P-09 Morosos/deuda

### Ola 3 (madurez y mejora)
- P-07 Electronica definitiva (nuevo enfoque)
- P-10 Reportes avanzados

## Criterio de "no repetir legacy"

Antes de desarrollar, cada proceso debe tener:

- flujo acordado con usuario;
- reglas de negocio explicitas;
- dato minimo requerido;
- evidencia de prueba de punta a punta.
