<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';

$pdo = web_init($config);

$hasDiasHabiles = db_has_column($pdo, 'parametros_cobranza', 'dias_habiles_tope_pronto_pago');
$hasInteresFijo = db_has_column($pdo, 'parametros_cobranza', 'importe_interes_mora_fijo');
$hasPostgradoRango = db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_desde')
    && db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_hasta');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diaGen = max(1, min(28, (int) ($_POST['dia_generacion_cuota'] ?? 1)));
    $diaTopeMes = max(1, min(31, (int) ($_POST['dia_tope_pronto_pago'] ?? 5)));
    $recargo = max(0.0, (float) str_replace(',', '.', (string) ($_POST['recargo_coeficiente'] ?? '0')));
    $boni = max(0.0, (float) str_replace(',', '.', (string) ($_POST['bonificacion_pronto_pago'] ?? '0')));
    $diasHabiles = $hasDiasHabiles ? max(1, min(30, (int) ($_POST['dias_habiles_tope_pronto_pago'] ?? 5))) : null;
    $interesFijo = $hasInteresFijo ? max(0.0, (float) str_replace(',', '.', (string) ($_POST['importe_interes_mora_fijo'] ?? '0'))) : null;
    $pgDesde = $hasPostgradoRango ? max(1, min(12, (int) ($_POST['postgrado_mes_desde'] ?? 4))) : null;
    $pgHasta = $hasPostgradoRango ? max(1, min(12, (int) ($_POST['postgrado_mes_hasta'] ?? 11))) : null;

    if (!$hasDiasHabiles || !$hasInteresFijo) {
        header(
            'Location: parametros_cobranza.php?err=' . rawurlencode(
                'Falta migración 15: ejecutá sql/migracion/15_parametros_cobranza_pronto_pago_habiles.sql'
            )
        );
        exit;
    }

    $st = $pdo->prepare(
        'UPDATE parametros_cobranza SET
            dia_generacion_cuota = ?,
            dia_tope_pronto_pago = ?,
            dias_habiles_tope_pronto_pago = ?,
            postgrado_mes_desde = ?,
            postgrado_mes_hasta = ?,
            recargo_coeficiente = ?,
            importe_interes_mora_fijo = ?,
            bonificacion_pronto_pago = ?
         WHERE id = 1'
    );
    $st->execute([$diaGen, $diaTopeMes, $diasHabiles, $pgDesde, $pgHasta, $recargo, $interesFijo, $boni]);
    header('Location: parametros_cobranza.php?ok=1');
    exit;
}

$row = $pdo->query('SELECT * FROM parametros_cobranza WHERE id = 1')->fetch();
if (!$row) {
    $pdo->exec(
        'INSERT INTO parametros_cobranza (id, dia_generacion_cuota, dia_tope_pronto_pago, recargo_coeficiente, bonificacion_pronto_pago)
         VALUES (1, 1, 5, 0, 0)'
    );
    $row = $pdo->query('SELECT * FROM parametros_cobranza WHERE id = 1')->fetch();
}

layout_start($config, 'Parámetros de cobranza');
if (isset($_GET['ok'])) {
    flash_ok('Parámetros guardados.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Parámetros de cobranza</h1>';
echo '<p class="muted">Reglas globales para pronto pago, descuento fijo e interés/mora (ex PORCEN). '
    . 'La aplicación al cobro y al recibo se implementará en el módulo de cobros; acá se persisten los valores.</p>';

if (!$hasDiasHabiles || !$hasInteresFijo) {
    echo '<p class="err">Ejecutá la migración <code>sql/migracion/15_parametros_cobranza_pronto_pago_habiles.sql</code> para habilitar días hábiles e interés fijo.</p>';
}
if (!$hasPostgradoRango) {
    echo '<p class="err">Ejecutá la migración <code>sql/migracion/21_tipo_alumno_y_periodo_postgrado.sql</code> para parametrizar meses de postgrado.</p>';
}

echo '<form method="post" class="form form-grid" style="max-width:40rem">';
echo '<label>Día del mes para generar cuota (1–28) <input type="number" name="dia_generacion_cuota" min="1" max="28" required value="'
    . (int) ($row['dia_generacion_cuota'] ?? 1) . '"></label>';
echo '<label>Día del mes (legacy Fox / referencia) <input type="number" name="dia_tope_pronto_pago" min="1" max="31" required value="'
    . (int) ($row['dia_tope_pronto_pago'] ?? 5) . '"></label>';
echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Campo histórico tipo PORCEN.DIA; la regla operativa principal es la de <strong>días hábiles</strong> abajo.</p>';

if ($hasDiasHabiles) {
    echo '<label>Días hábiles tope pronto pago (desde el día 1 del mes del período) <input type="number" name="dias_habiles_tope_pronto_pago" min="1" max="30" required value="'
        . (int) ($row['dias_habiles_tope_pronto_pago'] ?? 5) . '"></label>';
    echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Si la <strong>fecha de pago</strong> cae dentro de esos días hábiles (contados desde el 1 del mes de la cuota), aplica el <strong>descuento fijo</strong>. Si paga después, aplica interés/mora según los importes coeficientes abajo.</p>';
}
if ($hasPostgradoRango) {
    echo '<label>Postgrado: mes desde (1-12) <input type="number" name="postgrado_mes_desde" min="1" max="12" required value="'
        . (int) ($row['postgrado_mes_desde'] ?? 4) . '"></label>';
    echo '<label>Postgrado: mes hasta (1-12) <input type="number" name="postgrado_mes_hasta" min="1" max="12" required value="'
        . (int) ($row['postgrado_mes_hasta'] ?? 11) . '"></label>';
    echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Los alumnos <strong>regulares</strong> generan cuota todos los meses; los de <strong>postgrado</strong> sólo dentro de este rango.</p>';
}

echo '<label>Descuento fijo pronto pago (ARS) <input name="bonificacion_pronto_pago" type="number" step="0.01" min="0" required value="'
    . h(number_format((float) ($row['bonificacion_pronto_pago'] ?? 0), 2, '.', '')) . '"></label>';

echo '<label>Recargo por mora — % mensual (Fox RECARGO, 0 = off) <input name="recargo_coeficiente" type="number" step="0.01" min="0" required value="'
    . h((string) ($row['recargo_coeficiente'] ?? '0')) . '"></label>';
echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Ej. <strong>5</strong> = 5% mensual → 5÷30 ≈ 0,167% por día. '
    . 'Con 21 días de mora: ≈ 3,5% sobre el saldo. El sistema divide por 30 y por 100 como Fox.</p>';

if ($hasInteresFijo) {
    echo '<label>Interés / mora fijo después del tope (ARS) <input name="importe_interes_mora_fijo" type="number" step="0.01" min="0" required value="'
        . h(number_format((float) ($row['importe_interes_mora_fijo'] ?? 0), 2, '.', '')) . '"></label>';
    echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Importe fijo adicional cuando el pago supera los días hábiles de tope (además del coeficiente diario, si lo usás).</p>';
}

echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form>';

echo '<p><a href="index.php">Inicio</a> · <a href="generar_cuotas.php">Generar cuotas</a></p>';

layout_end();
