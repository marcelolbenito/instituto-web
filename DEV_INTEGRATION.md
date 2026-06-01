# Guía de integración — equipo de desarrollo

Documento para integrar un sistema externo nuevo al servicio de facturación electrónica `gesis-factura-electronica`. Este servicio es un wrapper REST sobre la API SOAP de AFIP/ARCA — abstrae certificados, tokens TRA y manejo SSL, pero los datos del comprobante son AFIP nativos.

**URL producción:** `https://servicios.gesis2.com`

> Prerrequisito: el negocio y el usuario de integración tienen que estar dados de alta en el sistema, con los certificados AFIP subidos. Esa parte la maneja el admin desde el dashboard — no es responsabilidad del equipo de integración.

---

## 1. Flujo de integración

### 1.1 Autenticación — obtener JWT

```http
POST /api/v1/auth/token
Content-Type: application/json

{
  "email": "sistemax@empresa.com",
  "password": "<password>"
}
```

Respuesta:
```json
{
  "access_token": "eyJ...",
  "token_type": "bearer"
}
```

- TTL del token: **30 minutos**. Renovar antes que expire.
- Header en todas las requests siguientes: `Authorization: Bearer {access_token}`.
- Si recibís 401, re-autenticate y reintentá la operación.

### 1.2 Emitir comprobante (recomendado: número auto)

```http
POST /api/v1/arca/crear-proximo-comprobante
Authorization: Bearer {token}
Content-Type: application/json
```

Body: ver sección 2 abajo (campos AFIP).

Diferencias vs `crear-comprobante`:
- **`crear-proximo-comprobante`**: el servicio consulta a AFIP el último número autorizado para ese (PtoVta, Tipo) y emite el siguiente. Más simple, menos riesgo de duplicados.
- **`crear-comprobante`**: vos especificás `CbteDesde`/`CbteHasta`. Útil si llevás tu propio control de numeración.

Respuesta exitosa:
```json
{
  "CAE": "75123456789012",
  "CAEFchVto": "20260615",
  "voucherNumber": 142,
  "Observaciones": []
}
```

### 1.3 Consultar comprobante emitido

```http
GET /api/v1/arca/informacion-comprobante/{numero}/{punto_venta}/{tipo}?production=true
Authorization: Bearer {token}
```

Retorna el comprobante tal cual lo tiene AFIP (CAE, fecha, todos los importes).

### 1.4 Endpoints de parámetros (catálogos AFIP)

Todos requieren `Authorization: Bearer {token}` y aceptan `?production=true/false`.

| Endpoint | Para qué |
|---|---|
| `GET /api/v1/arca/puntos-venta` | Puntos de venta habilitados para el negocio |
| `GET /api/v1/arca/tipos-comprobante` | Factura A=1, B=6, C=11, NC A=3, NC B=8, etc. |
| `GET /api/v1/arca/tipos-concepto` | Productos=1, Servicios=2, Ambos=3 |
| `GET /api/v1/arca/tipos-alicuota` | Alícuotas IVA: 0%=3, 10.5%=4, 21%=5, 27%=6, 5%=8, 2.5%=9 |
| `GET /api/v1/arca/tipos-moneda` | PES, DOL, etc. |
| `GET /api/v1/arca/condiciones-iva` | Condición IVA del receptor (RI, MO, EX, CF...) |
| `GET /api/v1/arca/tipos-tributo` | Tributos provinciales / municipales |
| `GET /api/v1/arca/ultimo-comprobante/{ptovta}/{tipo}` | Último número emitido |
| `GET /api/v1/arca/estado-servidor` | Ping a AFIP (AppServer/DbServer/AuthServer) |
| `GET /api/v1/arca/cotizacion/{moneda}/{YYYYMMDD}` | Cotización oficial AFIP |
| `GET /api/v1/arca/datos-contribuyente?cuit=...` | Datos del padrón AFIP |

---

## 2. Schema del body — `VoucherData`

Definido en `app/dominio/schemas/arca.py`. Campos AFIP nativos, casi 1:1 con la spec WSFEv1.

### 2.1 Campos obligatorios

