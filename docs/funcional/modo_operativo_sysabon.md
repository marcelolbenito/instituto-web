si
# Modo operativo actual de SYSTABON (vista funcional)

## Objetivo de este documento

Describir como trabaja hoy el instituto en SYSTABON, desde el menu y las tareas
diarias, sin foco tecnico. Este documento sirve para:

- entender la operacion real;
- acordar que se mantiene y que se mejora;
- guiar el desarrollo del sistema nuevo por procesos.

## Principio de trabajo

No copiar el sistema viejo "tal cual". Primero entender el flujo real de trabajo,
despues definir el flujo objetivo y recien ahi implementar.

## Menu funcional (actual)

En la pantalla de inicio legacy hay **seis botones** con iconos (barra principal):

- **Archivos**: mantenimiento de datos base (articulos, rubros, barrios, clientes
  y derivados: consultas de cuenta corriente, listados y cambio de estado).
- **Comprobantes**: emision y procesos del dia (facturas, notas de credito,
  recibos, proforma, anulaciones, generacion de abonos, cierre de caja, etc.).
- **Informes**: listados operativos (recibos, lista de precios, IVA ventas; otras
  opciones pueden estar deshabilitadas segun version).
- **Contable**: modulo contable (en esta copia del codigo no se detalla el desplegable).
- **Utilitarios**: numeracion, porcentajes, claves, tarjetas y similares.
- **Salida**: cierre de la aplicacion y retorno al escritorio.

**Nota:** Cuenta corriente, saldos y morosos **no** son un boton aparte: se acceden
desde **Archivos → Clientes** (submenus de consulta e informes).

### Mapa del menu principal (legacy)

Detalle relevado desde el codigo del sistema actual en este repositorio. Sirve para
ubicar cada opcion del menu en tipo de pantalla: **ficha maestra** (ABM de datos
base), **comprobante u operacion** (emisión o proceso del dia), **consulta**,
**informe o listado**, **parametros** (configuracion auxiliar), **no operativa**
(opcion deshabilitada o ausente en esta copia).

#### Boton Archivos

| Opcion | Subopcion | Tipo |
|--------|-----------|------|
| Articulos | — | Ficha maestra |
| Rubros | — | Ficha maestra |
| Barrios | — | Ficha maestra |
| Clientes | Ficha de Cliente | Ficha maestra |
| Clientes | Ficha Cta Cte → Cta. Cte. deudora | Consulta |
| Clientes | Ficha Cta Cte → Historica de Cta. Cte. | Consulta |
| Clientes | Resumen de Saldos → Saldo general | Informe o listado |
| Clientes | Resumen de Saldos → Moroso detallado | Informe o listado |
| Clientes | Resumen de Saldos → Moroso simplificado | Informe o listado |
| Clientes | Listado de Clientes | Informe o listado |
| Clientes | Cambiar Estado de Clientes | Mantenimiento (estado) |

#### Boton Comprobantes

| Opcion | Subopcion | Tipo |
|--------|-----------|------|
| Facturas | Impresas / Manual | Comprobante u operacion |
| Nota de Credito | Impresas / Manual | Comprobante u operacion |
| Recibos | Variante segun submenu (impresas / manual) | Comprobante u operacion |
| Proforma | — | Comprobante u operacion |
| Anulacion | — | Comprobante u operacion |
| Cierre Fis (X) | — | No operativa (deshabilitada en menu) |
| Cierre (Z) | — | No operativa (deshabilitada en menu) |
| Generacion de Abonos | — | Operacion masiva |
| Anular Ultima Gener de Abonos | — | Operacion |
| Cierre Caja | — | Operacion / control |
| Reimprimir Cierre Caja | — | Utilidad |
| Generacion Manual | — | Operacion (abono manual) |

#### Boton Informes

| Opcion | Tipo |
|--------|------|
| Resumen de Recibos | Informe o listado |
| Lista de Precio | Informe o listado |
| Iva Ventas | Informe o listado |
| Listado de Clientes Morosos | No operativa (deshabilitada en menu) |

#### Boton Contable

En esta copia del proyecto **no esta** el programa asociado al boton Contable en
el menu principal; aqui no se listan sus opciones.

#### Boton Utilitarios

| Opcion | Tipo |
|--------|------|
| Control de Numeros | Parametros |
| Porcentajes | Parametros |
| Clave de Acceso | Parametros / seguridad |
| Porc. de Rec-Tarj | Parametros |
| Ficha de Tarjetas | Ficha maestra (tarjetas) |

