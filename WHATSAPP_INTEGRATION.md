# Guía de integración — WhatsApp

Documento para integrar el servicio de **WhatsApp** de `gesis-services` desde un sistema cliente. Está pensado para equipos que **ya integran Facturación Electrónica (FE)** con este mismo servicio y quieren sumar el envío y la recepción de mensajes de WhatsApp.

**URL producción:** `https://servicios.gesis2.com`

El servicio expone una API REST que abstrae el proveedor de WhatsApp: vos hablás siempre con `gesis-services`, nunca con el proveedor directamente. La autenticación es el **mismo JWT** que ya usás para FE.

> **Prerrequisitos**
> 1. El negocio tiene que estar dado de alta en el sistema (igual que para FE) y el servicio de WhatsApp habilitado para ese negocio. Eso lo gestiona el admin.
> 2. El **CUIT del negocio tiene que ser el mismo en ambos sistemas** (el tuyo y el de `gesis-services`). Es el identificador compartido que usamos para vincular todo — sobre todo en la pasarela de webhooks (sección 4).

---

## 0. Conceptos clave

- **Emisor**: el número de WhatsApp con el que opera un negocio. Cada negocio tiene su emisor.
- **Estados del emisor**: `pending` → `connecting` → `connected`. También `disconnected` (se cerró la sesión) y `banned`.
- **Vinculación por QR**: para conectar el número, el dueño escanea un QR con su teléfono (WhatsApp → Dispositivos vinculados). El QR es **asíncrono y se renueva cada ~20-60s**, así que se **consume por polling** (sección 2.2).
- **Pasarela de eventos (webhook saliente)**: cuando entra un mensaje o cambia el estado de un envío, te lo reenviamos **firmado** a una URL que vos registres (sección 4).

---

## 1. Autenticación

Idéntica a FE. Login con email/password → JWT.

```http
POST /api/v1/auth/token
Content-Type: application/json

{ "email": "sistemax@empresa.com", "password": "<password>" }
```

```json
{ "access_token": "eyJ...", "token_type": "bearer" }
```

- TTL del token: **30 minutos**. Renovalo antes de que expire.
- Header en todas las requests siguientes: `Authorization: Bearer {access_token}`.
- Si recibís `401`, re-autenticate y reintentá.

**Dos modos de operar** (igual que FE):
- **Usuario del negocio**: el JWT ya identifica al negocio → no hace falta nada más.
- **Cuenta admin**: pasás `?custom_cuit=<CUIT>` (query param) para operar en nombre de un negocio. Solo funciona con usuarios admin; para usuarios normales se ignora y se usa su propio negocio.

> En los ejemplos de abajo, `?custom_cuit=...` es opcional — solo lo necesitás si operás con la cuenta admin.

---

## 2. Onboarding: conectar el número (QR)

Flujo de una sola vez por negocio: registrar el emisor → mostrar el QR → el dueño lo escanea → queda `connected`.

### 2.1 Registrar el emisor

```http
POST /api/v1/whatsapp/emisores
Authorization: Bearer {token}
Content-Type: application/json

{ "provider": "evolution", "phone_number": "5493541234567", "display_name": "Mi Negocio" }
```

Respuesta:
```json
{ "id": 1, "provider": "evolution", "phone_number": "5493541234567",
  "display_name": "Mi Negocio", "status": "pending", "created_at": "2026-06-13T20:00:00" }
```

- `phone_number` en formato internacional sin `+` ni espacios.
- Es metadata/etiqueta — el número real que queda vinculado es el que escanee el QR.

### 2.2 Iniciar la conexión y obtener el QR (polling)

Para arrancar la sesión:

```http
POST /api/v1/whatsapp/emisores/conectar?custom_cuit={CUIT}
Authorization: Bearer {token}
```

```json
{ "status": "connecting", "qr_base64": "iVBORw0KGgoAAAANSUhEUg..." }
```

`qr_base64` es un **PNG en base64 (sin el prefijo `data:image/png;base64,`)**. Para mostrarlo en HTML:
```html
<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUg..." />
```