| Campo | Tipo | Descripción |
|---|---|---|
| `CantReg` | int | Cantidad de comprobantes en el lote. Siempre `1` para facturas individuales. |
| `PtoVta` | int | Punto de venta (entero, no string). Tiene que estar habilitado en AFIP. |
| `CbteTipo` | int | Tipo de comprobante (Factura A=1, B=6, C=11...). Ver `/tipos-comprobante`. |
| `Concepto` | int | 1=Productos, 2=Servicios, 3=Productos y Servicios. |
| `DocTipo` | int | Tipo doc receptor: CUIT=80, CUIL=86, DNI=96, Consumidor Final=99. |
| `DocNro` | int | Número de documento del receptor. Para CF usar `0`. |
| `CbteDesde` | int | Número del comprobante. En `crear-proximo-comprobante` poner `1` (el wrapper lo corrige). |
| `CbteHasta` | int | Igual a `CbteDesde` para facturas individuales. |
| `ImpTotal` | float | **Total del comprobante**. Debe cumplir: `ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpIVA + ImpTrib`. |
| `ImpTotConc` | float | Importe neto **no gravado** (mercadería sin IVA discriminado). Usar `0` si no aplica. |
| `ImpNeto` | float | Importe neto **gravado** (sin IVA). |
| `ImpOpEx` | float | Importe de operaciones **exentas** de IVA. Usar `0` si no aplica. |
| `ImpIVA` | float | Suma de los IVAs aplicados. Si Factura C → `0`. |
| `ImpTrib` | float | Suma de tributos provinciales/municipales. Usar `0` si no aplica. |
| `MonId` | str | Moneda. `"PES"` para pesos argentinos. |
| `MonCotiz` | float | Cotización vs peso. `1.0` para `PES`. Para otras monedas usar cotización del día. |

### 2.2 Campos opcionales más usados

| Campo | Tipo | Cuándo |
|---|---|---|
| `Production` | bool | `true` para emitir contra producción AFIP. **Default `false` (homologación)**. |
| `custom_cuit` | string | Solo usuarios admin. Permite emitir en nombre de otro negocio. Normalmente NO se manda. |
| `CbteFch` | int | Fecha del comprobante en formato `YYYYMMDD` (entero). Si se omite, AFIP usa la fecha del día. |
| `CondicionIVAReceptorId` | int | Condición IVA del receptor. **OBLIGATORIO desde 2024 en muchos casos.** Ver `/condiciones-iva`. |
| `CanMisMonExt` | str | `"N"` por defecto. |
| `Iva` | array | Detalle de alícuotas IVA — ver sección 2.4. |
| `Tributos` | array | Detalle de tributos no-IVA — ver sección 2.5. |
| `CbtesAsoc` | array | **Obligatorio para Notas de Crédito y Débito** — referencia al comprobante original. |
| `Opcionales` | array | Campos auxiliares varios. Ver `/tipos-opcion`. |
| `FchServDesde` | int | `YYYYMMDD`. **Obligatorio si `Concepto=2` o `3`**. |
| `FchServHasta` | int | `YYYYMMDD`. **Obligatorio si `Concepto=2` o `3`**. |
| `FchVtoPago` | int | `YYYYMMDD`. **Obligatorio si `Concepto=2` o `3`**. |

### 2.3 Reglas duras de AFIP

- **Sumas exactas**: AFIP rechaza si `ImpTotal != ImpTotConc + ImpNeto + ImpOpEx + ImpIVA + ImpTrib` (tolerancia ~ 0.01).
- **Suma de alícuotas IVA**: `sum(Iva[].Importe) == ImpIVA` (mismo redondeo).
- **Suma de bases**: `sum(Iva[].BaseImp) == ImpNeto` (en Factura A/B).
- **Factura C** (`CbteTipo=11`): no se discrimina IVA. Mandar `Iva=null`, `ImpIVA=0`, e `ImpNeto = total gravado` (sin separar IVA).
- **Consumidor final**: `DocTipo=99`, `DocNro=0`, importe limite ~ AR$ — depende del año.
- **Redondeo**: AFIP exige 2 decimales. Usar `round(x, 2)` antes de enviar — diferencias de centavos rompen.

