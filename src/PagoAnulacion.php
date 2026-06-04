<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Saldos.php';
require_once __DIR__ . '/Caja.php';
require_once __DIR__ . '/FacturaElectronica.php';

function pago_anulacion_schema_ok(PDO $pdo): bool
{
    return db_has_column($pdo, 'pago_registrado', 'anulado_en');
}

function pago_esta_anulado(array $pago): bool
{
    return trim((string) ($pago['anulado_en'] ?? '')) !== '';
}

/** Fragmento SQL: solo recibos vigentes (alias opcional, ej. pr). */
function pago_sql_solo_vigentes(string $alias = ''): string
{
    $col = $alias !== '' ? $alias . '.anulado_en' : 'anulado_en';

    return '(' . $col . ' IS NULL)';
}

/**
 * @return array{ok:bool,msg:string}
 */
function pago_anulacion_validar(PDO $pdo, int $pagoId): array
{
    if (!pago_anulacion_schema_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Ejecutá la migración 37 (anulación de recibos).'];
    }
    if ($pagoId <= 0) {
        return ['ok' => false, 'msg' => 'Indicá un número de recibo válido.'];
    }

    $st = $pdo->prepare('SELECT * FROM pago_registrado WHERE id = ? LIMIT 1');
    $st->execute([$pagoId]);
    $pago = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pago) {
        return ['ok' => false, 'msg' => 'Recibo no encontrado.'];
    }
    if (pago_esta_anulado($pago)) {
        return ['ok' => false, 'msg' => 'Este recibo ya está anulado.'];
    }

    $medio = strtolower(trim((string) ($pago['medio'] ?? '')));
    if (in_array($medio, ['legacy', 'excel'], true)) {
        return ['ok' => false, 'msg' => 'No se pueden anular cobros importados (legacy / Excel).'];
    }

    $fe = fe_estado_pago($pdo, $pagoId);
    if ($fe['estado'] === 'autorizado') {
        return [
            'ok' => false,
            'msg' => 'El recibo tiene factura electrónica autorizada. Debe emitirse nota de crédito (pendiente de desarrollo).',
        ];
    }

    return ['ok' => true, 'msg' => ''];
}

/**
 * Anula un recibo web: revierte cuotas, contramovimientos CC, obligaciones y caja.
 *
 * @return array{ok:bool,msg:string,alumno_id?:int}
 */
function pago_anular_recibo(PDO $pdo, int $pagoId, ?int $usuarioId, string $motivo): array
{
    $valid = pago_anulacion_validar($pdo, $pagoId);
    if (!$valid['ok']) {
        return $valid;
    }

    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['ok' => false, 'msg' => 'Indicá el motivo de la anulación.'];
    }
    if (mb_strlen($motivo) > 255) {
        $motivo = mb_substr($motivo, 0, 255);
    }

    $pdo->beginTransaction();
    try {
        $stP = $pdo->prepare('SELECT * FROM pago_registrado WHERE id = ? FOR UPDATE');
        $stP->execute([$pagoId]);
        $pago = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$pago || pago_esta_anulado($pago)) {
            throw new RuntimeException('El recibo ya no está disponible para anular.');
        }

        $alumnoId = (int) ($pago['alumno_id'] ?? 0);
        $fechaPago = (string) ($pago['fecha_pago'] ?? '');
        $importe = (float) ($pago['importe'] ?? 0);
        $medio = trim((string) ($pago['medio'] ?? ''));

        $stPac = $pdo->prepare(
            'SELECT pac.*, cm.importe_original, cm.saldo, cm.estado, cm.importe_diferencia_beca
             FROM pago_aplica_cuota pac
             INNER JOIN cuota_mensual cm ON cm.id = pac.cuota_id
             WHERE pac.pago_id = ?
             FOR UPDATE'
        );
        $stPac->execute([$pagoId]);
        $pacRows = $stPac->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hasDifBeca = db_has_column($pdo, 'cuota_mensual', 'importe_diferencia_beca');
        if ($hasDifBeca) {
            $updCuota = $pdo->prepare(
                'UPDATE cuota_mensual
                 SET saldo = ?,
                     estado = ?,
                     importe_diferencia_beca = CASE WHEN ? = 1 THEN 0 ELSE importe_diferencia_beca END
                 WHERE id = ?'
            );
        } else {
            $updCuota = $pdo->prepare(
                'UPDATE cuota_mensual SET saldo = ?, estado = ? WHERE id = ?'
            );
        }
        foreach ($pacRows as $pac) {
            $capital = (float) ($pac['importe_capital'] ?? 0);
            if ($capital <= 0.00001) {
                $capital = (float) ($pac['importe_aplicado'] ?? 0);
            }
            $descPac = (float) ($pac['importe_descuento'] ?? 0);
            $reduccion = round($capital + $descPac, 2);
            $saldoActual = (float) ($pac['saldo'] ?? 0);
            $orig = (float) ($pac['importe_original'] ?? 0);
            $nuevoSaldo = round($saldoActual + $reduccion, 2);
            $estado = 'pendiente';
            if ($nuevoSaldo <= 0.005) {
                $nuevoSaldo = 0.0;
                $estado = 'pagada';
            } elseif ($orig > 0.005 && $nuevoSaldo + 0.005 < $orig) {
                $estado = 'parcial';
            }
            $limpiaBeca = $hasDifBeca && ((float) ($pac['importe_beca_perdida'] ?? 0)) > 0.005 ? 1 : 0;
            if ($hasDifBeca) {
                $updCuota->execute([$nuevoSaldo, $estado, $limpiaBeca, (int) $pac['cuota_id']]);
            } else {
                $updCuota->execute([$nuevoSaldo, $estado, (int) $pac['cuota_id']]);
            }
        }

        if (db_has_column($pdo, 'cc_ajuste_debe', 'pago_id')) {
            $pdo->prepare(
                'DELETE FROM cc_ajuste_debe
                 WHERE pago_id = ?
                   AND (
                     referencia LIKE \'RECIBO_INC:%\'
                     OR referencia LIKE \'RECIBO_DEC:%\'
                     OR referencia LIKE \'RECIBO_ITEM:%\'
                   )'
            )->execute([$pagoId]);

            $pdo->prepare(
                'UPDATE cc_ajuste_debe SET pago_id = NULL WHERE pago_id = ?'
            )->execute([$pagoId]);
        }

        $stAn = $pdo->prepare(
            'UPDATE pago_registrado
             SET anulado_en = CURRENT_TIMESTAMP,
                 anulado_por = ?,
                 motivo_anulacion = ?
             WHERE id = ? AND anulado_en IS NULL'
        );
        $stAn->execute([
            $usuarioId !== null && $usuarioId > 0 ? $usuarioId : null,
            $motivo,
            $pagoId,
        ]);
        if ($stAn->rowCount() === 0) {
            throw new RuntimeException('No se pudo marcar el recibo como anulado.');
        }

        $cajaRes = caja_registrar_egreso_por_anulacion($pdo, $pagoId, $alumnoId, $fechaPago, $importe, $medio, $motivo);

        $pdo->commit();
        recalcular_saldo_alumnos($pdo, $alumnoId);

        $msg = 'Recibo Nº ' . $pagoId . ' anulado correctamente.';
        if (($cajaRes['msg'] ?? '') !== '') {
            $msg .= ' Caja: ' . $cajaRes['msg'];
        }

        return [
            'ok' => true,
            'msg' => $msg,
            'alumno_id' => $alumnoId,
            'caja_estado' => $cajaRes['estado'] ?? '',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}