**El QR expira y se renueva.** No lo muestres una sola vez: hacé **polling** de este endpoint cada ~3 segundos y refrescá la imagen, hasta que el estado pase a `connected`:

```http
GET /api/v1/whatsapp/emisores/qr?custom_cuit={CUIT}
Authorization: Bearer {token}
```

```json
{ "status": "connecting", "qr_base64": "iVBOR..." }
```

Cuando el dueño escanea, la respuesta pasa a:
```json
{ "status": "connected", "qr_base64": null }
```
→ ahí frenás el polling y mostrás "Conectado".

> **Importante para el front:** el navegador no debe llamar a `gesis-services` directo con el JWT. Hacé que tu backend exponga un endpoint proxy (ej. `obtener_qr`) que llame a `gesis-services` con el token del lado servidor, y que el front haga polling a ese proxy.

### 2.3 Instrucción para el usuario final (el dueño del número)

> En el teléfono: **WhatsApp → Ajustes → Dispositivos vinculados → Vincular un dispositivo** → escanear el QR en pantalla.

### 2.4 Confirmar el estado de conexión

```http
GET /api/v1/whatsapp/emisores/estado?custom_cuit={CUIT}
Authorization: Bearer {token}
```

```json
{ "id_emisor": 1, "status": "connected", "provider": "evolution" }
```

---

## 3. Enviar mensajes

Una vez `connected`, podés enviar.

### 3.1 Texto

```http
POST /api/v1/whatsapp/enviar-mensaje?custom_cuit={CUIT}
Authorization: Bearer {token}
Content-Type: application/json

{ "to": "5493541112233", "text": "Hola! Tu factura está lista." }
```

Respuesta:
```json
{ "id": 42, "status": "sent", "provider_message_id": "3EB0FD93F585E6A2D1C1D6",
  "created_at": "2026-06-13T20:05:00" }
```

### 3.2 Media (imagen / documento)

```http
POST /api/v1/whatsapp/enviar-mensaje?custom_cuit={CUIT}
Content-Type: application/json

{ "to": "5493541112233", "text": "Adjunto tu comprobante",
  "media_url": "https://tu-server.com/facturas/0001.pdf",
  "media_type": "document", "caption": "Factura 0001" }
```

- `media_url`: URL pública del archivo.
- `media_type` (opcional): `image` | `document` | `video` | `audio`. Si no lo mandás, se infiere de la extensión.

### 3.3 Consultar el estado de un mensaje

```http
GET /api/v1/whatsapp/mensajes/{id}
Authorization: Bearer {token}
```

```json
{ "id": 42, "status": "delivered", "provider_message_id": "3EB0...",
  "created_at": "2026-06-13T20:05:00" }
```

El histórico paginado: `GET /api/v1/whatsapp/mensajes?skip=0&limit=50`.

> Los acuses de entrega/lectura (`delivered` / `read`) llegan de forma **asíncrona** vía la pasarela de webhooks (sección 4) — no esperes que el `POST /enviar-mensaje` ya devuelva `delivered`. El POST devuelve `sent` (aceptado por el proveedor); el resto del ciclo llega por webhook.

---

## 4. Recibir eventos: la pasarela de webhooks

Para recibir **mensajes entrantes** y **cambios de estado** (acuses, reconexiones), registrás una URL de callback. Nosotros te POSTeamos ahí cada evento, **firmado con HMAC** para que verifiques que viene de nosotros.

### 4.1 Registrar tu callback

```http
POST /api/v1/whatsapp/callbacks?custom_cuit={CUIT}
Authorization: Bearer {token}
Content-Type: application/json

{ "url": "https://tu-server.com/webhooks/whatsapp",
  "secret": "un-secreto-largo-de-al-menos-32-caracteres",
  "events": "inbound,status,session" }
```

- **`url`**: tiene que ser una URL **pública `http`/`https`**. Por seguridad (anti-SSRF) **se rechazan** destinos internos: `localhost`, IPs privadas (`10.x`, `172.16-31.x`, `192.168.x`), loopback y link-local.
- **`secret`**: lo elegís vos, **mínimo 32 caracteres**. Guardalo de tu lado — lo vas a usar para verificar la firma. Lo almacenamos cifrado.
- **`events`**: lista separada por comas. Valores: `inbound`, `status`, `session`. Vacío o `*` = todos.

