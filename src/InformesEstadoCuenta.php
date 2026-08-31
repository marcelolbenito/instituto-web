<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Cobranza.php';
require_once __DIR__ . '/Postitulo.php';
require_once __DIR__ . '/Saldos.php';

/**
 * Armado del reporte de estado de cuenta a una fecha de consulta.
 *
 * @return array{
 *   alumno: array<string,mixed>,
 *   fecha_consulta: string,
 *   fecha_emision: string,
 *   lineas: list<array<string,mixed>>,
 *   total_original: float,
 *   total_capital: float,
 *   total_recargos: float,
 *   total_actualizado: float,
 *   saldo_cc: float
 * }|null
 */
function informes_estado_cuenta_armar(PDO $pdo, int $alumnoId, ?string $fechaConsultaYmd = null): ?array
{
    if ($alumnoId <= 0) {
        return null;
    }

    $fechaConsulta = trim((string) ($fechaConsultaYmd ?? date('Y-m-d')));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaConsulta) !== 1) {
        $fechaConsulta = date('Y-m-d');
    }

    $cols = 'a.id, a.codigo_legacy, a.nombre_completo, a.documento, a.activo, a.saldo_cc';
    if (db_has_column($pdo, 'alumnos', 'tipo_alumno')) {
        $cols .= ', a.tipo_alumno';
    }
    if (db_has_column($pdo, 'alumnos', 'email')) {
        $cols .= ', a.email';
    }
    if (db_has_column($pdo, 'alumnos', 'telefono_whatsapp')) {
        $cols .= ', a.telefono_whatsapp';
    }
    $joinBarrio = db_has_column($pdo, 'alumnos', 'barrio_id')
        ? 'LEFT JOIN barrios b ON b.id = a.barrio_id'
        : '';
    if ($joinBarrio !== '') {
        $cols .= ', b.nombre AS barrio_nombre';
    }

    $st = $pdo->prepare("SELECT {$cols} FROM alumnos a {$joinBarrio} WHERE a.id = ? LIMIT 1");
    $st->execute([$alumnoId]);
    $alumno = $st->fetch(PDO::FETCH_ASSOC);
    if (!$alumno) {
        return null;
    }

    $param = cobranza_cargar_parametros($pdo);
    $esPostituloAlumno = postitulo_schema_ok($pdo) && postitulo_alumno_es($pdo, $alumnoId);
    $lineas = [];

    $cuotas = cobranza_cuotas_pendientes_alumno($pdo, $alumnoId);
    foreach ($cuotas as $cuota) {
        if ($esPostituloAlumno && !array_key_exists('es_postitulo', $cuota)) {
            $cuota['es_postitulo'] = 1;
            $cuota['fecha_vencimiento_postitulo'] = postitulo_vencimiento_periodo(
                $pdo,
                (int) ($cuota['anio'] ?? 0),
                (int) ($cuota['mes'] ?? 0)
            );
        }
        $calc = cobranza_calcular_linea_cuota($param, $cuota, $fechaConsulta);
        $capital = round((float) ($calc['importe_capital'] ?? cobranza_saldo_impago_cuota($cuota)), 2);
        $original = round((float) ($cuota['importe_original'] ?? $capital), 2);
        $total = round((float) ($calc['total_linea'] ?? $capital), 2);
        $recargos = round(
            (float) ($calc['importe_recargo_variable'] ?? 0)
            + (float) ($calc['importe_recargo_fijo'] ?? 0)
            + (float) ($calc['importe_beca_perdida'] ?? 0),
            2
        );
        $desc = round((float) ($calc['importe_descuento'] ?? 0), 2);
        $anio = (int) ($cuota['anio'] ?? 0);
        $mes = (int) ($cuota['mes'] ?? 0);
        $lineas[] = [
            'tipo' => 'cuota',
            'concepto' => sprintf('Cuota mensual %04d-%02d', $anio, $mes),
            'periodo' => sprintf('%04d-%02d', $anio, $mes),
            'fecha_ref' => (string) ($calc['fecha_tope_pronto'] ?? ''),
            'monto_original' => $original,
            'monto_capital' => $capital,
            'monto_recargos' => $recargos,
            'monto_descuento' => $desc,
            'monto_actualizado' => $total,
            'dias_mora' => (int) ($calc['dias_mora'] ?? 0),
            'dentro_pronto' => !empty($calc['dentro_pronto']),
            'detalle_calc' => $calc,
        ];
    }

    $tieneBeca = false;
    foreach ($cuotas as $c) {
        if ((int) ($c['tiene_beca'] ?? 0) === 1) {
            $tieneBeca = true;
            break;
        }
    }
    $artBeca = '';
    foreach ($cuotas as $c) {
        $art = trim((string) ($c['articulos_beca_detalle'] ?? ''));
        if ($art !== '') {
            $artBeca = $art;
            break;
        }
    }

    foreach (cobranza_ajustes_debe_pendientes($pdo, $alumnoId) as $adj) {
        if (cobranza_ajuste_es_espejo_item_recibo($adj)
            || cobranza_referencia_es_incremento_cobro((string) ($adj['referencia'] ?? ''))
            || cobranza_referencia_es_descuento_cobro((string) ($adj['referencia'] ?? ''))
        ) {
            continue;
        }
        $calc = cobranza_calcular_linea_debe_pendiente(
            $param,
            $adj,
            $fechaConsulta,
            $tieneBeca,
            $artBeca
        );
        $capital = round((float) ($calc['importe_capital'] ?? $adj['debe'] ?? 0), 2);
        $original = round((float) ($adj['debe'] ?? $capital), 2);
        $total = round((float) ($calc['total_linea'] ?? $capital), 2);
        $recargos = round(
            (float) ($calc['importe_recargo_variable'] ?? 0)
            + (float) ($calc['importe_recargo_fijo'] ?? 0)
            + (float) ($calc['importe_beca_perdida'] ?? 0),
            2
        );
        $concepto = trim((string) ($adj['concepto'] ?? 'Obligación pendiente'));
        if ($concepto === '') {
            $concepto = 'Obligación pendiente';
        }
        $lineas[] = [
            'tipo' => 'debe',
            'concepto' => $concepto,
            'periodo' => '',
            'fecha_ref' => (string) ($adj['fecha_mov'] ?? ''),
            'monto_original' => $original,
            'monto_capital' => $capital,
            'monto_recargos' => $recargos,
            'monto_descuento' => round((float) ($calc['importe_descuento'] ?? 0), 2),
            'monto_actualizado' => $total,
            'dias_mora' => (int) ($calc['dias_mora'] ?? 0),
            'dentro_pronto' => !empty($calc['dentro_pronto']),
            'detalle_calc' => $calc,
        ];
    }

    usort($lineas, static function (array $a, array $b): int {
        $pa = (string) ($a['periodo'] ?? '');
        $pb = (string) ($b['periodo'] ?? '');
        if ($pa !== '' && $pb !== '' && $pa !== $pb) {
            return $pa <=> $pb;
        }
        if ($pa !== $pb) {
            return $pa === '' ? 1 : -1;
        }

        return strcmp((string) $a['concepto'], (string) $b['concepto']);
    });

    $totOrig = 0.0;
    $totCap = 0.0;
    $totRec = 0.0;
    $totAct = 0.0;
    foreach ($lineas as $lin) {
        $totOrig += (float) $lin['monto_original'];
        $totCap += (float) $lin['monto_capital'];
        $totRec += (float) $lin['monto_recargos'] - (float) $lin['monto_descuento'];
        $totAct += (float) $lin['monto_actualizado'];
    }

    return [
        'alumno' => $alumno,
        'fecha_consulta' => $fechaConsulta,
        'fecha_emision' => date('Y-m-d H:i:s'),
        'lineas' => $lineas,
        'total_original' => round($totOrig, 2),
        'total_capital' => round($totCap, 2),
        'total_recargos' => round($totRec, 2),
        'total_actualizado' => round($totAct, 2),
        'saldo_cc' => round((float) ($alumno['saldo_cc'] ?? 0), 2),
    ];
}

