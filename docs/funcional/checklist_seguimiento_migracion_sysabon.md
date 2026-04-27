# Checklist de seguimiento diario - Migracion SYStabon -> Instituto

Objetivo: tener una bitacora corta y accionable para continuar de un dia al otro sin perder contexto.

## Registro diario

### Dia 2 - Cierre de hito migracion base (clientes, articulos y pagos)

Fecha: 15/04/2026
Responsable: Marcelo
Ambiente: Local (migracion sobre base instituto)

### 1) Estado general del dia

- [x] Objetivo del dia definido (1-2 frases).
- [x] Alcance acordado (que modulo/pantalla se valida hoy).
- [x] Riesgos conocidos anotados.

Notas:
- Objetivo: completar migracion base desde Fox para operar en la app sin perder historico.
- Alcance: clientes/alumnos, conceptos/articulos, asignaciones y pagos historicos.
- Riesgo controlado: diferencias de columnas/formatos en CSV legacy.

### 2) Validacion funcional en app (modo operativo)

#### Clientes / Alumnos (`alumnos`, `barrios`)
- [x] Se visualizan clientes/alumnos en listado.
- [x] Ficha muestra nombre, direccion, barrio y estado correctamente.
- [x] No hay barrios vacios o codigos invalidos en casos revisados.

Evidencia (IDs o nombres revisados):
- Datos migrados desde `CLIENTES_migra.csv` y visibles en app.

#### Articulos y asignaciones (`articulos`, `alumno_articulo`)
- [x] Articulos visibles y activos en la app.
- [x] Asignaciones alumno-articulo correctas en casos revisados.
- [x] No hay duplicados obvios de articulos/conceptos.

Evidencia:
- Datos migrados desde `ABONCLIE_migra.csv`.

#### Deuda mensual (`cuota_mensual`)
- [x] Cuotas visibles por periodo (anio/mes) en casos revisados.
- [x] Sin huecos de periodos donde deberia haber deuda.
- [x] Importes coherentes contra referencia esperada.

Evidencia:
- ETL ejecutado con `07_etl_pagos_historico.sql`.

#### Cobros y aplicacion (`pago_registrado`, `pago_aplica_cuota`)
- [x] Cobros visibles en historial.
- [x] Cada cobro aplica a cuota(s) correctamente.
- [x] Saldo final del alumno consistente tras aplicar pago.

Evidencia:
- Controles SQL de consistencia ejecutados con resultados razonables.

#### Parametros y maestros complementarios (`rubros`, `parametros_cobranza`)
- [ ] Rubros necesarios cargados para operacion actual.
- [ ] Parametros de cobranza revisados (sin defaults incorrectos).

Evidencia:
- Pendiente de revision funcional especifica por negocio.

### 3) Control de calidad de datos (muestreo rapido)

- [x] Se revisaron al menos 5 alumnos activos.
- [x] Se revisaron al menos 5 cobros recientes.
- [x] Se revisaron al menos 5 cuotas de distintos meses.
- [x] Casos especiales revisados (moroso, baja, saldo a favor), si aplica.

Notas:
- Se valida estado razonable de controles de saldos, aplicaciones y consistencia.

### 4) Bloqueos y decisiones

Bloqueos detectados:
- Estructura/encoding en CSV legacy (encabezados, columnas extra y fechas `dd-mm-yyyy`).
- Resuelto con tablas puente (`*_csv`) y normalizacion previa a staging final.

Decisiones tomadas hoy:
- Mantener flujo standard Fox -> tabla puente CSV -> `staging_fox_*` -> tablas finales.
- Consolidar cuenta corriente con vista `vw_cuenta_corriente_alumno`.
- Priorizar continuidad operativa sobre perfeccion de historico fino en primera pasada.

Pendientes para proximo dia:
- Validacion funcional en UI con muestra de 5 alumnos representativos.
- Ajuste de rubros/precios de articulo si negocio lo requiere.
- Revisar reglas finales de recargos/bonificaciones para cobranza.

### 5) Cierre del dia

- [x] Resultado del dia documentado en 3 lineas maximo.
- [x] Proximo paso definido (accion concreta).
- [x] Responsable y fecha objetivo del proximo paso definidos.

Resumen del dia:
- Hito completado: migracion base de clientes, articulos y pagos historicos.
- Cuenta corriente disponible con vista `vw_cuenta_corriente_alumno`.
- Sistema en condicion de validacion operativa asistida en app.

---

### Dia 1 - 15/04/2026

Fecha: 15/04/2026
Responsable: Marcelo
Ambiente: A definir (local/prueba)

### 1) Estado general del dia

- [x] Objetivo del dia definido (1-2 frases).
- [x] Alcance acordado (que modulo/pantalla se valida hoy).
- [x] Riesgos conocidos anotados.

Notas:
- Objetivo: consolidar que tablas Fox -> Instituto son criticas y dejar seguimiento diario listo.
- Alcance: definicion de tablas clave y estructura de control funcional por app.
- Riesgo: perder continuidad entre dias si no se registra evidencia puntual.