### 4.2 Qué te mandamos

Para cada evento te hacemos un `POST` a tu URL:

```http
POST https://tu-server.com/webhooks/whatsapp
Content-Type: application/json
X-Gesis-Signature: 9a1f...   (hex)

{
  "event": "inbound",
  "id_negocio": 15,
  "cuit": "20111111111",
  "emisor": { "id": 1, "phone_number": "5493541234567" },
  "data": { ... },
  "timestamp": "2026-06-13T20:10:00.123456+00:00"
}
```

El contenido de `data` depende del `event`:

| `event` | `data` |
|---|---|
| `inbound` (mensaje entrante) | `{ "from": "549...", "text": "...", "provider_message_id": "...", "type": "text" }` |
| `status` (acuse de un envío) | `{ "provider_message_id": "...", "status": "delivered" }` |
| `session` (estado de conexión) | `{ "status": "connected" }` |

> **Matcheá por `cuit`, NO por `id_negocio`.** El `id_negocio` del payload es **nuestro ID interno** y no significa nada en tu base. Resolvé tu negocio local por **CUIT** (`payload["cuit"]`), que es el identificador compartido.

### 4.3 Verificar la firma (HMAC-SHA256)

La firma es `HMAC-SHA256(cuerpo_crudo, secret)` en hex, en el header `X-Gesis-Signature`.

> **El error más común:** verificar contra el JSON re-serializado. **Tenés que firmar contra el cuerpo crudo** (los bytes exactos que recibís). Si hacés `decode` y volvés a `encode`, los bytes cambian y la firma no coincide.

Ejemplo en PHP:
```php
$raw = file_get_contents('php://input');                 // bytes EXACTOS — NO decodificar antes
$sig = $_SERVER['HTTP_X_GESIS_SIGNATURE'] ?? '';
$esperada = hash_hmac('sha256', $raw, $callback_secret);  // el MISMO secret que registraste
if (!hash_equals($esperada, $sig)) {
    http_response_code(401);
    exit;
}
$evento = json_decode($raw, true);   // recién ACÁ, después de verificar
// procesar $evento['event'], $evento['data'], $evento['cuit'], ...
http_response_code(200);
```

Ejemplo en Node:
```js
const crypto = require('crypto');
const esperada = crypto.createHmac('sha256', callbackSecret).update(rawBody).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(esperada), Buffer.from(sig))) return res.status(401).end();
```

### 4.4 Reglas de la pasarela

- **Respondé `2xx` rápido** (menos de ~8s). Si tu endpoint está caído o tarda, el evento se pierde: **hoy no hay reintentos** (está previsto para una próxima versión). Procesá de forma idempotente usando `provider_message_id`.
- El evento llega **un instante después** del hecho real (es fire-and-forget), no en la misma request del envío.

---

## 5. Referencia de endpoints

Todos bajo `https://servicios.gesis2.com`, con `Authorization: Bearer {token}`. `custom_cuit` es query param y solo aplica a cuentas admin.

| Método | Path | Descripción |
|---|---|---|
| POST | `/api/v1/auth/token` | Login → JWT |
| POST | `/api/v1/whatsapp/emisores` | Registrar el emisor del negocio |
| POST | `/api/v1/whatsapp/emisores/conectar` | Iniciar sesión → `{status, qr_base64}` |
| GET | `/api/v1/whatsapp/emisores/qr` | QR actual + estado (**pollear**) |
| GET | `/api/v1/whatsapp/emisores/estado` | Estado de conexión del emisor |
| POST | `/api/v1/whatsapp/enviar-mensaje` | Enviar texto o media |
| GET | `/api/v1/whatsapp/mensajes/{id}` | Estado/detalle de un mensaje |
| GET | `/api/v1/whatsapp/mensajes` | Histórico paginado (`?skip=&limit=`) |
| POST | `/api/v1/whatsapp/callbacks` | Registrar la URL de callback (webhook) |

