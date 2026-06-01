<?php
declare(strict_types=1);

/**
 * Recalcula y persiste saldo de cuenta corriente por alumno.
 * Usa la misma lógica que cuenta corriente (vista operativa / simple).
 */
function saldo_corte_desde(): ?string
{
    $raw = trim((string) getenv('SALDO_CORTE_DESDE'));
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return null;
    }

    return $raw;
}

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
