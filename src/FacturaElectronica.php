<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/ReciboHtml.php';
require_once __DIR__ . '/GesisArcaClient.php';
require_once __DIR__ . '/ParametrosFe.php';

function fe_schema_ok(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT 1 FROM comprobante LIMIT 1');
        $pdo->query('SELECT 1 FROM comprobante_electronico LIMIT 1');
        $ok = db_has_column($pdo, 'pago_registrado', 'comprobante_id');
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/**
 * @return array{estado:string,comprobante_id:?int,cae:?string,punto_venta:?int,numero:?int,mensaje:?string}
 */
function fe_estado_pago(PDO $pdo, int $pagoId): array
{
    if (!fe_schema_ok($pdo) || $pagoId <= 0) {
        return ['estado' => 'no_disponible', 'comprobante_id' => null, 'cae' => null, 'punto_venta' => null, 'numero' => null, 'mensaje' => null];
    }

    $st = $pdo->prepare(
        'SELECT pr.comprobante_id, c.punto_venta, c.numero, c.letra, ce.estado AS fe_estado, ce.cae, ce.mensaje_error
         FROM pago_registrado pr
         LEFT JOIN comprobante c ON c.id = pr.comprobante_id
         LEFT JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
         WHERE pr.id = ?'
    );
    $st->execute([$pagoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['estado' => 'no_existe', 'comprobante_id' => null, 'cae' => null, 'punto_venta' => null, 'numero' => null, 'mensaje' => null];
    }

    $compId = $row['comprobante_id'] !== null ? (int) $row['comprobante_id'] : null;
    if ($compId === null || $compId <= 0) {
        return ['estado' => 'sin_fe', 'comprobante_id' => null, 'cae' => null, 'punto_venta' => null, 'numero' => null, 'mensaje' => null];
    }

    $feEst = (string) ($row['fe_estado'] ?? '');
    if ($feEst === 'autorizado') {
        return [
            'estado' => 'autorizado',
            'comprobante_id' => $compId,
            'cae' => $row['cae'] !== null ? (string) $row['cae'] : null,
            'punto_venta' => $row['punto_venta'] !== null ? (int) $row['punto_venta'] : null,
            'numero' => $row['numero'] !== null ? (int) $row['numero'] : null,
            'mensaje' => null,
        ];
    }

    return [
        'estado' => $feEst !== '' ? $feEst : 'pendiente',
        'comprobante_id' => $compId,
        'cae' => null,
        'punto_venta' => $row['punto_venta'] !== null ? (int) $row['punto_venta'] : null,
        'numero' => $row['numero'] !== null ? (int) $row['numero'] : null,
        'mensaje' => $row['mensaje_error'] !== null ? (string) $row['mensaje_error'] : null,
    ];
}

function fe_pago_ya_facturado(PDO $pdo, int $pagoId): bool
{
    return fe_estado_pago($pdo, $pagoId)['estado'] === 'autorizado';
}

/** Pagos ficticios de migración Excel (no cobro real en caja). */
function fe_pago_es_migrado_excel(PDO $pdo, array $pago): bool
{
    $medio = strtolower(trim((string) ($pago['medio'] ?? '')));
    if ($medio === 'excel') {
        return true;
    }
    if (
        db_has_column($pdo, 'pago_registrado', 'forma_pago_id')
        && db_has_column($pdo, 'formas_pago', 'codigo')
        && !empty($pago['forma_pago_id'])
    ) {
        $st = $pdo->prepare('SELECT LOWER(TRIM(codigo)) FROM formas_pago WHERE id = ? LIMIT 1');
        $st->execute([(int) $pago['forma_pago_id']]);
        $cod = (string) ($st->fetchColumn() ?: '');

        return $cod === 'excel';
    }

    return false;
}

/** Condición SQL para listados: excluir medio/código excel. */
function fe_sql_excluir_pagos_excel(PDO $pdo): string
{
    $cond = "LOWER(TRIM(COALESCE(pr.medio, ''))) <> 'excel'";
    if (db_has_column($pdo, 'pago_registrado', 'forma_pago_id') && db_has_column($pdo, 'formas_pago', 'codigo')) {
        $cond .= " AND (pr.forma_pago_id IS NULL OR NOT EXISTS (
            SELECT 1 FROM formas_pago fp
            WHERE fp.id = pr.forma_pago_id AND LOWER(TRIM(fp.codigo)) = 'excel'
        ))";
    }

    return $cond;
}