---

## 6. Estados

**Emisor:** `pending` (registrado, sin conectar) · `connecting` (esperando el escaneo del QR) · `connected` (operativo) · `disconnected` (se cerró la sesión, hay que reconectar) · `banned`.

**Mensaje saliente:** `queued` → `sent` (aceptado por el proveedor) → `delivered` (entregado al dispositivo) → `read` (leído). `failed` si falló.

**Mensaje entrante:** `received`.

---

## 7. Notas importantes

- **CUIT consistente entre sistemas.** El negocio tiene que tener el mismo CUIT en tu sistema y en `gesis-services`. La pasarela resuelve el negocio por CUIT.
- **El QR es efímero** → consumilo por polling de `GET /emisores/qr`, nunca como una sola imagen estática.
- **Webhook**: verificá la firma contra el **cuerpo crudo**, matcheá por **CUIT**, respondé **2xx rápido**, sé idempotente por `provider_message_id`.
- **Reconexión**: si el emisor pasa a `disconnected` (te llega un evento `session`), repetí el flujo de la sección 2 (conectar + QR) para volver a vincular.
- **No satures**: aplicá un ritmo razonable de envíos por número. Mandar a alta velocidad puede degradar la entrega.
- Si un endpoint devuelve `400`, el `detail` del cuerpo explica el motivo (ej. "El usuario no tiene un negocio asociado", "No hay un emisor de WhatsApp configurado para el negocio").

---

## 8. Quickstart (curl)

El camino feliz de punta a punta. Reemplazá las credenciales y la URL del callback.

```bash
BASE=https://servicios.gesis2.com

# 1. Login → JWT
TOKEN=$(curl -s -X POST $BASE/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"sistema@empresa.com","password":"<password>"}' | jq -r .access_token)

# 2. Registrar el emisor (una vez por negocio)
curl -s -X POST $BASE/api/v1/whatsapp/emisores \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"provider":"evolution","phone_number":"5493541234567","display_name":"Mi Negocio"}'

# 3. Iniciar la conexión (arranca la sesión)
curl -s -X POST $BASE/api/v1/whatsapp/emisores/conectar -H "Authorization: Bearer $TOKEN"

# 4. Pollear el QR hasta que status sea "connected" (repetir cada ~3s)
curl -s $BASE/api/v1/whatsapp/emisores/qr -H "Authorization: Bearer $TOKEN"
#   -> mostrá qr_base64 como imagen; cuando devuelva {"status":"connected","qr_base64":null} frenás

# 5. Registrar tu webhook (para recibir entrantes y acuses)
curl -s -X POST $BASE/api/v1/whatsapp/callbacks \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"url":"https://tu-server.com/webhooks/whatsapp","secret":"un-secreto-de-32-o-mas-caracteres","events":"inbound,status,session"}'

# 6. Enviar un mensaje
curl -s -X POST $BASE/api/v1/whatsapp/enviar-mensaje \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"to":"5493541112233","text":"Hola! Tu factura está lista."}'
```

---

## 9. Implementación de referencia (PHP)

Cliente HTTP con cache del JWT y reintento en 401, en el mismo estilo que un `ServicioArca` con `curl`. Si operás con cuenta admin, agregá `?custom_cuit=<CUIT>` a los paths.

