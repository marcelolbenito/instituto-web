<?php
declare(strict_types=1);

/**
 * Activa postgrado/postítulo desde Excel (solo administrador).
 * Equivalente a: php tools/activar_postitulo_excel.php [--dry-run] [--yes]
 *
 * Excel esperado en la raíz del proyecto: LISTADO COMPLETO POSTITULO.xlsx
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/ActivarPostituloExcel.php';
require_once dirname(__DIR__) . '/src/Auth.php';

$pdo = web_init($config);
auth_require_admin();

$excelPath = dirname(__DIR__) . '/LISTADO COMPLETO POSTITULO.xlsx';
$excelOk = is_readable($excelPath);
$reportText = null;
$applyResult = null;
$error = null;

$runPreview = $excelOk && ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_GET['preview']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_write();
    $action = (string) ($_POST['action'] ?? '');
    $confirm = (string) ($_POST['confirmar'] ?? '') === '1';

    if (!$excelOk) {
        $error = 'No se encuentra el Excel en la raíz del proyecto: LISTADO COMPLETO POSTITULO.xlsx';
    } elseif ($action === 'aplicar') {
        if (!$confirm) {
            $error = 'Marcá la casilla de confirmación para aplicar.';
        } else {
            try {
                $rows = ActivarPostituloExcel::readExcel($excelPath);
                $enriched = ActivarPostituloExcel::enrich($pdo, $rows);
                $applyResult = ActivarPostituloExcel::apply($pdo, $enriched);
                ob_start();
                ActivarPostituloExcel::printReport($enriched, false);
                $reportText = ob_get_clean();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if ($error === null && $runPreview && $excelOk) {
    try {
        $rows = ActivarPostituloExcel::readExcel($excelPath);
        $enriched = ActivarPostituloExcel::enrich($pdo, $rows);
        ob_start();
        ActivarPostituloExcel::printReport($enriched, true);
        $reportText = ob_get_clean();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

layout_start($config, 'Activar postítulo desde Excel');
echo '<h1>Activar postgrado / postítulo desde Excel</h1>';
echo '<p class="muted">Lee <code>LISTADO COMPLETO POSTITULO.xlsx</code> (columna A = artículo, D = DNI). '
    . 'Activa alumnos, asigna <code>tipo_alumno=postgrado</code> y vincula concepto. '
    . '<strong>No desactiva</strong> a nadie ni genera cuotas.</p>';

if (!$excelOk) {
    echo '<p class="err">Subí el archivo <code>LISTADO COMPLETO POSTITULO.xlsx</code> a la raíz del proyecto '
        . '(misma carpeta que <code>public/</code> y <code>src/</code>).</p>';
} else {
    echo '<p class="ok">Excel encontrado: <code>' . h(basename($excelPath)) . '</code></p>';
}

if ($error !== null) {
    flash_err($error);
}

if ($applyResult !== null) {
    flash_ok(
        'Aplicado: ' . (int) $applyResult['activados'] . ' alumnos pasaron a activo; '
        . (int) $applyResult['conceptos'] . ' vínculos alumno_articulo nuevos.'
    );
}

if ($reportText !== null && $reportText !== '') {
    echo '<section class="card"><h2 style="margin-top:0">' . ($applyResult !== null ? 'Resultado' : 'Vista previa (sin cambios)') . '</h2>';
    echo '<pre class="report-pre" style="white-space:pre-wrap;font-size:0.9rem;margin:0">' . h($reportText) . '</pre>';
    echo '</section>';
}

if ($excelOk) {
    echo '<form method="post" class="form" style="max-width:36rem;margin-top:1rem">';
    echo '<input type="hidden" name="action" value="aplicar">';
    echo '<label class="check"><input type="checkbox" name="confirmar" value="1" required> ';
    echo 'Confirmo: quiero activar los alumnos del Excel y vincular sus conceptos</label>';
    echo '<div class="form-actions">';
    echo '<button type="submit" class="btn-primary">Aplicar activación</button>';
    echo ' <a class="btn-secondary" href="activar_postitulo.php">Actualizar vista previa</a>';
    echo '</div></form>';
}

echo '<p class="muted" style="margin-top:1.5rem">Recomendación: hacé backup de la BD antes de aplicar. '
    . 'Después verificá en <a href="alumnos.php?activo=activos">Alumnos → Activos</a>.</p>';
echo '<p><a href="index.php">Inicio</a></p>';

layout_end();