### 2.4 Estructura `Iva`

Lista de alícuotas aplicadas. Si Factura A/B con IVA, **obligatorio**.

```json
"Iva": [
  { "Id": 5, "BaseImp": 100.00, "Importe": 21.00 },
  { "Id": 4, "BaseImp": 50.00, "Importe": 5.25 }
]
```

Mapeo `Id` → alícuota (extraído del cliente existente):
| Id | Alícuota |
|---|---|
| 3 | 0% |
| 4 | 10.5% |
| 5 | 21% |
| 6 | 27% |
| 8 | 5% |
| 9 | 2.5% |

Llamar `/tipos-alicuota` para listado oficial.

### 2.5 Estructura `Tributos`

```json
"Tributos": [
  {
    "Id": 99,
    "Desc": "Impuesto Municipal",
    "BaseImp": 100.00,
    "Alic": 2.5,
    "Importe": 2.50
  }
]
```

`Id` viene de `/tipos-tributo`. Suma de `Tributos[].Importe` debe igualar `ImpTrib`.

### 2.6 Estructura `CbtesAsoc` (Notas de Crédito/Débito)

```json
"CbtesAsoc": [
  { "Tipo": 6, "PtoVta": 1, "Nro": 100 }
]
```

Referencia a la factura original que se está acreditando/debitando.

---

## 3. Ejemplos de body completos

### 3.1 Factura B a Consumidor Final, productos, IVA 21%

```json
{
  "Production": false,
  "CantReg": 1,
  "PtoVta": 1,
  "CbteTipo": 6,
  "Concepto": 1,
  "DocTipo": 99,
  "DocNro": 0,
  "CbteDesde": 1,
  "CbteHasta": 1,
  "CbteFch": 20260515,
  "ImpTotal": 121.00,
  "ImpTotConc": 0,
  "ImpNeto": 100.00,
  "ImpOpEx": 0,
  "ImpIVA": 21.00,
  "ImpTrib": 0,
  "MonId": "PES",
  "MonCotiz": 1.0,
  "CondicionIVAReceptorId": 5,
  "Iva": [
    { "Id": 5, "BaseImp": 100.00, "Importe": 21.00 }
  ]
}
```

### 3.2 Factura A a Responsable Inscripto, IVA mixto (21% + 10.5%)

```json
{
  "Production": false,
  "CantReg": 1,
  "PtoVta": 1,
  "CbteTipo": 1,
  "Concepto": 1,
  "DocTipo": 80,
  "DocNro": 20123456789,
  "CbteDesde": 1,
  "CbteHasta": 1,
  "CbteFch": 20260515,
  "ImpTotal": 176.25,
  "ImpTotConc": 0,
  "ImpNeto": 150.00,
  "ImpOpEx": 0,
  "ImpIVA": 26.25,
  "ImpTrib": 0,
  "MonId": "PES",
  "MonCotiz": 1.0,
  "CondicionIVAReceptorId": 1,
  "Iva": [
    { "Id": 5, "BaseImp": 100.00, "Importe": 21.00 },
    { "Id": 4, "BaseImp": 50.00, "Importe": 5.25 }
  ]
}
```

### 3.3 Factura C (Monotributista, sin IVA discriminado)

```json
{
  "Production": false,
  "CantReg": 1,
  "PtoVta": 1,
  "CbteTipo": 11,
  "Concepto": 1,
  "DocTipo": 96,
  "DocNro": 12345678,
  "CbteDesde": 1,
  "CbteHasta": 1,
  "ImpTotal": 100.00,
  "ImpTotConc": 0,
  "ImpNeto": 100.00,
  "ImpOpEx": 0,
  "ImpIVA": 0,
  "ImpTrib": 0,
  "MonId": "PES",
  "MonCotiz": 1.0,
  "CondicionIVAReceptorId": 5
}
```

### 3.4 Nota de Crédito B (referencia a factura previa)