/**
 * @param array<string,mixed> $reporte
 */
function informes_estado_cuenta_render_bloque(array $reporte, bool $paraImpresion = false): void
{
    $al = $reporte['alumno'];
    $tsEmi = strtotime((string) $reporte['fecha_emision']);
    $emiTxt = $tsEmi !== false ? date('d/m/Y H:i', $tsEmi) : (string) $reporte['fecha_emision'];
    $tsCons = strtotime((string) $reporte['fecha_consulta']);
    $consTxt = $tsCons !== false ? date('d/m/Y', $tsCons) : (string) $reporte['fecha_consulta'];

    echo '<section class="estado-cuenta-bloque">';
    echo '<h2>Datos del alumno</h2>';
    echo '<dl class="estado-cuenta-datos">';
    echo '<div><dt>Nombre</dt><dd>' . h((string) ($al['nombre_completo'] ?? '')) . '</dd></div>';
    echo '<div><dt>DNI / documento</dt><dd>' . h((string) ($al['documento'] ?? '—')) . '</dd></div>';
    echo '<div><dt>Código</dt><dd>' . h((string) ($al['codigo_legacy'] ?? '—')) . '</dd></div>';
    if (!empty($al['barrio_nombre'])) {
        echo '<div><dt>Barrio</dt><dd>' . h((string) $al['barrio_nombre']) . '</dd></div>';
    }
    if (isset($al['tipo_alumno']) && (string) $al['tipo_alumno'] !== '') {
        echo '<div><dt>Tipo</dt><dd>' . h((string) $al['tipo_alumno']) . '</dd></div>';
    }
    if (!empty($al['email'])) {
        echo '<div><dt>Email</dt><dd>' . h((string) $al['email']) . '</dd></div>';
    }
    if (!empty($al['telefono_whatsapp'])) {
        echo '<div><dt>WhatsApp</dt><dd>' . h((string) $al['telefono_whatsapp']) . '</dd></div>';
    }
    $activoTxt = ((int) ($al['activo'] ?? 0) === 1) ? 'Activo' : 'Inactivo';
    echo '<div><dt>Estado ficha</dt><dd>' . h($activoTxt) . '</dd></div>';
    echo '<div><dt>Fecha de emisión</dt><dd>' . h($emiTxt) . '</dd></div>';
    echo '<div><dt>Situación al</dt><dd>' . h($consTxt) . '</dd></div>';
    echo '</dl>';

    echo '<h2>Cuotas y deudas adeudadas</h2>';
    echo '<p class="muted small">Montos actualizados a la fecha de consulta con recargos/intereses según parámetros de cobranza'
        . ($paraImpresion ? '.' : ' (misma lógica que al registrar un cobro).') . '</p>';

    $lineas = $reporte['lineas'];
    if ($lineas === []) {
        echo '<p class="ok">Sin cuotas ni obligaciones pendientes a la fecha de consulta.</p>';
    } else {
        echo '<table class="table estado-cuenta-tabla"><thead><tr>';
        echo '<th>Concepto</th><th>Venc. / ref.</th>';
        echo '<th class="num">Monto original</th><th class="num">Recargos</th><th class="num">Monto actualizado</th>';
        echo '</tr></thead><tbody>';
        foreach ($lineas as $lin) {
            $tsRef = strtotime((string) ($lin['fecha_ref'] ?? ''));
            $refTxt = $tsRef !== false ? date('d/m/Y', $tsRef) : (string) ($lin['fecha_ref'] ?? '—');
            $recNeto = round((float) $lin['monto_recargos'] - (float) $lin['monto_descuento'], 2);
            echo '<tr>';
            echo '<td>' . h((string) $lin['concepto']);
            if (!empty($lin['dias_mora'])) {
                echo ' <span class="muted">· ' . (int) $lin['dias_mora'] . ' día(s) mora</span>';
            }
            echo '</td>';
            echo '<td>' . h($refTxt !== '' ? $refTxt : '—') . '</td>';
            echo '<td class="num">$ ' . number_format((float) $lin['monto_original'], 2, ',', '.') . '</td>';
            echo '<td class="num">$ ' . number_format($recNeto, 2, ',', '.') . '</td>';
            echo '<td class="num"><strong>$ ' . number_format((float) $lin['monto_actualizado'], 2, ',', '.') . '</strong></td>';
            echo '</tr>';
        }
        echo '</tbody><tfoot>';
        echo '<tr><th colspan="2">Total general adeudado</th>';
        echo '<td class="num">$ ' . number_format((float) $reporte['total_original'], 2, ',', '.') . '</td>';
        echo '<td class="num">$ ' . number_format((float) $reporte['total_recargos'], 2, ',', '.') . '</td>';
        echo '<td class="num text-debe"><strong>$ ' . number_format((float) $reporte['total_actualizado'], 2, ',', '.') . '</strong></td>';
        echo '</tr></tfoot></table>';
    }

    echo '<p class="estado-cuenta-total">Total general adeudado al ' . h($consTxt) . ': '
        . '<strong class="text-debe">$ ' . number_format((float) $reporte['total_actualizado'], 2, ',', '.') . '</strong></p>';
    echo '<p class="muted small">Saldo de cuenta corriente registrado (referencia): $ '
        . number_format((float) $reporte['saldo_cc'], 2, ',', '.')
        . '. El total adeudado de este informe incluye recargos proyectados a la fecha de consulta.</p>';
    echo '</section>';
}
