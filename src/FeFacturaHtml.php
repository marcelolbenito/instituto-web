<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/ParametrosFe.php';
require_once __DIR__ . '/FacturaElectronica.php';
require_once __DIR__ . '/InstitutoLogo.php';

/**
 * Carga comprobante FE autorizado para impresión (por recibo o por comprobante).
 *
 * @return array{
 *   comprobante: array<string,mixed>,
 *   electronico: array<string,mixed>,
 *   detalle: list<array<string,mixed>>,
 *   alumno: array<string,mixed>,
 *   pago_id: ?int,
 *   voucher: array<string,mixed>,
 *   gesis: array<string,mixed>,
 *   emisor: array<string,mixed>
 * }|null
 */
function fe_impresion_cargar(PDO $pdo, array $config, ?int $pagoId = null, ?int $comprobanteId = null): ?array
{
    if (!fe_schema_ok($pdo)) {
        return null;
    }

    $colDir = db_has_column($pdo, 'alumnos', 'direccion') ? ', a.direccion' : '';

    if ($pagoId !== null && $pagoId > 0) {
        $st = $pdo->prepare(
            'SELECT pr.id AS pago_id, c.*, ce.cae, ce.cae_vencimiento, ce.estado AS fe_estado,
                    ce.request_json, ce.response_json, ce.autorizado_en,
                    a.id AS alumno_id, a.nombre_completo, a.documento, a.cuit, a.condicion_iva' . $colDir . '
             FROM pago_registrado pr
             INNER JOIN comprobante c ON c.id = pr.comprobante_id
             INNER JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
             INNER JOIN alumnos a ON a.id = c.alumno_id
             WHERE pr.id = ? AND ce.estado = \'autorizado\'
             LIMIT 1'
        );
        $st->execute([$pagoId]);
    } elseif ($comprobanteId !== null && $comprobanteId > 0) {
        $st = $pdo->prepare(
            'SELECT pr.id AS pago_id, c.*, ce.cae, ce.cae_vencimiento, ce.estado AS fe_estado,
                    ce.request_json, ce.response_json, ce.autorizado_en,
                    a.id AS alumno_id, a.nombre_completo, a.documento, a.cuit, a.condicion_iva' . $colDir . '
             FROM comprobante c
             INNER JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
             INNER JOIN alumnos a ON a.id = c.alumno_id
             LEFT JOIN pago_registrado pr ON pr.comprobante_id = c.id
             WHERE c.id = ? AND ce.estado = \'autorizado\'
             LIMIT 1'
        );
        $st->execute([$comprobanteId]);
    } else {
        return null;
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $compId = (int) $row['id'];
    $stD = $pdo->prepare(
        'SELECT orden, descripcion, cantidad, precio_unitario, importe_total
         FROM comprobante_detalle WHERE comprobante_id = ? ORDER BY orden'
    );
    $stD->execute([$compId]);
    $detalle = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $voucher = [];
    if (!empty($row['request_json'])) {
        $decoded = json_decode((string) $row['request_json'], true);
        if (is_array($decoded)) {
            $voucher = $decoded;
        }
    }

    $gesis = fe_gesis_config($config, $pdo);
    $electronico = [
        'cae' => (string) ($row['cae'] ?? ''),
        'cae_vencimiento' => (string) ($row['cae_vencimiento'] ?? ''),
        'fe_estado' => (string) ($row['fe_estado'] ?? ''),
        'autorizado_en' => (string) ($row['autorizado_en'] ?? ''),
        'response_json' => (string) ($row['response_json'] ?? ''),
    ];
    unset($row['cae'], $row['cae_vencimiento'], $row['fe_estado'], $row['request_json'], $row['response_json'], $row['autorizado_en']);

    $alumno = [
        'id' => (int) ($row['alumno_id'] ?? 0),
        'nombre_completo' => (string) ($row['nombre_completo'] ?? ''),
        'documento' => (string) ($row['documento'] ?? ''),
        'cuit' => (string) ($row['cuit'] ?? ''),
        'condicion_iva' => (string) ($row['condicion_iva'] ?? 'consumidor_final'),
        'direccion' => (string) ($row['direccion'] ?? ''),
    ];
    unset($row['alumno_id'], $row['nombre_completo'], $row['documento'], $row['cuit'], $row['condicion_iva'], $row['direccion']);

    $pagoIdOut = isset($row['pago_id']) && $row['pago_id'] !== null ? (int) $row['pago_id'] : null;
    unset($row['pago_id']);

    return [
        'comprobante' => $row,
        'electronico' => $electronico,
        'detalle' => $detalle,
        'alumno' => $alumno,
        'pago_id' => $pagoIdOut,
        'voucher' => $voucher,
        'gesis' => $gesis,
        'emisor' => fe_emisor_cargar($pdo, $config),
    ];
}

function fe_format_fecha_afip(?string $yyyymmdd): string
{
    if ($yyyymmdd === null || $yyyymmdd === '') {
        return '';
    }
    $digits = preg_replace('/\D/', '', $yyyymmdd);
    if (strlen($digits) === 8) {
        return substr($digits, 6, 2) . '/' . substr($digits, 4, 2) . '/' . substr($digits, 0, 4);
    }

    $ts = strtotime($yyyymmdd);

    return $ts !== false ? date('d/m/Y', $ts) : '';
}

/**
 * @param int|string|null $afipInt Fecha AFIP yyyymmdd
 */
function fe_format_fecha_afip_int($afipInt): string
{
    if ($afipInt === null || $afipInt === '' || (int) $afipInt <= 0) {
        return '';
    }

    return fe_format_fecha_afip((string) $afipInt);
}

function fe_cbte_tipo_nombre(int $tipoCmp): string
{
    $map = [
        1 => 'Factura A',
        2 => 'Nota de débito A',
        3 => 'Nota de crédito A',
        6 => 'Factura B',
        7 => 'Nota de débito B',
        8 => 'Nota de crédito B',
        11 => 'Factura C',
        12 => 'Nota de débito C',
        13 => 'Nota de crédito C',
    ];

    return $map[$tipoCmp] ?? ('Comprobante tipo ' . $tipoCmp);
}

function fe_condicion_iva_etiqueta(string $condicion): string
{
    switch ($condicion) {
        case 'inscripto':
            return 'IVA Responsable Inscripto';
        case 'exento':
            return 'IVA Sujeto Exento';
        case 'monotributo':
            return 'Responsable Monotributo';
        case 'no_inscripto':
            return 'IVA No Responsable';
        default:
            return 'Consumidor final';
    }
}

function fe_doc_tipo_etiqueta(int $docTipo): string
{
    switch ($docTipo) {
        case 80:
            return 'CUIT';
        case 86:
            return 'CUIL';
        case 96:
            return 'DNI';
        case 99:
            return 'Sin identificar';
        default:
            return 'Documento';
    }
}

function fe_doc_receptor_texto(int $docTipo, int $docNro): string
{
    if ($docTipo === 99 || $docNro <= 0) {
        return 'Sin identificar / Consumidor final';
    }
    $nro = (string) $docNro;
    if ($docTipo === 80 && strlen($nro) === 11) {
        return fe_doc_tipo_etiqueta($docTipo) . ' '
            . substr($nro, 0, 2) . '-' . substr($nro, 2, 8) . '-' . substr($nro, 10, 1);
    }

    return fe_doc_tipo_etiqueta($docTipo) . ' ' . $nro;
}

function fe_moneda_qr(string $monedaDb, ?string $monIdVoucher): string
{
    $m = strtoupper(trim($monIdVoucher !== null && $monIdVoucher !== '' ? $monIdVoucher : $monedaDb));
    if ($m === 'ARS' || $m === 'PES' || $m === '') {
        return 'PES';
    }

    return $m;
}

/**
 * JSON v1 para código QR AFIP/ARCA (RG 4291).
 *
 * @return array<string,mixed>
 */
function fe_qr_payload(
    int $cuitEmisor,
    string $fechaYmd,
    int $ptoVta,
    int $tipoCmp,
    int $nroCmp,
    float $importe,
    string $monedaQr,
    float $cotizacion,
    int $tipoDocRec,
    int $nroDocRec,
    string $cae
): array {
    $codAut = preg_replace('/\D/', '', $cae);
    if ($codAut === '') {
        $codAut = '0';
    }

    return [
        'ver' => 1,
        'fecha' => $fechaYmd,
        'cuit' => $cuitEmisor,
        'ptoVta' => $ptoVta,
        'tipoCmp' => $tipoCmp,
        'nroCmp' => $nroCmp,
        'importe' => round($importe, 2),
        'moneda' => $monedaQr,
        'ctz' => round($cotizacion, 6),
        'tipoDocRec' => $tipoDocRec,
        'nroDocRec' => $nroDocRec,
        'tipoCodAut' => 'E',
        'codAut' => (int) $codAut,
    ];
}

function fe_qr_url_desde_datos(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '';
    }

    return 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode($json);
}