#### Boton Salida

| Opcion | Tipo |
|--------|------|
| Salida | Fin de sesion (cierra el sistema) |

### Notas sobre el menu legacy

- **Submenus anidados:** varias opciones abren un primer desplegable y luego otro
  (por ejemplo **Archivos → Clientes**, o **Comprobantes → Facturas** con
  variantes impresa / manual).
- **Recibos:** en el codigo del submenu ligado a **Recibos** reaparecen rotulos
  heredados de otro comprobante; lo que vale para la operatoria es la opcion
  **Recibos** del menu **Comprobantes**, no el texto interno del segundo nivel.
- **Listado de Clientes Morosos** bajo **Informes** puede figurar como no seleccionable segun la version o parametros del menu.

## Flujo funcional principal (actual)

1. Se identifica el alumno/cliente.
2. Se verifica deuda o cuota del periodo.
3. Se emite comprobante (factura o recibo, segun caso).
4. Si corresponde, se pasa por circuito de electronica.
5. Se registra impacto en caja y cuenta corriente.
6. Se consulta reporte de control.

## Entidades de negocio (lenguaje usuario)

- Alumno/Cliente: persona responsable de pago.
- Cuota/Deuda: obligacion por periodo.
- Comprobante: respaldo de la operacion.
- Cobro/Pago: aplicacion de dinero a deuda.
- Caja: entrada/salida de dinero del dia.
- Electronica: autorizacion fiscal del comprobante.

## Ficha de cliente (pantalla actual de carga)

Relevamiento desde la pantalla de alta/edicion de clientes que usa hoy el sistema
(Ficha de clientes). Sirve para acordar el dato minimo operativo y lo que queda
como historico u opcional en el sistema nuevo.

### Datos que el operador carga o ve (hoy)

- Codigo interno del cliente.
- Apellido y nombre (razon social en pantalla).
- Orden (referencia auxiliar del legado).
- Direccion.
- Barrio: codigo de barrio y nombre (el nombre se completa desde el maestro).
- Condicion frente al IVA (inscripto, no inscripto, exento, consumidor final,
  monotributo).
- CUIT (cuando la condicion no es consumidor final).
- Fecha de ingreso.
- Estado operativo (activo, desconectado, inactivo) y, si corresponde, fecha de
  baja o desconexion.
- Servicios contratados en la ficha: cable e internet (marcado y lista de
  precio asociada a cada uno).
- Si el cliente lleva factura (marca en pantalla).
- Observaciones (texto libre).

### Dato minimo para operar (propuesta para validar con usuarios)

Sin esto no deberia darse de alta un cliente que vaya a facturar o cobrar:

- Identificador unico en sistema (codigo o equivalente en el producto nuevo).
- Nombre completo o razon social.
- Direccion y barrio coherentes con el maestro de barrios.
- Condicion IVA y, salvo consumidor final, CUIT valido y unico en negocio.
- Fecha de ingreso.
- Estado (activo / baja / desconexion) con fecha de cierre cuando no esta activo.

### Campos del legado que pueden ser opcionales u obsoletos en pantalla nueva

Existen en la base historica de clientes pero no aparecen en la ficha actual de
carga; conviene confirmar si alguien los sigue usando en otro proceso o informe:

- Manzana y numero de casa (ubicacion detallada).
- Cobrador asignado (codigo y descripcion).
- Tipo y numero de documento distintos del CUIT.
- Importe o flags de abono agregados en fichas mas antiguas.
- Cualquier otro atributo solo consultado por listados legacy.

### Controles que aplica hoy la pantalla (lenguaje usuario)

- No permite dejar en blanco apellido y nombre, direccion ni fecha de ingreso.
- Si no es consumidor final, exige CUIT y lo valida; si el CUIT ya existe, el
  nombre debe coincidir con el ya registrado para ese CUIT.
- El codigo de barrio debe existir en el maestro; si no se usa codigo, se puede
  buscar barrio por nombre y el sistema rellena codigo y detalle.
- Alerta si la fecha de ingreso es muy antigua o futura (el operador puede
  confirmar o corregir).
- Si el estado no es activo, se espera fecha de baja; esa fecha no puede ser
  anterior a la de ingreso.

## Ficha de articulo (pantalla actual de carga)

### Donde esta en el menu (hoy)