```json
{
  "Production": false,
  "CantReg": 1,
  "PtoVta": 1,
  "CbteTipo": 8,
  "Concepto": 1,
  "DocTipo": 99,
  "DocNro": 0,
  "CbteDesde": 1,
  "CbteHasta": 1,
  "ImpTotal": 121.00,
  "ImpTotConc": 0,
  "ImpNeto": 100.00,
  "ImpOpEx": 0,
  "ImpIVA": 21.00,
  "ImpTrib": 0,
  "MonId": "PES",
  "MonCotiz": 1.0,
  "CondicionIVAReceptorId": 5,
  "Iva": [{ "Id": 5, "BaseImp": 100.00, "Importe": 21.00 }],
  "CbtesAsoc": [
    { "Tipo": 6, "PtoVta": 1, "Nro": 142 }
  ]
}
```

---

## 4. Cliente de referencia (PHP)

Implementación productiva existente: `sistema-de-gestion/src/clases/servicios/arca_service.class.php`.

Patrón completo (token caching + retry):

```php
class ArcaService {
    private const BASE_URL = 'https://servicios.gesis2.com/';
    private static $access_token = null;
    private static $token_expiry = null;

    private static function login() {
        // POST /api/v1/auth/token
        // cachear access_token + expiry (30 min)
    }

    private static function getValidToken() {
        if (token expirado o nulo) return self::login();
        return self::$access_token;
    }

    public static function crearProximoComprobante($datos, $cuit, $production = false, $retry_count = 0) {
        $token = self::getValidToken();
        $payload = $datos;
        $payload['Production'] = $production;
        $payload['custom_cuit'] = $cuit;   // si el user es admin operando para otro negocio
        $payload['CantReg'] = 1;
        $payload['CbteDesde'] = 1;
        $payload['CbteHasta'] = 1;

        // POST /api/v1/arca/crear-proximo-comprobante

        // Retry logic:
        // - 401 → renovar token + reintentar (1x)
        // - 500/timeout → reintentar con backoff (max 3x, sleep 2/3/4s)
        // - otros → throw
    }
}
```

Errores AFIP conocidos vienen en `result['error']` o `result['errors']` — tratar como excepción de negocio (no reintentar).

---

## 5. Errores comunes

| HTTP | Causa | Acción |
|---|---|---|
| 400 | Datos del comprobante inválidos para AFIP, o cert no encontrado para el CUIT/entorno | Revisar body (sumas, alícuotas, fechas) o subir certificados |
| 401 | Token expirado o inválido | Re-autenticar |
| 403 | Usuario no admin intentando operar para otro CUIT con `custom_cuit` | Crear usuario propio del negocio |
| 422 | Pydantic validation falló — body con tipos incorrectos | Revisar schema (campos requeridos, tipos) |
| 500 | Error AFIP / SOAP / cert vencido / WSDL caído | Revisar logs en server o `/estado-servidor` |

Errores específicos de AFIP (rechazo del comprobante) llegan con status **200** pero con `Observaciones` no vacío y/o `error`/`errors` en el body. **Importante**: tratar respuesta de la API completa, no solo el status code.

---

## 6. Checklist pre-go-live

Confirmar con el admin (antes de codear):

- [ ] Negocio dado de alta con CUIT correcto
- [ ] Certificados AFIP de **producción** subidos
- [ ] Punto de venta habilitado en AFIP
- [ ] Usuario dedicado de integración creado y credenciales recibidas

Del lado del equipo de integración:

- [ ] Token caching implementado (no relogin por request — TTL 30 min)
- [ ] Retry para 500/timeout (backoff) y renovación automática en 401
- [ ] Validación de sumas (`ImpTotal == ImpTotConc + ImpNeto + ImpOpEx + ImpIVA + ImpTrib`) antes de enviar
- [ ] Redondeo a 2 decimales en todos los importes
- [ ] Manejo de respuesta AFIP con `Observaciones` no vacío (puede llegar con HTTP 200)
- [ ] Probar end-to-end en homologación (`Production: false`) primero
- [ ] Recién después flippear a `Production: true`

---

## 7. Soporte

- Swagger interactivo: `https://servicios.gesis2.com/docs`
- Repo: `gesis-factura-electronica` (branch `main`)
- Spec AFIP WSFEv1 oficial: https://www.afip.gob.ar/ws/WSFEV1/manual_desarrollador_wsfev1.pdf
