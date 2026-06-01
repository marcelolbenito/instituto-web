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
require_once dirname(__DIR__) . '/src/Cobranza.php';

$pdo = Db::pdo($config);
$hasTipoAlumno = db_has_column($pdo, 'alumnos', 'tipo_alumno');
$hasRangoPostgrado = db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_desde')
    && db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_hasta');

$reporte = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $anio = (int) ($_POST['anio'] ?? 0);
    $mes = (int) ($_POST['mes'] ?? 0);
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        $reporte = ['ok' => false, 'msg' => 'Año o mes inválido.'];
    } elseif (!$hasTipoAlumno || !$hasRangoPostgrado) {
        $reporte = ['ok' => false, 'msg' => 'Falta migración 21 (tipo de alumno y rango de postgrado).'];
    } else {
        $fechaVen = cobranza_fecha_generacion_cuota($pdo, $anio, $mes);

        $sql = '
            SELECT aa.alumno_id, COALESCE(SUM(a.importe_referencia), 0) AS total
            FROM alumno_articulo aa
            INNER JOIN articulos a ON a.id = aa.articulo_id AND a.activo = 1
            INNER JOIN alumnos al ON al.id = aa.alumno_id
            INNER JOIN parametros_cobranza p ON p.id = 1
            WHERE al.activo = 1
              AND (
                COALESCE(al.tipo_alumno, \'regular\') = \'regular\'
                OR (
                    COALESCE(al.tipo_alumno, \'regular\') = \'postgrado\'
                    AND (
                        CASE
                          WHEN COALESCE(p.postgrado_mes_desde, 4) <= COALESCE(p.postgrado_mes_hasta, 11)
                            THEN (? BETWEEN COALESCE(p.postgrado_mes_desde, 4) AND COALESCE(p.postgrado_mes_hasta, 11))
                          ELSE (? >= COALESCE(p.postgrado_mes_desde, 4) OR ? <= COALESCE(p.postgrado_mes_hasta, 11))
                        END
                    )
                )
              )
            GROUP BY aa.alumno_id HAVING total > 0
        ';

        $st = $pdo->prepare($sql);
        $st->execute([$mes, $mes, $mes]);
        $filas = $st->fetchAll();

        $creadas = 0;
        $omitidas = 0;
        $elegibles = count($filas);

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
                'elegibles' => $elegibles,
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
echo '<p class="muted">Solo alumnos con <strong>activo = sí</strong>. Por cada uno con al menos un <strong>artículo asignado</strong> (y artículo activo), se crea una fila en <code>cuota_mensual</code> con importe = suma de precios lista 1 (<code>importe_referencia</code>). No pisa cuotas ya existentes para ese período. La fecha del cargo es el <strong>día de generación</strong> configurado en <a href="parametros_cobranza.php">Parámetros cobranza</a> (por defecto el <strong>1</strong> de cada mes); en cuenta corriente se muestra siempre el <strong>1 del período</strong> (ej. cuota 2026-05 → 01/05/2026).</p>';
if (!$hasTipoAlumno || !$hasRangoPostgrado) {
    echo '<p class="err">Ejecutá la migración <code>sql/migracion/21_tipo_alumno_y_periodo_postgrado.sql</code> para habilitar tipo de alumno y rango de postgrado.</p>';
}

if ($reporte !== null) {
    if (!empty($reporte['ok'])) {
        $creadas = (int) ($reporte['creadas'] ?? 0);
        $omitidas = (int) ($reporte['omitidas'] ?? 0);
        $elegibles = (int) ($reporte['elegibles'] ?? ($creadas + $omitidas));
        $periodo = (string) ($reporte['periodo'] ?? '');
        $titulo = $creadas > 0
            ? 'Generación completada: se crearon ' . $creadas . ' cuota(s).'
            : 'Generación completada: no se crearon cuotas nuevas.';
        flash_ok($titulo);
        echo '<section class="card">';
        echo '<h2 style="margin-top:0">Resultado de generación</h2>';
        echo '<p class="muted">Período: <strong>' . h($periodo) . '</strong></p>';
        echo '<ul>';
        echo '<li>Alumnos elegibles evaluados: <strong>' . $elegibles . '</strong></li>';
        echo '<li>Cuotas creadas: <strong>' . $creadas . '</strong></li>';
        echo '<li>Cuotas omitidas (ya existían): <strong>' . $omitidas . '</strong></li>';
        echo '</ul>';
        echo '</section>';
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
