<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

function fe_parametros_tabla_ok(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT 1 FROM parametros_factura_electronica LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/**
 * @return array<string,mixed>|null
 */
function fe_parametros_cargar(PDO $pdo): ?array
{
    if (!fe_parametros_tabla_ok($pdo)) {
        return null;
    }
    $row = $pdo->query('SELECT * FROM parametros_factura_electronica WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->exec('INSERT IGNORE INTO parametros_factura_electronica (id) VALUES (1)');
        $row = $pdo->query('SELECT * FROM parametros_factura_electronica WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }

    return $row ?: null;
}

/**
 * Config efectiva para Gesis: primero BD (parametros_factura_electronica), luego config.php.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function fe_gesis_config(array $config, ?PDO $pdo = null): array
{
    $defaults = [
        'base_url' => 'https://servicios.gesis2.com',
        'email' => '',
        'password' => '',
        'cuit_emisor' => null,
        'production' => false,
        'punto_venta' => 1,
        'cbte_tipo' => 11,
        'concepto' => 2,
    ];

    $g = $config['gesis'] ?? [];
    if (is_array($g)) {
        if (!empty($g['base_url'])) {
            $defaults['base_url'] = (string) $g['base_url'];
        }
        if (!empty($g['email'])) {
            $defaults['email'] = (string) $g['email'];
        }
        if (!empty($g['password'])) {
            $defaults['password'] = (string) $g['password'];
        }
        if (isset($g['cuit_emisor']) && $g['cuit_emisor'] !== '') {
            $defaults['cuit_emisor'] = normalize_cuit((string) $g['cuit_emisor']);
        }
        if (isset($g['production'])) {
            $defaults['production'] = (bool) $g['production'];
        }
        if (isset($g['punto_venta'])) {
            $defaults['punto_venta'] = (int) $g['punto_venta'];
        }
        if (isset($g['cbte_tipo'])) {
            $defaults['cbte_tipo'] = (int) $g['cbte_tipo'];
        }
        if (isset($g['concepto'])) {
            $defaults['concepto'] = (int) $g['concepto'];
        }
    }

    if ($pdo !== null) {
        $row = fe_parametros_cargar($pdo);
        if ($row !== null) {
            $url = trim((string) ($row['gesis_url'] ?? ''));
            if ($url !== '') {
                $defaults['base_url'] = $url;
            }
            $email = trim((string) ($row['gesis_email'] ?? ''));
            if ($email !== '') {
                $defaults['email'] = $email;
            }
            $pass = (string) ($row['gesis_password'] ?? '');
            if ($pass !== '') {
                $defaults['password'] = $pass;
            }
            $cuit = normalize_cuit($row['cuit_emisor'] ?? null);
            if ($cuit !== null) {
                $defaults['cuit_emisor'] = $cuit;
            }
            $defaults['production'] = !empty($row['production']);
            $defaults['punto_venta'] = max(1, (int) ($row['punto_venta'] ?? 1));
            $defaults['cbte_tipo'] = max(1, (int) ($row['cbte_tipo'] ?? 11));
            $defaults['concepto'] = max(1, min(3, (int) ($row['concepto'] ?? 2)));
        }
    }

    return $defaults;
}

function fe_emisor_tabla_extendida_ok(PDO $pdo): bool
{
    return fe_parametros_tabla_ok($pdo)
        && db_has_column($pdo, 'parametros_factura_electronica', 'razon_social');
}

/**
 * Datos del emisor para impresión ARCA (desde parámetros o fallback app).
 *
 * @param array<string,mixed>|null $row
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function fe_emisor_desde_fila(?array $row, array $config): array
{
    $fallbackNombre = trim((string) ($config['app']['name'] ?? 'Instituto'));
    $cuit = $row !== null ? normalize_cuit($row['cuit_emisor'] ?? null) : null;

    if ($row === null || !isset($row['razon_social'])) {
        return [
            'razon_social' => $fallbackNombre,
            'nombre_fantasia' => '',
            'domicilio_comercial' => '',
            'localidad' => '',
            'provincia' => '',
            'codigo_postal' => '',
            'telefono' => '',
            'email_contacto' => '',
            'condicion_iva_emisor' => 'monotributo',
            'inicio_actividades' => '',
            'ingresos_brutos' => '',
            'jurisdiccion_iibb' => '',
            'actividad_principal' => '',
            'cuit_emisor' => $cuit,
        ];
    }

    $inicio = $row['inicio_actividades'] ?? null;
    $inicioStr = $inicio !== null && $inicio !== '' ? (string) $inicio : '';

    return [
        'razon_social' => trim((string) ($row['razon_social'] ?? '')) ?: $fallbackNombre,
        'nombre_fantasia' => trim((string) ($row['nombre_fantasia'] ?? '')),
        'domicilio_comercial' => trim((string) ($row['domicilio_comercial'] ?? '')),
        'localidad' => trim((string) ($row['localidad'] ?? '')),
        'provincia' => trim((string) ($row['provincia'] ?? '')),
        'codigo_postal' => trim((string) ($row['codigo_postal'] ?? '')),
        'telefono' => trim((string) ($row['telefono'] ?? '')),
        'email_contacto' => trim((string) ($row['email_contacto'] ?? '')),
        'condicion_iva_emisor' => (string) ($row['condicion_iva_emisor'] ?? 'monotributo'),
        'inicio_actividades' => $inicioStr,
        'ingresos_brutos' => trim((string) ($row['ingresos_brutos'] ?? '')),
        'jurisdiccion_iibb' => trim((string) ($row['jurisdiccion_iibb'] ?? '')),
        'actividad_principal' => trim((string) ($row['actividad_principal'] ?? '')),
        'cuit_emisor' => $cuit,
    ];
}

/**
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function fe_emisor_cargar(PDO $pdo, array $config): array
{
    return fe_emisor_desde_fila(fe_parametros_cargar($pdo), $config);
}

function fe_condicion_iva_emisor_etiqueta(string $condicion): string
{
    switch ($condicion) {
        case 'responsable_inscripto':
            return 'IVA Responsable Inscripto';
        case 'monotributo':
            return 'IVA Responsable Monotributo';
        case 'exento':
            return 'IVA Sujeto Exento';
        case 'no_inscripto':
            return 'IVA No Responsable';
        default:
            return 'IVA Responsable Monotributo';
    }
}

/**
 * @param array<string,mixed> $emisor
 */
function fe_emisor_domicilio_linea(array $emisor): string
{
    $calle = trim((string) ($emisor['domicilio_comercial'] ?? ''));
    $loc = trim((string) ($emisor['localidad'] ?? ''));
    $prov = trim((string) ($emisor['provincia'] ?? ''));
    $cp = trim((string) ($emisor['codigo_postal'] ?? ''));

    $partes = [];
    if ($calle !== '') {
        $partes[] = $calle;
    }
    $ciudad = $loc;
    if ($cp !== '') {
        $ciudad = ($ciudad !== '' ? $ciudad . ' ' : '') . '(' . $cp . ')';
    }
    if ($ciudad !== '') {
        $partes[] = $ciudad;
    }
    if ($prov !== '') {
        $partes[] = $prov;
    }

    return implode(' — ', $partes);
}

/**
 * Campos obligatorios para impresión según práctica AFIP/ARCA (RG 4291 y comprobantes en papel).
 *
 * @param array<string,mixed> $emisor
 * @return list<string>
 */
function fe_emisor_campos_faltantes_impresion(array $emisor): array
{
    $faltan = [];
    if (trim((string) ($emisor['razon_social'] ?? '')) === '') {
        $faltan[] = 'Razón social';
    }
    if (trim((string) ($emisor['domicilio_comercial'] ?? '')) === '') {
        $faltan[] = 'Domicilio comercial';
    }
    $cuit = normalize_cuit($emisor['cuit_emisor'] ?? null);
    if ($cuit === null || !cuit_ok($cuit)) {
        $faltan[] = 'CUIT válido';
    }
    if (trim((string) ($emisor['inicio_actividades'] ?? '')) === '') {
        $faltan[] = 'Fecha de inicio de actividades';
    }
    if (trim((string) ($emisor['ingresos_brutos'] ?? '')) === '') {
        $faltan[] = 'Nº de inscripción en Ingresos Brutos';
    }

    return $faltan;
}

/**
 * @param array<string,mixed> $post
 * @return array{ok:bool,msg:string}
 */
function fe_parametros_guardar(PDO $pdo, array $post): array
{
    if (!fe_parametros_tabla_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Ejecute sql/migracion/31_parametros_factura_electronica_compat.sql'];
    }

    $url = trim((string) ($post['gesis_url'] ?? ''));
    if ($url === '') {
        $url = 'https://servicios.gesis2.com';
    }
    $email = trim((string) ($post['gesis_email'] ?? ''));
    $passNueva = (string) ($post['gesis_password'] ?? '');
    $cuit = normalize_cuit($post['cuit_emisor'] ?? null);

    $pto = max(1, min(9999, (int) ($post['punto_venta'] ?? 1)));
    $cbte = max(1, (int) ($post['cbte_tipo'] ?? 11));
    $concepto = max(1, min(3, (int) ($post['concepto'] ?? 2)));
    $production = !empty($post['production']) ? 1 : 0;
    $obs = trim((string) ($post['observaciones'] ?? ''));

    $actual = fe_parametros_cargar($pdo);
    $passFinal = $passNueva !== '' ? $passNueva : (string) ($actual['gesis_password'] ?? '');
    if ($email === '') {
        return ['ok' => false, 'msg' => 'El email de integración Gesis es obligatorio.'];
    }
    if ($passFinal === '') {
        return ['ok' => false, 'msg' => 'La contraseña Gesis es obligatoria (o deje la ya guardada sin cambiar).'];
    }

    if ($cuit === null || !cuit_ok($cuit)) {
        return ['ok' => false, 'msg' => 'El CUIT del instituto es obligatorio y debe tener 11 dígitos.'];
    }

    $emisorExtendido = fe_emisor_tabla_extendida_ok($pdo);
    $razonSocial = trim((string) ($post['razon_social'] ?? ''));
    $domicilio = trim((string) ($post['domicilio_comercial'] ?? ''));
    $inicioAct = trim((string) ($post['inicio_actividades'] ?? ''));
    $iibb = trim((string) ($post['ingresos_brutos'] ?? ''));

    if ($emisorExtendido) {
        if ($razonSocial === '') {
            return ['ok' => false, 'msg' => 'La razón social del emisor es obligatoria.'];
        }
        if ($domicilio === '') {
            return ['ok' => false, 'msg' => 'El domicilio comercial del emisor es obligatorio.'];
        }
        if ($inicioAct === '') {
            return ['ok' => false, 'msg' => 'La fecha de inicio de actividades es obligatoria.'];
        }
        if ($iibb === '') {
            return ['ok' => false, 'msg' => 'El número de Ingresos Brutos es obligatorio.'];
        }
        $condEmisor = (string) ($post['condicion_iva_emisor'] ?? 'monotributo');
        if (!in_array($condEmisor, ['responsable_inscripto', 'monotributo', 'exento', 'no_inscripto'], true)) {
            return ['ok' => false, 'msg' => 'Condición IVA del emisor inválida.'];
        }
    }

    if ($emisorExtendido) {
        $st = $pdo->prepare(
            'UPDATE parametros_factura_electronica SET
                gesis_url = ?, gesis_email = ?, gesis_password = ?, cuit_emisor = ?,
                razon_social = ?, nombre_fantasia = ?, domicilio_comercial = ?, localidad = ?, provincia = ?,
                codigo_postal = ?, telefono = ?, email_contacto = ?, condicion_iva_emisor = ?,
                inicio_actividades = ?, ingresos_brutos = ?, jurisdiccion_iibb = ?, actividad_principal = ?,
                punto_venta = ?, cbte_tipo = ?, concepto = ?, production = ?, observaciones = ?
             WHERE id = 1'
        );
        $st->execute([
            $url,
            $email,
            $passFinal,
            $cuit,
            $razonSocial,
            trim((string) ($post['nombre_fantasia'] ?? '')) ?: null,
            $domicilio,
            trim((string) ($post['localidad'] ?? '')) ?: null,
            trim((string) ($post['provincia'] ?? '')) ?: null,
            trim((string) ($post['codigo_postal'] ?? '')) ?: null,
            trim((string) ($post['telefono'] ?? '')) ?: null,
            trim((string) ($post['email_contacto'] ?? '')) ?: null,
            $condEmisor,
            $inicioAct,
            $iibb,
            trim((string) ($post['jurisdiccion_iibb'] ?? '')) ?: null,
            trim((string) ($post['actividad_principal'] ?? '')) ?: null,
            $pto,
            $cbte,
            $concepto,
            $production,
            $obs !== '' ? $obs : null,
        ]);
    } else {
        $st = $pdo->prepare(
            'UPDATE parametros_factura_electronica SET
                gesis_url = ?, gesis_email = ?, gesis_password = ?, cuit_emisor = ?,
                punto_venta = ?, cbte_tipo = ?, concepto = ?, production = ?, observaciones = ?
             WHERE id = 1'
        );
        $st->execute([
            $url,
            $email,
            $passFinal,
            $cuit,
            $pto,
            $cbte,
            $concepto,
            $production,
            $obs !== '' ? $obs : null,
        ]);
    }

    return ['ok' => true, 'msg' => 'Parámetros de factura electrónica guardados.'];
}