/**
 * @return list<array<string,mixed>>
 */
function fe_buscar_pagos(PDO $pdo, ?int $pagoId, ?int $alumnoId, string $q, int $limit = 40): array
{
    $limit = max(1, min(100, $limit));
    $where = ['1=1', fe_sql_excluir_pagos_excel($pdo)];
    $params = [];

    if ($pagoId !== null && $pagoId > 0) {
        $where[] = 'pr.id = ?';
        $params[] = $pagoId;
    }
    if ($alumnoId !== null && $alumnoId > 0) {
        $where[] = 'pr.alumno_id = ?';
        $params[] = $alumnoId;
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(pr.id = ? OR a.documento LIKE ? OR a.codigo_legacy = ?)';
            $params[] = (int) $q;
            $params[] = '%' . $q . '%';
            $params[] = $q;
        } else {
            $where[] = 'a.nombre_completo LIKE ?';
            $params[] = '%' . $q . '%';
        }
    }

    $sql = 'SELECT pr.id AS pago_id, pr.fecha_pago, pr.importe, pr.comprobante_id,
                   a.id AS alumno_id, a.nombre_completo, a.documento, a.condicion_iva, a.cuit, a.hace_factura,
                   c.punto_venta, c.numero AS comp_numero, c.letra AS comp_letra,
                   ce.estado AS fe_estado, ce.cae
            FROM pago_registrado pr
            INNER JOIN alumnos a ON a.id = pr.alumno_id
            LEFT JOIN comprobante c ON c.id = pr.comprobante_id
            LEFT JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY pr.fecha_pago DESC, pr.id DESC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Líneas de detalle interno (comprobante_detalle) a partir del recibo.
 *
 * @param array{pago:array,alumno:?array,lineas:list,ajustes:list,items:list} $datos
 * @return list<array{descripcion:string,importe_total:float}>
 */
function fe_lineas_desde_recibo(array $datos): array
{
    $pago = $datos['pago'];
    $pagoId = (int) ($pago['id'] ?? 0);
    $lineas = [];

    foreach ($datos['lineas'] as $lr) {
        $per = sprintf('%04d-%02d', (int) $lr['anio'], (int) $lr['mes']);
        $tot = (float) ($lr['importe_aplicado'] ?? 0);
        if ($tot < 0.00001) {
            $tot = (float) ($lr['importe_capital'] ?? 0)
                + (float) ($lr['importe_recargo'] ?? 0)
                + (float) ($lr['importe_beca_perdida'] ?? 0)
                - (float) ($lr['importe_descuento'] ?? 0);
        }
        if ($tot > 0.00001) {
            $lineas[] = ['descripcion' => 'Cuota mensual ' . $per, 'importe_total' => round($tot, 2)];
        }
    }
    foreach ($datos['ajustes'] as $ar) {
        $tot = (float) ($ar['debe'] ?? 0);
        if ($tot > 0.00001) {
            $lineas[] = [
                'descripcion' => trim((string) ($ar['concepto'] ?? 'Obligación')),
                'importe_total' => round($tot, 2),
            ];
        }
    }
    foreach ($datos['items'] as $it) {
        $tot = (float) ($it['importe_total'] ?? 0);
        if ($tot > 0.00001) {
            $lineas[] = [
                'descripcion' => (string) ($it['descripcion'] ?? 'Ítem'),
                'importe_total' => round($tot, 2),
            ];
        }
    }

    $recMora = round((float) ($pago['importe_interes'] ?? 0), 2);
    if ($recMora > 0.00001) {
        $lineas[] = ['descripcion' => 'Recargo por mora', 'importe_total' => $recMora];
    }
    $beca = round((float) ($pago['importe_beca_perdida'] ?? 0), 2);
    if ($beca > 0.00001) {
        $lineas[] = ['descripcion' => 'Diferencia BECA', 'importe_total' => $beca];
    }
    $descMedio = round((float) ($pago['importe_descuento_medio'] ?? 0), 2);
    $descTotal = round((float) ($pago['importe_descuento'] ?? 0), 2);
    $descCuotas = round(max(0, $descTotal - $descMedio), 2);
    if ($descCuotas > 0.00001) {
        $lineas[] = ['descripcion' => 'Descuento (pronto pago)', 'importe_total' => -$descCuotas];
    }
    $recMedio = round((float) ($pago['importe_recargo_medio'] ?? 0), 2);
    if ($recMedio > 0.00001) {
        $lineas[] = ['descripcion' => 'Recargo por forma de pago', 'importe_total' => $recMedio];
    }
    if ($descMedio > 0.00001) {
        $lineas[] = ['descripcion' => 'Descuento en efectivo', 'importe_total' => -$descMedio];
    }

    if (count($lineas) === 0) {
        $lineas[] = [
            'descripcion' => 'Servicios — recibo Nº ' . $pagoId,
            'importe_total' => round((float) ($pago['importe'] ?? 0), 2),
        ];
    }

    return $lineas;
}