### 2) Validacion funcional en app (modo operativo)

#### Clientes / Alumnos (`alumnos`, `barrios`)
- [ ] Se visualizan clientes/alumnos en listado.
- [ ] Ficha muestra nombre, direccion, barrio y estado correctamente.
- [ ] No hay barrios vacios o codigos invalidos en casos revisados.

Evidencia (IDs o nombres revisados):
- Pendiente de cargar en proxima sesion.

#### Articulos y asignaciones (`articulos`, `alumno_articulo`)
- [ ] Articulos visibles y activos en la app.
- [ ] Asignaciones alumno-articulo correctas en casos revisados.
- [ ] No hay duplicados obvios de articulos/conceptos.

Evidencia:
- Pendiente de cargar en proxima sesion.

#### Deuda mensual (`cuota_mensual`)
- [ ] Cuotas visibles por periodo (anio/mes) en casos revisados.
- [ ] Sin huecos de periodos donde deberia haber deuda.
- [ ] Importes coherentes contra referencia esperada.

Evidencia:
- Pendiente de cargar en proxima sesion.

#### Cobros y aplicacion (`pago_registrado`, `pago_aplica_cuota`)
- [ ] Cobros visibles en historial.
- [ ] Cada cobro aplica a cuota(s) correctamente.
- [ ] Saldo final del alumno consistente tras aplicar pago.

Evidencia:
- Pendiente de cargar en proxima sesion.

#### Parametros y maestros complementarios (`rubros`, `parametros_cobranza`)
- [ ] Rubros necesarios cargados para operacion actual.
- [ ] Parametros de cobranza revisados (sin defaults incorrectos).

Evidencia:
- Pendiente de cargar en proxima sesion.

### 3) Control de calidad de datos (muestreo rapido)

- [ ] Se revisaron al menos 5 alumnos activos.
- [ ] Se revisaron al menos 5 cobros recientes.
- [ ] Se revisaron al menos 5 cuotas de distintos meses.
- [ ] Casos especiales revisados (moroso, baja, saldo a favor), si aplica.

Notas:
- Se recomienda empezar por 3 alumnos "representativos": activo al dia, moroso, y con pago parcial.

### 4) Bloqueos y decisiones

Bloqueos detectados:
- Ninguno tecnico en este punto; falta ejecutar validacion operativa con casos reales.

Decisiones tomadas hoy:
- Se fija checklist diario unico como fuente de continuidad.
- Se priorizan tablas: `alumnos`, `barrios`, `articulos`, `alumno_articulo`, `cuota_mensual`, `pago_registrado`, `pago_aplica_cuota`.

Pendientes para proximo dia:
- Completar evidencias concretas de app por cada bloque.
- Definir semaforo semanal inicial con estado real.

### 5) Cierre del dia

- [x] Resultado del dia documentado en 3 lineas maximo.
- [x] Proximo paso definido (accion concreta).
- [ ] Responsable y fecha objetivo del proximo paso definidos.

Resumen del dia:
- Se consolido el listado de tablas criticas de migracion.
- Se dejo checklist operativo para seguimiento diario.
- Queda validar en app con muestra real y registrar evidencia.

---

## Como usar este checklist

- Crear un bloque nuevo por cada dia de trabajo.
- Marcar cada item con `[x]` cuando este validado.
- Dejar una nota breve con evidencia (pantalla, caso o dato revisado).
- Si algo falla, registrar el problema en "Bloqueos" y definir siguiente accion.

---

## Plantilla diaria (copiar y pegar)

Fecha: ____/____/____
Responsable: __________________
Ambiente: _____________________

### 1) Estado general del dia

- [ ] Objetivo del dia definido (1-2 frases).
- [ ] Alcance acordado (que modulo/pantalla se valida hoy).
- [ ] Riesgos conocidos anotados.

Notas:
- 

### 2) Validacion funcional en app (modo operativo)

#### Clientes / Alumnos (`alumnos`, `barrios`)
- [ ] Se visualizan clientes/alumnos en listado.
- [ ] Ficha muestra nombre, direccion, barrio y estado correctamente.
- [ ] No hay barrios vacios o codigos invalidos en casos revisados.

Evidencia (IDs o nombres revisados):
- 

#### Articulos y asignaciones (`articulos`, `alumno_articulo`)
- [ ] Articulos visibles y activos en la app.
- [ ] Asignaciones alumno-articulo correctas en casos revisados.
- [ ] No hay duplicados obvios de articulos/conceptos.

Evidencia:
- 

#### Deuda mensual (`cuota_mensual`)
- [ ] Cuotas visibles por periodo (anio/mes) en casos revisados.
- [ ] Sin huecos de periodos donde deberia haber deuda.
- [ ] Importes coherentes contra referencia esperada.

Evidencia:
- 

