<?php
declare(strict_types=1);

/**
 * Recalcula y persiste saldo de cuenta corriente por alumno.
 * Usa la misma lógica que cuenta corriente (vista operativa / simple).
 */
require_once __DIR__ . '/OperativoCobranza.php';

function recalcular_saldo_alumnos(\PDO $pdo, ?int $alumnoId = null, ?string $fechaCorte = null): int
{
    require_once __DIR__ . '/CuentaCorrienteMovimientos.php';

    $stUpd = $pdo->prepare('UPDATE alumnos SET saldo_cc = ROUND(?, 2) WHERE id = ?');

    if ($alumnoId !== null && $alumnoId > 0) {
        [, $resumen] = cc_build_movimientos($pdo, $alumnoId, 'simple');
        $stUpd->execute([$resumen['saldo'], $alumnoId]);

        return $stUpd->rowCount();
    }

    $ids = $pdo->query('SELECT id FROM alumnos')->fetchAll(\PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        $aid = (int) $id;
        if ($aid <= 0) {
            continue;
        }
        [, $resumen] = cc_build_movimientos($pdo, $aid, 'simple');
        $stUpd->execute([$resumen['saldo'], $aid]);
        $n += $stUpd->rowCount();
    }

    return $n;
}