function fe_condicion_iva_receptor_id(string $condicion): int
{
    switch ($condicion) {
        case 'inscripto':
            return 1;
        case 'exento':
            return 4;
        case 'monotributo':
            return 6;
        case 'no_inscripto':
            return 5;
        default:
            return 5;
    }
}

/**
 * @param array<string,mixed> $alumno
 * @return array{doc_tipo:int,doc_nro:int}
 */
function fe_doc_receptor_desde_alumno(array $alumno): array
{
    $cond = (string) ($alumno['condicion_iva'] ?? 'consumidor_final');
    $cuit = normalize_cuit($alumno['cuit'] ?? null);

    if (cuit_ok($cuit) && in_array($cond, ['inscripto', 'exento'], true)) {
        return ['doc_tipo' => 80, 'doc_nro' => (int) $cuit];
    }

    $doc = preg_replace('/\D/', '', (string) ($alumno['documento'] ?? ''));
    if ($doc !== '' && $doc !== null && strlen($doc) >= 7 && strlen($doc) <= 8) {
        return ['doc_tipo' => 96, 'doc_nro' => (int) $doc];
    }

    return ['doc_tipo' => 99, 'doc_nro' => 0];
}

function fe_letra_desde_cbte_tipo(int $cbteTipo): string
{
    if (in_array($cbteTipo, [1, 3], true)) {
        return 'A';
    }
    if (in_array($cbteTipo, [6, 8], true)) {
        return 'B';
    }
    if ($cbteTipo === 11) {
        return 'C';
    }

    return 'B';
}

/**
 * Fecha de vencimiento del CAE (Y-m-d). AFIP/Gesis suele enviar CAEFchVto como YYYYMMDD.
 */
function fe_parse_cae_vencimiento(array $resp, string $fechaEmision = ''): string
{
    foreach (['CAEFchVto', 'CaefchVto', 'caeFchVto', 'CAE_FCH_VTO'] as $key) {
        if (!isset($resp[$key]) || $resp[$key] === '' || $resp[$key] === null) {
            continue;
        }
        $digits = preg_replace('/\D/', '', (string) $resp[$key]);
        if (strlen($digits) === 8) {
            return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
        }
    }

    $base = $fechaEmision !== '' ? strtotime($fechaEmision) : false;
    if ($base === false) {
        $base = time();
    }

    return date('Y-m-d', strtotime('+10 days', $base));
}

