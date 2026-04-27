<?php
declare(strict_types=1);

/**
 * Generación manual de cuota_mensual por período (año/mes).
 * Importe = suma de importe_referencia de los artículos asignados al alumno (alumno_articulo).
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = Db::pdo($config);

$reporte = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $anio = (int) ($_POST['anio'] ?? 0);
    $mes = (int) ($_POST['mes'] ?? 0);
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        $reporte = ['ok' => false, 'msg' => 'Año o mes inválido.'];
    } else {
        $dt = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
        $dt->modify('last day of this month');
        $fechaVen = $dt->format('Y-m-d');

        $sql = '
            SELECT aa.alumno_id, COALESCE(SUM(a.importe_referencia), 0) AS total
            FROM alumno_articulo aa
            INNER JOIN articulos a ON a.id = aa.articulo_id AND a.activo = 1
            INNER JOIN alumnos al ON al.id = aa.alumno_id
            WHERE al.activo = 1
            GROUP BY aa.alumno_id HAVING total > 0
        ';

        $st = $pdo->query($sql);
        $filas = $st->fetchAll();

        $creadas = 0;
        $omitidas = 0;

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO cuota_mensual (alumno_id, anio, mes, importe_original, saldo, fecha_vencimiento, estado, nota)
                 VALUES (?, ?, ?, ?, ?, ?, \'pendiente\', ?)'
            );
            $chk = $pdo->prepare(
                'SELECT id, estado FROM cuota_mensual WHERE alumno_id = ? AND anio = ? AND mes = ?'
            );

            foreach ($filas as $row) {
                $alumnoId = (int) $row['alumno_id'];
                $total = round((float) $row['total'], 2);
                if ($total <= 0) {
                    continue;
                }

                $chk->execute([$alumnoId, $anio, $mes]);
                $ex = $chk->fetch();
                if ($ex) {
                    $omitidas++;
                    continue;
                }

                $nota = 'Generada desde artículos asignados (' . date('Y-m-d H:i') . ')';
                $ins->execute([
                    $alumnoId,
                    $anio,
                    $mes,
                    $total,
                    $total,
                    $fechaVen,
                    $nota,
                ]);
                $creadas++;
            }

            $pdo->commit();
            recalcular_saldo_alumnos($pdo);
            $reporte = [
                'ok' => true,
                'creadas' => $creadas,
                'omitidas' => $omitidas,
                'periodo' => sprintf('%04d-%02d', $anio, $mes),
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $reporte = ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

$ahora = new DateTimeImmutable('now');
$anioDefault = (int) $ahora->format('Y');
$mesDefault = (int) $ahora->format('n');

layout_start($config, 'Generar cuotas');
echo '<h1>Generar cuotas del mes</h1>';
echo '<p class="muted">Solo alumnos con <strong>activo = sí</strong>. Por cada uno con al menos un <strong>artículo asignado</strong> (y artículo activo), se crea una fila en <code>cuota_mensual</code> con importe = suma de precios lista 1 (<code>importe_referencia</code>). No pisa cuotas ya existentes para ese período.</p>';

if ($reporte !== null) {
    if (!empty($reporte['ok'])) {
        flash_ok(
            'Período ' . h($reporte['periodo']) . ': cuotas creadas: ' . (int) $reporte['creadas']
            . ', ya existían (omitidas): ' . (int) $reporte['omitidas'] . '.'
        );
    } else {
        flash_err($reporte['msg'] ?? 'Error');
    }
}

echo '<form method="post" class="form form-grid">';
echo '<label>Año <input name="anio" type="number" required min="2000" max="2100" value="' . $anioDefault . '"></label>';
echo '<label>Mes <input name="mes" type="number" required min="1" max="12" value="' . $mesDefault . '"></label>';
echo '<div class="form-actions"><button type="submit">Generar cuotas para este período</button></div>';
echo '</form>';

echo '<p><a href="index.php">Inicio</a> · <a href="conceptos_alumno.php">Conceptos por alumno</a></p>';

layout_end();