```php
<?php
class ServicioWhatsApp
{
    const BASE_URL = 'https://servicios.gesis2.com';
    const LOGIN_EMAIL = 'sistema@empresa.com';
    const LOGIN_PASSWORD = '<password>';

    private static $token = null;
    private static $tokenExp = 0;

    private static function token(): string
    {
        if (self::$token !== null && time() < self::$tokenExp) return self::$token;
        $r = self::http('POST', '/api/v1/auth/token',
            ['email' => self::LOGIN_EMAIL, 'password' => self::LOGIN_PASSWORD], false);
        self::$token = $r['body']['access_token'] ?? null;
        self::$tokenExp = time() + 25 * 60;   // TTL real 30 min; renovamos a los 25
        return self::$token;
    }

    /** Helper curl. $auth agrega el Bearer; reintenta UNA vez ante 401. */
    private static function http(string $method, string $path, ?array $body = null, bool $auth = true, bool $retry = true): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        if ($auth) $headers[] = 'Authorization: Bearer ' . self::token();
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 401 && $auth && $retry) {   // token vencido → re-login y reintento
            self::$token = null;
            return self::http($method, $path, $body, true, false);
        }
        return ['code' => $code, 'body' => json_decode($raw, true)];
    }

    public static function registrarEmisor($phone, $displayName)
    { return self::http('POST', '/api/v1/whatsapp/emisores',
        ['provider' => 'evolution', 'phone_number' => $phone, 'display_name' => $displayName]); }

    public static function conectar()  { return self::http('POST', '/api/v1/whatsapp/emisores/conectar'); }
    public static function obtenerQr() { return self::http('GET',  '/api/v1/whatsapp/emisores/qr'); }
    public static function estado()    { return self::http('GET',  '/api/v1/whatsapp/emisores/estado'); }

    public static function enviar($to, $text)
    { return self::http('POST', '/api/v1/whatsapp/enviar-mensaje', ['to' => $to, 'text' => $text]); }

    public static function registrarCallback($url, $secret, $events = 'inbound,status,session')
    { return self::http('POST', '/api/v1/whatsapp/callbacks',
        ['url' => $url, 'secret' => $secret, 'events' => $events]); }
}
```

El receptor del webhook (verificación de firma contra el cuerpo crudo) está en la sección 4.3.

---

## 10. Códigos de respuesta

| Código | Significado | Qué hacer |
|---|---|---|
| `200` | OK | — |
| `400` | Error de dominio | Leer `detail` (ej. emisor no configurado, negocio sin asociar) |
| `401` | Token inválido o vencido | Re-autenticar y reintentar |
| `403` | Recurso de otro negocio | No accedas a mensajes/emisores que no son del negocio del token |
| `404` | No encontrado | El recurso (ej. mensaje) no existe |
| `422` | Validación del body | Revisar el body (ej. `secret` con menos de 32 caracteres) |
| `500` | Error interno / proveedor | Reintentar con backoff; si persiste, avisar a soporte |

---

## 11. Preguntas frecuentes / troubleshooting

**Mi webhook devuelve 401 y no me llegan los eventos.**
Tres causas casi siempre: (1) estás verificando la firma contra el JSON re-serializado en vez del **cuerpo crudo** (`php://input`); (2) estás resolviendo el negocio por `id_negocio` en vez de por **`cuit`**; (3) el `secret` que usás para verificar no es el mismo que registraste.

**`qr_base64` viene `null` pero el estado no es `connected`.**
El QR es asíncrono: seguí poleando `GET /emisores/qr` cada ~3s. Si después de ~30s sigue `null`, volvé a llamar a `POST /emisores/conectar` para regenerar la sesión.

**`POST /enviar-mensaje` me da 400 "No hay un emisor configurado".**
Falta el onboarding: registrá el emisor (2.1) y conectá el número por QR (2.2) antes de enviar.

**El mensaje queda en `sent` y nunca pasa a `delivered`.**
Los acuses llegan **por webhook** (evento `status`). Si no registraste un callback (sección 4), no vas a ver el cambio de estado — el envío igual se hizo.

**`POST /callbacks` me da 422.**
El `secret` tiene que ser de **32 caracteres o más**. Y la `url` no puede ser interna (`localhost`, IP privada): tiene que ser pública.

**El número se desconectó solo (me llegó un evento `session` con `disconnected`).**
Puede pasar por inactividad, por cerrar la sesión desde el teléfono, o por baja del proveedor. Reconectá repitiendo el flujo de la sección 2 (conectar + escanear QR de nuevo).

**¿Puedo tener varios negocios bajo una sola cuenta?**
Sí, con una cuenta **admin** y pasando `?custom_cuit=<CUIT>` en cada llamada. Con un usuario común, el negocio sale del token y no hace falta `custom_cuit`.