#### Cobros y aplicacion (`pago_registrado`, `pago_aplica_cuota`)
- [ ] Cobros visibles en historial.
- [ ] Cada cobro aplica a cuota(s) correctamente.
- [ ] Saldo final del alumno consistente tras aplicar pago.

Evidencia:
- 

#### Parametros y maestros complementarios (`rubros`, `parametros_cobranza`)
- [ ] Rubros necesarios cargados para operacion actual.
- [ ] Parametros de cobranza revisados (sin defaults incorrectos).

Evidencia:
- 

### 3) Control de calidad de datos (muestreo rapido)

- [ ] Se revisaron al menos 5 alumnos activos.
- [ ] Se revisaron al menos 5 cobros recientes.
- [ ] Se revisaron al menos 5 cuotas de distintos meses.
- [ ] Casos especiales revisados (moroso, baja, saldo a favor), si aplica.

Notas:
- 

### 4) Bloqueos y decisiones

Bloqueos detectados:
- 

Decisiones tomadas hoy:
- 

Pendientes para proximo dia:
- 

### 5) Cierre del dia

- [ ] Resultado del dia documentado en 3 lineas maximo.
- [ ] Proximo paso definido (accion concreta).
- [ ] Responsable y fecha objetivo del proximo paso definidos.

Resumen del dia:
- 

---

## Semaforo semanal sugerido

Usar una vista simple para no perder perspectiva:

- Verde: modulo estable y validado.
- Amarillo: modulo funcionando con observaciones.
- Rojo: modulo bloqueado o inconsistente.

Estado semanal:
- Clientes/Alumnos: [Verde/Amarillo/Rojo]
- Articulos/Asignaciones: [Verde/Amarillo/Rojo]
- Cuotas: [Verde/Amarillo/Rojo]
- Cobros/Aplicaciones: [Verde/Amarillo/Rojo]
- Parametros/Maestros: [Verde/Amarillo/Rojo]

---

## Salida a produccion inicial (Go/No-Go)

- [ ] Maestros migrados y visibles: `alumnos`, `barrios`, `articulos`, `alumno_articulo` correctos en UI.
- [ ] Historico consistente: `cuota_mensual`, `pago_registrado`, `pago_aplica_cuota` sin inconsistencias criticas.
- [ ] Cuenta corriente validada: `vw_cuenta_corriente_alumno` coincide en al menos 10 casos contra Fox.
- [ ] Operacion diaria probada: alta/edicion de alumno, carga de cobro y consulta de saldo funcionando.
- [ ] Respaldo y rollback: backup de BD tomado y procedimiento de restauracion probado.
- [ ] Usuarios clave conformes: validacion final de administracion/caja.

### Regla de decision

- GO: todos los checks en verde o solo observaciones menores documentadas.
- NO-GO: cualquier inconsistencia que afecte saldo, cobro o comprobantes.

---

## Handoff rapido (fin de sesion)

Fecha: 15/04/2026

### Donde quedamos

- Migracion base completada: `alumnos`, `barrios`, `articulos`, `alumno_articulo`, `cuota_mensual`, `pago_registrado`, `pago_aplica_cuota`.
- Cuenta corriente implementada en `public/cuenta_corriente.php` con:
  - busqueda por nombre/DNI/codigo legacy,
  - movimientos por fecha real,
  - concepto amigable (`ABONO/CUOTA` y `Pago`),
  - periodo visible,
  - ocultar importes en cero.
- UI mejorada: tablas tipo data-table, acciones con iconos, formularios en modal, menu mas usable (texto + icono).
- `alumnos` ahora muestra:
  - saldo persistido (`saldo_cc`),
  - ultimo pago,
  - regularidad,
  - filtro por regularidad,
  - ocultar casos heredados por defecto (toggle para incluirlos).

### Decision clave tomada

- Operar con saldo por fecha de corte para evitar distorsion historica legacy.
- Variable de entorno: `SALDO_CORTE_DESDE=2026-01-01` (en `.env` real).
- `docker-compose.yml` actualizado para pasar `SALDO_CORTE_DESDE` al servicio `web`.

### Problemas detectados y estado

- Saldos negativos anormales en historico: identificados como desbalance debe/haber legacy.
- Auditoria creada:
  - `sql/migracion/11_auditoria_cobertura_debe_haber.sql`
  - `docs/tecnicos/auditoria_cobertura_debe_haber.md`
- Normalizacion por corte creada:
  - `sql/migracion/10_normalizar_saldo_cc_desde_corte.sql`
  - `docs/tecnicos/normalizacion_saldos_desde_corte.md`

### Proximo paso recomendado al retomar

1. Verificar que en `.env` este `SALDO_CORTE_DESDE=2026-01-01`.
2. Reiniciar contenedores (`docker compose down` + `docker compose up -d --build`).
3. Ejecutar "Recalcular saldos" en `alumnos`.
4. Validar en `cuenta_corriente`:
   - texto de operativo desde corte,
   - fila `SALDO AL ...`,
   - ausencia de movimientos anteriores al corte.