function fe_qr_imagen_url(string $qrUrl, int $size = 160): string
{
    if ($qrUrl === '') {
        return '';
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&data=' . rawurlencode($qrUrl);
}

function fe_formato_pv_numero(int $ptoVta, int $numero): string
{
    return sprintf('%05d-%08d', $ptoVta, $numero);
}

/**
 * @param array{
 *   comprobante: array<string,mixed>,
 *   electronico: array<string,mixed>,
 *   detalle: list<array<string,mixed>>,
 *   alumno: array<string,mixed>,
 *   pago_id: ?int,
 *   voucher: array<string,mixed>,
 *   gesis: array<string,mixed>,
 *   emisor: array<string,mixed>
 * } $datos
 */
function fe_factura_render_html(array $config, array $datos, bool $conToolbar = true, ?PDO $pdo = null): void
{
    $comp = $datos['comprobante'];
    $ce = $datos['electronico'];
    $alumno = $datos['alumno'];
    $voucher = $datos['voucher'];
    $gesis = $datos['gesis'];
    $emisor = $datos['emisor'] ?? fe_emisor_desde_fila(null, $config);

    $pto = (int) ($comp['punto_venta'] ?? 0);
    $numero = (int) ($comp['numero'] ?? 0);
    $letra = (string) ($comp['letra'] ?? 'C');
    $cbteTipo = (int) ($voucher['CbteTipo'] ?? $gesis['cbte_tipo'] ?? 11);
    $tipoNombre = fe_cbte_tipo_nombre($cbteTipo);
    $concepto = (int) ($voucher['Concepto'] ?? $gesis['concepto'] ?? 2);

    $cuitEmisor = normalize_cuit($emisor['cuit_emisor'] ?? $gesis['cuit_emisor'] ?? null);
    if ($cuitEmisor === null) {
        $cuitEmisor = '00000000000';
    }

    $fechaEmision = (string) ($comp['fecha_emision'] ?? '');
    $fechaQr = $fechaEmision !== '' ? date('Y-m-d', strtotime($fechaEmision) ?: time()) : date('Y-m-d');

    $doc = fe_doc_receptor_desde_alumno($alumno);
    if (isset($voucher['DocTipo'])) {
        $doc['doc_tipo'] = (int) $voucher['DocTipo'];
    }
    if (isset($voucher['DocNro'])) {
        $doc['doc_nro'] = (int) $voucher['DocNro'];
    }

    $importe = round((float) ($comp['importe_total'] ?? 0), 2);
    $monedaQr = fe_moneda_qr((string) ($comp['moneda'] ?? 'ARS'), isset($voucher['MonId']) ? (string) $voucher['MonId'] : null);
    $cotiz = isset($voucher['MonCotiz']) ? (float) $voucher['MonCotiz'] : (float) ($comp['cotizacion'] ?? 1);

    $qrPayload = fe_qr_payload(
        (int) $cuitEmisor,
        $fechaQr,
        $pto,
        $cbteTipo,
        $numero,
        $importe,
        $monedaQr,
        $cotiz,
        $doc['doc_tipo'],
        $doc['doc_nro'],
        (string) ($ce['cae'] ?? '')
    );
    $qrUrl = fe_qr_url_desde_datos($qrPayload);
    $qrImg = fe_qr_imagen_url($qrUrl);

    $razonSocial = trim((string) ($emisor['razon_social'] ?? ''));
    $fantasia = trim((string) ($emisor['nombre_fantasia'] ?? ''));
    $domicilioEmisor = fe_emisor_domicilio_linea($emisor);
    $condEmisor = fe_condicion_iva_emisor_etiqueta((string) ($emisor['condicion_iva_emisor'] ?? 'monotributo'));
    $inicioAct = fe_format_fecha_afip((string) ($emisor['inicio_actividades'] ?? ''));
    $iibb = trim((string) ($emisor['ingresos_brutos'] ?? ''));
    $jurisIibb = trim((string) ($emisor['jurisdiccion_iibb'] ?? ''));
    $actividad = trim((string) ($emisor['actividad_principal'] ?? ''));
    $telEmisor = trim((string) ($emisor['telefono'] ?? ''));
    $mailEmisor = trim((string) ($emisor['email_contacto'] ?? ''));

    $faltanEmisor = fe_emisor_campos_faltantes_impresion($emisor);
    $homologacion = empty($gesis['production']);

    $cae = (string) ($ce['cae'] ?? '');
    $caeVto = (string) ($ce['cae_vencimiento'] ?? '');
    if ($caeVto !== '' && preg_match('/^\d{8}$/', preg_replace('/\D/', '', $caeVto))) {
        $d = preg_replace('/\D/', '', $caeVto);
        $caeVto = substr($d, 6, 2) . '/' . substr($d, 4, 2) . '/' . substr($d, 0, 4);
    } elseif ($caeVto !== '') {
        $caeVto = date('d/m/Y', strtotime($caeVto) ?: time());
    }

    $fechaImpresa = $fechaQr !== '' ? date('d/m/Y', strtotime($fechaQr) ?: time()) : '';
    $pvNum = fe_formato_pv_numero($pto, $numero);
    $cuitFmt = strlen((string) $cuitEmisor) === 11
        ? substr((string) $cuitEmisor, 0, 2) . '-' . substr((string) $cuitEmisor, 2, 8) . '-' . substr((string) $cuitEmisor, 10, 1)
        : (string) $cuitEmisor;

    echo '<article class="fe-factura">';
    if ($homologacion) {
        echo '<div class="fe-homo-banner">COMPROBANTE DE HOMOLOGACIÓN — SIN VALIDEZ FISCAL</div>';
    }
    if (count($faltanEmisor) > 0) {
        echo '<div class="fe-warn-banner no-print">Faltan datos del emisor en parámetros: '
            . h(implode(', ', $faltanEmisor))
            . '. <a href="parametros_factura_electronica.php">Completar</a>.</div>';
    }

    if ($pdo !== null) {
        instituto_logo_render_html($pdo, 'instituto-logo-print instituto-logo-fe');
    }

    echo '<p class="fe-original">ORIGINAL</p>';

    echo '<header class="fe-factura-header">';
    echo '<div class="fe-factura-tipo"><span class="fe-letra">' . h($letra) . '</span>';
    echo '<div><strong>' . h($tipoNombre) . '</strong><br>';
    echo '<span class="fe-cod">Cod. ' . h(str_pad((string) $cbteTipo, 3, '0', STR_PAD_LEFT)) . '</span></div></div>';
    echo '<div class="fe-factura-num">';
    echo '<div>Punto de venta: <strong>' . sprintf('%05d', $pto) . '</strong></div>';
    echo '<div>Comp. Nro: <strong>' . sprintf('%08d', $numero) . '</strong></div>';
    echo '<div class="fe-pv-full">Nº <strong>' . h($pvNum) . '</strong></div>';
    echo '<div>Fecha de emisión: <strong>' . h($fechaImpresa) . '</strong></div>';
    echo '</div></header>';

    echo '<div class="fe-dos-columnas">';
    echo '<section class="fe-bloque fe-emisor">';
    echo '<h2>Emisor</h2>';
    echo '<p><strong>' . h($razonSocial) . '</strong></p>';
    if ($fantasia !== '' && strcasecmp($fantasia, $razonSocial) !== 0) {
        echo '<p class="muted">' . h($fantasia) . '</p>';
    }
    if ($domicilioEmisor !== '') {
        echo '<p>Domicilio comercial: ' . h($domicilioEmisor) . '</p>';
    }
    echo '<p>CUIT: <strong>' . h($cuitFmt) . '</strong></p>';
    echo '<p>' . h($condEmisor) . '</p>';
    if ($inicioAct !== '') {
        echo '<p>Inicio de actividades: <strong>' . h($inicioAct) . '</strong></p>';
    }
    if ($iibb !== '') {
        echo '<p>Ingresos Brutos: <strong>' . h($iibb) . '</strong>';
        if ($jurisIibb !== '') {
            echo ' (' . h($jurisIibb) . ')';
        }
        echo '</p>';
    }
    if ($actividad !== '') {
        echo '<p>Actividad: ' . h($actividad) . '</p>';
    }
    if ($telEmisor !== '' || $mailEmisor !== '') {
        echo '<p class="muted">';
        if ($telEmisor !== '') {
            echo 'Tel. ' . h($telEmisor);
        }
        if ($telEmisor !== '' && $mailEmisor !== '') {
            echo ' · ';
        }
        if ($mailEmisor !== '') {
            echo h($mailEmisor);
        }
        echo '</p>';
    }
    echo '</section>';

    echo '<section class="fe-bloque fe-receptor">';
    echo '<h2>Receptor</h2>';
    echo '<p><strong>' . h((string) $alumno['nombre_completo']) . '</strong></p>';
    $dirRec = trim((string) ($alumno['direccion'] ?? ''));
    if ($dirRec !== '') {
        echo '<p>Domicilio: ' . h($dirRec) . '</p>';
    }
    echo '<p>' . h(fe_doc_receptor_texto($doc['doc_tipo'], $doc['doc_nro'])) . '</p>';
    echo '<p>' . h(fe_condicion_iva_etiqueta((string) $alumno['condicion_iva'])) . '</p>';
    if (!empty($datos['pago_id'])) {
        echo '<p class="muted">Recibo de cobro Nº ' . (int) $datos['pago_id'] . '</p>';
    }
    echo '</section>';
    echo '</div>';

    $fServDesde = fe_format_fecha_afip_int($voucher['FchServDesde'] ?? null);
    $fServHasta = fe_format_fecha_afip_int($voucher['FchServHasta'] ?? null);
    $fVtoPago = fe_format_fecha_afip_int($voucher['FchVtoPago'] ?? null);
    if ($concepto >= 2 && ($fServDesde !== '' || $fServHasta !== '')) {
        echo '<p class="fe-periodo"><strong>Período facturado (servicios):</strong> ';
        echo h($fServDesde) . ' al ' . h($fServHasta) . '</p>';
    }
    if ($fVtoPago !== '') {
        echo '<p class="fe-periodo"><strong>Fecha de vto. para el pago:</strong> ' . h($fVtoPago) . '</p>';
    }

    echo '<table class="fe-detalle"><thead><tr>';
    echo '<th>Descripción</th><th class="num">Cant.</th><th class="num">P. unit.</th><th class="num">Importe</th>';
    echo '</tr></thead><tbody>';
    $detalle = $datos['detalle'];
    if (count($detalle) === 0) {
        echo '<tr><td>Servicios educativos / cuotas</td><td class="num">1</td>';
        echo '<td class="num">' . number_format($importe, 2, ',', '.') . '</td>';
        echo '<td class="num">' . number_format($importe, 2, ',', '.') . '</td></tr>';
    } else {
        foreach ($detalle as $ln) {
            $cant = (float) ($ln['cantidad'] ?? 1);
            if ($cant <= 0 || $cant > 9999) {
                $cant = 1.0;
            }
            $pu = (float) ($ln['precio_unitario'] ?? 0);
            $imp = (float) ($ln['importe_total'] ?? 0);
            if ($pu <= 0 && $cant > 0) {
                $pu = $imp / $cant;
            }
            echo '<tr><td>' . h((string) ($ln['descripcion'] ?? '')) . '</td>';
            echo '<td class="num">' . number_format($cant, 2, ',', '.') . '</td>';
            echo '<td class="num">' . number_format($pu, 2, ',', '.') . '</td>';
            echo '<td class="num">' . number_format($imp, 2, ',', '.') . '</td></tr>';
        }
    }
    echo '</tbody></table>';

    echo '<div class="fe-totales">';
    echo '<div class="fe-total-row"><span>Importe total</span>';
    echo '<strong>$ ' . number_format($importe, 2, ',', '.') . '</strong></div>';
    if ((float) ($comp['importe_iva'] ?? 0) > 0) {
        echo '<div class="fe-total-row muted"><span>IVA</span>';
        echo '<span>$ ' . number_format((float) $comp['importe_iva'], 2, ',', '.') . '</span></div>';
    }
    echo '</div>';

    if ($cbteTipo === 11 || ($emisor['condicion_iva_emisor'] ?? '') === 'monotributo') {
        echo '<p class="fe-leyenda-monotributo">El total de este comprobante incluye los tributos '
            . 'nacionales indirectos correspondientes al Régimen Simplificado para Pequeños Contribuyentes '
            . 'de la Ley 24.977 (Monotributo), cuando corresponda.</p>';
    }

    echo '<footer class="fe-cae-qr">';
    echo '<div class="fe-cae">';
    echo '<p><strong>CAE Nº:</strong> ' . h($cae) . '</p>';
    echo '<p><strong>Fecha de vto. CAE:</strong> ' . h($caeVto) . '</p>';
    echo '<p class="fe-leyenda-afip">Comprobante autorizado por AFIP — ARCA. '
        . 'Consulte la validez del comprobante escaneando el código QR.</p>';
    echo '</div>';
    if ($qrImg !== '') {
        echo '<div class="fe-qr">';
        echo '<img src="' . h($qrImg) . '" width="160" height="160" alt="Código QR AFIP">';
        echo '<p class="muted"><a href="' . h($qrUrl) . '" target="_blank" rel="noopener">Ver en AFIP</a></p>';
        echo '</div>';
    }
    echo '</footer>';

    echo '<p class="fe-pie-legal">Comprobante autorizado — Régimen de Emisión de Comprobantes Electrónicos '
        . '(RG AFIP 4291/2018 y modificatorias). CAE otorgado por ARCA/AFIP. '
        . 'Original — ' . h($pvNum) . '.</p>';
    echo '</article>';
}