function fe_ensure_talonario(PDO $pdo, int $ptoVta, int $cbteTipo): int
{
    $letra = fe_letra_desde_cbte_tipo($cbteTipo);
    $codigo = 'FE_' . $letra . '_PV' . $ptoVta;
    $tipoDb = $letra === 'A' ? 'FAC_A' : 'FAC_B';

    $st = $pdo->prepare('SELECT id FROM talonario WHERE codigo = ? LIMIT 1');
    $st->execute([$codigo]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $ins = $pdo->prepare(
        'INSERT INTO talonario (codigo, tipo, punto_venta, descripcion, activo) VALUES (?, ?, ?, ?, 1)'
    );
    $ins->execute([$codigo, $tipoDb, $ptoVta, 'Factura electrónica ' . $letra . ' PV ' . $ptoVta]);
    $tid = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT IGNORE INTO talonario_ultimo_numero (talonario_id, ultimo_numero) VALUES (?, 0)')
        ->execute([$tid]);

    return $tid;
}

/**
 * Arma el body AFIP para crear-proximo-comprobante (Factura C por defecto).
 *
 * @param array<string,mixed> $gesisCfg
 * @param array{pago:array,alumno:?array} $datos
 * @return array<string,mixed>
 */
function fe_armar_voucher_desde_recibo(array $gesisCfg, array $datos, ?int $ptoVtaOverride = null): array
{
    $pago = $datos['pago'];
    $alumno = $datos['alumno'] ?? [];
    $pto = $ptoVtaOverride ?? (int) $gesisCfg['punto_venta'];
    $cbteTipo = (int) $gesisCfg['cbte_tipo'];
    $concepto = (int) $gesisCfg['concepto'];
    $total = round((float) ($pago['importe'] ?? 0), 2);
    if ($total <= 0) {
        throw new InvalidArgumentException('El recibo no tiene importe positivo.');
    }

    $fecha = (string) ($pago['fecha_pago'] ?? date('Y-m-d'));
    $ts = strtotime($fecha) ?: time();
    $cbteFch = (int) date('Ymd', $ts);
    $mesIni = (int) date('Ym01', $ts);
    $mesFin = (int) date('Ymt', $ts);

    $doc = fe_doc_receptor_desde_alumno(is_array($alumno) ? $alumno : []);
    $condIva = fe_condicion_iva_receptor_id((string) ($alumno['condicion_iva'] ?? 'consumidor_final'));

    $voucher = [
        'CantReg' => 1,
        'PtoVta' => $pto,
        'CbteTipo' => $cbteTipo,
        'Concepto' => $concepto,
        'DocTipo' => $doc['doc_tipo'],
        'DocNro' => $doc['doc_nro'],
        'CbteDesde' => 1,
        'CbteHasta' => 1,
        'CbteFch' => $cbteFch,
        'ImpTotal' => $total,
        'ImpTotConc' => 0.0,
        'ImpNeto' => $total,
        'ImpOpEx' => 0.0,
        'ImpIVA' => 0.0,
        'ImpTrib' => 0.0,
        'MonId' => 'PES',
        'MonCotiz' => 1.0,
        'CondicionIVAReceptorId' => $condIva,
    ];

    if ($concepto === 2 || $concepto === 3) {
        $voucher['FchServDesde'] = $mesIni;
        $voucher['FchServHasta'] = $mesFin;
        $voucher['FchVtoPago'] = $mesFin;
    }

    if ($cbteTipo === 6) {
        $neto = round($total / 1.21, 2);
        $iva = round($total - $neto, 2);
        $voucher['ImpNeto'] = $neto;
        $voucher['ImpIVA'] = $iva;
        $voucher['Iva'] = [['Id' => 5, 'BaseImp' => $neto, 'Importe' => $iva]];
    }

    return $voucher;
}

/**
 * @param array<string,mixed> $config
 * @return array{ok:bool,msg:string,cae?:string,vto?:string,numero?:int,punto_venta?:int,comprobante_id?:int}
 */
function fe_emitir_desde_pago(PDO $pdo, array $config, int $pagoId, ?int $ptoVtaOverride = null, ?bool $productionOverride = null): array
{
    if (!fe_schema_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Falta migración 04 (facturación) y 30 (comprobante_id en pagos).'];
    }

    if (fe_pago_ya_facturado($pdo, $pagoId)) {
        return ['ok' => false, 'msg' => 'Este recibo ya tiene factura electrónica autorizada.'];
    }

    $datos = recibo_cargar_por_pago($pdo, $pagoId);
    if ($datos === null) {
        return ['ok' => false, 'msg' => 'Recibo no encontrado.'];
    }
    if (fe_pago_es_migrado_excel($pdo, $datos['pago'])) {
        return [
            'ok' => false,
            'msg' => 'Este recibo no admite emitir factura ARCA desde aquí.',
        ];
    }

    $gesisCfg = fe_gesis_config($config, $pdo);
    $client = new GesisArcaClient($gesisCfg);
    if (!$client->isConfigured()) {
        return ['ok' => false, 'msg' => 'Configure Gesis en Utilitarios → Factura electrónica (parámetros).'];
    }

    $production = $productionOverride ?? (bool) $gesisCfg['production'];
    $pto = $ptoVtaOverride ?? (int) $gesisCfg['punto_venta'];
    $cbteTipo = (int) $gesisCfg['cbte_tipo'];

    try {
        $voucher = fe_armar_voucher_desde_recibo($gesisCfg, $datos, $pto);
        $resp = $client->crearProximoComprobante($voucher, $production);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }

    $cae = isset($resp['CAE']) ? (string) $resp['CAE'] : '';
    if ($cae === '') {
        $obs = fe_gesis_extraer_error($resp);
        return ['ok' => false, 'msg' => $obs !== '' ? $obs : 'La API no devolvió CAE.'];
    }

    $numero = (int) ($resp['voucherNumber'] ?? 0);
    if ($numero <= 0) {
        return ['ok' => false, 'msg' => 'La API no devolvió número de comprobante.'];
    }

    $pago = $datos['pago'];
    $alumnoId = (int) ($pago['alumno_id'] ?? 0);
    $total = round((float) ($pago['importe'] ?? 0), 2);
    $letra = fe_letra_desde_cbte_tipo($cbteTipo);
    $fechaPago = (string) ($pago['fecha_pago'] ?? date('Y-m-d'));
    $fechaEmision = $fechaPago . ' 12:00:00';
    $caeVto = fe_parse_cae_vencimiento($resp, $fechaPago);

    try {
        $pdo->beginTransaction();

        $talonarioId = fe_ensure_talonario($pdo, $pto, $cbteTipo);

        $insC = $pdo->prepare(
            'INSERT INTO comprobante (
                origen, alumno_id, talonario_id, tipo, letra, punto_venta, numero,
                fecha_emision, importe_neto, importe_iva, importe_total, estado, observaciones
             ) VALUES (
                \'web\', ?, ?, \'FACTURA\', ?, ?, ?,
                ?, ?, 0, ?, \'emitido\', ?
             )'
        );
        $obs = 'Factura electrónica desde recibo Nº ' . $pagoId;
        $insC->execute([
            $alumnoId,
            $talonarioId,
            $letra,
            $pto,
            $numero,
            $fechaEmision,
            $total,
            $total,
            $obs,
        ]);
        $compId = (int) $pdo->lastInsertId();

        $orden = 1;
        $insD = $pdo->prepare(
            'INSERT INTO comprobante_detalle (
                comprobante_id, orden, descripcion, cantidad, precio_unitario, importe_total
             ) VALUES (?, ?, ?, 1, ?, ?)'
        );
        foreach (fe_lineas_desde_recibo($datos) as $ln) {
            $imp = round((float) $ln['importe_total'], 2);
            $insD->execute([$compId, $orden++, (string) $ln['descripcion'], $imp, $imp]);
        }

        $reqJson = json_encode($voucher, JSON_UNESCAPED_UNICODE);
        $resJson = json_encode($resp, JSON_UNESCAPED_UNICODE);

        $pdo->prepare(
            'INSERT INTO comprobante_electronico (
                comprobante_id, proveedor, estado, cae, cae_vencimiento, numero_electronico,
                request_json, response_json, autorizado_en
             ) VALUES (?, \'otro\', \'autorizado\', ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $compId,
            $cae,
            $caeVto,
            $numero,
            $reqJson,
            $resJson,
        ]);

        $pdo->prepare('UPDATE pago_registrado SET comprobante_id = ? WHERE id = ?')
            ->execute([$compId, $pagoId]);

        $pdo->prepare(
            'INSERT INTO talonario_ultimo_numero (talonario_id, ultimo_numero)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE ultimo_numero = GREATEST(ultimo_numero, VALUES(ultimo_numero))'
        )->execute([$talonarioId, $numero]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'msg' => 'Error al guardar: ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'msg' => 'Factura electrónica autorizada.',
        'cae' => $cae,
        'vto' => $caeVto ?? '',
        'numero' => $numero,
        'punto_venta' => $pto,
        'comprobante_id' => $compId,
    ];
}
