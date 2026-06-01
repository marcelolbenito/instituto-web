<?php
declare(strict_types=1);

/**
 * Cliente HTTP mínimo para Gesis factura electrónica (DEV_INTEGRATION.md).
 */
final class GesisArcaClient
{
    private string $baseUrl;
    private string $email;
    private string $password;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    public function __construct(array $gesisConfig)
    {
        $base = rtrim((string) ($gesisConfig['base_url'] ?? 'https://servicios.gesis2.com'), '/');
        $this->baseUrl = $base;
        $this->email = trim((string) ($gesisConfig['email'] ?? ''));
        $this->password = (string) ($gesisConfig['password'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->email !== '' && $this->password !== '';
    }

    /**
     * @param array<string,mixed> $voucherData Campos AFIP (sin Production si se pasa aparte).
     * @return array<string,mixed>
     */
    public function crearProximoComprobante(array $voucherData, bool $production = false): array
    {
        $payload = $voucherData;
        $payload['Production'] = $production;
        $payload['CantReg'] = 1;
        $payload['CbteDesde'] = 1;
        $payload['CbteHasta'] = 1;

        return $this->requestJson(
            'POST',
            '/api/v1/arca/crear-proximo-comprobante',
            $payload,
            true
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function requestJson(string $method, string $path, ?array $body, bool $retryOn401): array
    {
        $token = $this->getValidToken();
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => 90,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts['http']['header'] = implode("\r\n", $headers);
            $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('No se pudo conectar con Gesis (' . $path . ').');
        }

        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $m)) {
            $code = (int) $m[0];
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta inválida de Gesis (HTTP ' . $code . ').');
        }

        if ($code === 401 && $retryOn401) {
            $this->accessToken = null;
            $this->tokenExpiry = 0;

            return $this->requestJson($method, $path, $body, false);
        }

        if ($code >= 400) {
            $msg = fe_gesis_extraer_error($decoded);
            throw new RuntimeException($msg !== '' ? $msg : ('Error Gesis HTTP ' . $code));
        }

        if (!empty($decoded['error']) || !empty($decoded['errors'])) {
            $msg = fe_gesis_extraer_error($decoded);
            throw new RuntimeException($msg !== '' ? $msg : 'AFIP rechazó el comprobante.');
        }

        return $decoded;
    }

    private function getValidToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }
        $this->login();

        return (string) $this->accessToken;
    }

    private function login(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Gesis no configurado: complete los datos en Utilitarios → Factura electrónica (parámetros).'
            );
        }

        $url = $this->baseUrl . '/api/v1/auth/token';
        $body = json_encode([
            'email' => $this->email,
            'password' => $this->password,
        ], JSON_THROW_ON_ERROR);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('No se pudo autenticar en Gesis.');
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException('Credenciales Gesis inválidas o respuesta inesperada.');
        }

        $this->accessToken = (string) $decoded['access_token'];
        $this->tokenExpiry = time() + 29 * 60;
    }
}

/**
 * @param array<string,mixed> $decoded
 */
function fe_gesis_extraer_error(array $decoded): string
{
    if (!empty($decoded['detail']) && is_string($decoded['detail'])) {
        return $decoded['detail'];
    }
    if (!empty($decoded['message']) && is_string($decoded['message'])) {
        return $decoded['message'];
    }
    if (!empty($decoded['error']) && is_string($decoded['error'])) {
        return $decoded['error'];
    }
    if (!empty($decoded['errors'])) {
        if (is_string($decoded['errors'])) {
            return $decoded['errors'];
        }
        if (is_array($decoded['errors'])) {
            return implode('; ', array_map('strval', $decoded['errors']));
        }
    }
    if (!empty($decoded['Observaciones']) && is_array($decoded['Observaciones'])) {
        return implode('; ', array_map('strval', $decoded['Observaciones']));
    }

    return '';
}