- Menu principal: **Archivos**.
- Opcion: **Articulos** (texto de ayuda: archivo de articulos, altas, bajas y
  modificaciones).
- Titulo de la ventana de trabajo: **FICHA DE ARTICULO**.

En el mismo menu **Archivos** estan tambien **Rubros**, **Clientes** (submenu)
y **Barrios**, que se relacionan con esta ficha.

### Datos que el operador carga o ve (hoy)

- Codigo del articulo (numerico).
- Detalle o nombre del concepto (texto).
- Rubro: codigo y descripcion (la descripcion se completa desde el maestro de
  rubros).
- Clasificacion del articulo: **abono** o **articulos varios**.
- Unidad de venta: **unidad** o **fraccion** (segun como se factura o mide).
- IVA: codigo y descripcion tomados del maestro de alicuotas (tabla de tipos de
  IVA).
- Precios por lista de venta (en pantalla aparecen como lista 1 a4, con
  importes de venta final): en la practica son varios precios de referencia
  (venta, minorista, publico, interior u homologos segun rotulos en pantalla).

### Dato minimo para operar (propuesta para validar con usuarios)

- Codigo unico de articulo en el instituto.
- Detalle legible y no duplicado respecto a otro articulo activo.
- Rubro valido en maestro.
- Tipo abono vs varios acordado con la operatoria de cuotas y facturacion.
- Alicuota IVA coherente con el comprobante.
- Al menos un importe de referencia vigente para cobranza (o politica clara de
  cual lista usa cada cliente o cada servicio).

### Campos o bloques desactivados u opcionales en la pantalla actual

Parte de la ficha legacy tiene lineas comentadas o controles deshabilitados
(costo, descuentos porcentuales, stock, proveedor, cuenta contable, etc.). Antes
de llevarlos al sistema nuevo hay que confirmar si el instituto los usa en
otro modulo o solo quedaron de una version anterior.

### Controles que aplica hoy la pantalla (lenguaje usuario)

- No permite codigo en cero en alta; en alta no puede repetirse un codigo ya
  existente.
- Exige detalle no vacio y no duplicado (mismo texto que otro articulo).
- Si se ingresa rubro por codigo, debe existir en el maestro; si se busca por
  nombre, el sistema rellena codigo y descripcion.
- Al elegir tipo **abono**, la pantalla ajusta la medida de venta (unidad o
  fraccion) segun la logica del programa legacy.

## Reglas de negocio observadas (para validar con usuarios)

- No puede existir comprobante sin cliente valido.
- Numeracion de comprobantes debe ser unica por tipo/punto de venta.
- Todo cobro debe quedar aplicado a una deuda o saldo a favor.
- Una anulacion debe dejar trazabilidad del comprobante origen.
- Si hay electronica, el resultado (aprobado/error) debe quedar registrado.

## Dolor actual (hipotesis)

- Mucha logica implicita en la operatoria (depende de quien carga).
- Trazabilidad incompleta entre comprobante, cobro y caja.
- Mantenimiento dificil por estructura legacy.
- Reportes dependientes de interpretacion manual.

## Flujo objetivo (a construir)

- Mismo lenguaje operativo para el usuario (no romper habito).
- Menos pasos manuales y menos duplicacion de carga.
- Trazabilidad completa de cada operacion.
- Reglas de validacion automaticas.
- Reportes operativos y de control confiables.

## Taller funcional sugerido (1 jornada)

### Bloque 1: alta y mantenimiento
- Como se da de alta un alumno/cliente.
- Cuando y como se asignan conceptos/cuotas.

### Bloque 2: facturacion y cobro
- Caso normal.
- Caso con diferencia/saldo parcial.
- Caso con nota de credito/anulacion.

**Implementación actual (app instituto-web):** resumen técnico de `registrar_cobro`, saldo impago, legacy `PAGOS:…:YYYY-MM` y marcas Q/L — ver [registrar_cobro_contexto.md](registrar_cobro_contexto.md).

### Bloque 3: cierre y control
- Cierre de caja.
- Control de pendientes.
- Reportes que "si o si" necesitan.

## Definiciones pendientes para el sistema nuevo

- Catalogo final de tipos de comprobante.
- Politica de numeracion (por sucursal y tipo).
- Politica de aplicacion de pagos (automatico/manual).
- Politica de anulaciones y reaperturas.
- Minimo de reportes para salir a produccion.
