<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';
require_once dirname(__DIR__) . '/src/OperativoCobranza.php';

$pdo = web_init($config);

$hasDiasHabiles = db_has_column($pdo, 'parametros_cobranza', 'dias_habiles_tope_pronto_pago');
$hasInteresFijo = db_has_column($pdo, 'parametros_cobranza', 'importe_interes_mora_fijo');
$hasPostgradoRango = db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_desde')
    && db_has_column($pdo, 'parametros_cobranza', 'postgrado_mes_hasta');
$hasOperativo = operativo_schema_ok($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_write();
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

    $operativoPeriodo = null;
    $operativoErr = '';
    if ($hasOperativo) {
        if (!empty($_POST['operativo_limpiar'])) {
            $operativoPeriodo = null;
        } else {
            $rawMes = trim((string) ($_POST['operativo_periodo_desde'] ?? ''));
            if ($rawMes === '') {
                $operativoPeriodo = operativo_periodo_desde_bd($pdo);
            } else {
                $operativoPeriodo = operativo_periodo_normalizar_input($rawMes);
                if ($operativoPeriodo === null) {
                    $operativoErr = 'Período operativo inválido (use año-mes, ej. 2026-06).';
                }
            }
        }
    }
    if ($operativoErr !== '') {
        header('Location: parametros_cobranza.php?err=' . rawurlencode($operativoErr));
        exit;
    }

    if ($hasOperativo) {
        $st = $pdo->prepare(
            'UPDATE parametros_cobranza SET
                dia_generacion_cuota = ?,
                dia_tope_pronto_pago = ?,
                dias_habiles_tope_pronto_pago = ?,
                postgrado_mes_desde = ?,
                postgrado_mes_hasta = ?,
                recargo_coeficiente = ?,
                importe_interes_mora_fijo = ?,
                bonificacion_pronto_pago = ?,
                operativo_periodo_desde = ?
             WHERE id = 1'
        );
        $st->execute([$diaGen, $diaTopeMes, $diasHabiles, $pgDesde, $pgHasta, $recargo, $interesFijo, $boni, $operativoPeriodo]);
    } else {
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
    }

    $recalcMsg = '';
    if ($hasOperativo && !empty($_POST['recalcular_saldos'])) {
        $n = recalcular_saldo_alumnos($pdo);
        $recalcMsg = ' Saldos recalculados (' . $n . ' alumnos).';
    }

    header('Location: parametros_cobranza.php?ok=' . rawurlencode('Parámetros guardados.' . $recalcMsg));
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

$operativoCfg = operativo_resumen_config($pdo);
$operativoValorForm = operativo_periodo_desde_bd($pdo) ?? '';

layout_start($config, 'Parámetros de cobranza');
if (isset($_GET['ok'])) {
    flash_ok((string) $_GET['ok']);
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Parámetros de cobranza</h1>';
echo '<p class="muted">Reglas globales de cobranza, mora/pronto pago y <strong>período operativo</strong> '
    . '(qué cuotas y movimientos se muestran en cobro, cuenta corriente e informes).</p>';

if (!$hasDiasHabiles || !$hasInteresFijo) {
    echo '<p class="err">Ejecutá la migración <code>sql/migracion/15_parametros_cobranza_pronto_pago_habiles.sql</code> para habilitar días hábiles e interés fijo.</p>';
}
if (!$hasPostgradoRango) {
    echo '<p class="warn">Ejecutá la migración <code>sql/migracion/21_tipo_alumno_y_periodo_postgrado.sql</code> para parametrizar meses de postgrado.</p>';
}
if (!$hasOperativo) {
    echo '<p class="warn">Ejecutá la migración <code>sql/migracion/39_operativo_periodo_desde_compat.sql</code> para configurar el período operativo desde esta pantalla.</p>';
}

echo '<form method="post" class="form form-grid" id="form-param-cobranza" autocomplete="off" style="max-width:44rem">';

if ($hasOperativo) {
    echo '<h2>Período operativo (vista ordenada)</h2>';
    echo '<p class="muted small">Oculta cuotas y movimientos <strong>anteriores</strong> al mes elegido en cobro, '
        . 'cuenta corriente (vista operativa), morosos y saldos. <strong>No borra datos</strong> del histórico migrado.</p>';
    echo '<label>Operar desde (año-mes) ';
    echo '<input type="month" name="operativo_periodo_desde" value="' . h($operativoValorForm) . '" autocomplete="off"></label>';
    echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">Ejemplo cliente en producción desde junio 2026: <strong>2026-06</strong>.</p>';
    echo '<label class="checkbox"><input type="checkbox" name="recalcular_saldos" value="1" checked autocomplete="off"> '
        . 'Recalcular saldos de todos los alumnos al guardar</label>';
    echo '<p class="muted small">Vista efectiva ahora: <strong>' . h($operativoCfg['etiqueta']) . '</strong> '
        . '(origen: ' . h($operativoCfg['origen']) . ').</p>';
    if ($operativoCfg['configurado'] && $operativoCfg['fecha_corte'] !== null) {
        echo '<p class="ok flash" style="margin:0.5rem 0">Fecha de corte para saldos: <strong>'
            . h($operativoCfg['fecha_corte_txt'] ?? $operativoCfg['fecha_corte']) . '</strong>'
            . ' (<code>' . h($operativoCfg['fecha_corte']) . '</code>).</p>';
    } elseif (!$operativoCfg['configurado']) {
        echo '<p class="warn flash" style="margin:0.5rem 0">Todavía no hay mes de inicio configurado. '
            . 'Elija <strong>2026-06</strong> arriba y pulse Guardar para operar desde el 1° de junio de 2026.</p>';
    }
}

echo '<h2>Generación y mora</h2>';
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

if ($hasOperativo && $operativoCfg['configurado']) {
    echo '<details class="param-advanced-box">';
    echo '<summary>Acción avanzada: quitar período operativo</summary>';
    echo '<p class="muted small">Solo si querés <strong>resetear</strong> el mes guardado y volver al comportamiento '
        . 'por defecto (año 2026 sin mes fijo). No es necesario para el uso normal.</p>';
    echo '<label class="checkbox checkbox-danger">';
    echo '<input type="checkbox" name="operativo_limpiar" id="operativo-limpiar" value="1" autocomplete="off"> ';
    echo 'Quitar el mes configurado en la base de datos</label>';
    echo '</details>';
}

echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form>';

if ($hasOperativo) {
    echo '<script>
(() => {
  const form = document.getElementById("form-param-cobranza");
  const limpiar = document.getElementById("operativo-limpiar");
  if (!form) return;
  form.addEventListener("submit", (e) => {
    if (limpiar && limpiar.checked) {
      const ok = window.confirm(
        "¿Quitar el período operativo guardado?\\n\\n"
        + "El sistema dejará de filtrar por el mes configurado. "
        + "Esta acción solo tiene efecto si confirma al guardar."
      );
      if (!ok) e.preventDefault();
    }
  });
})();
</script>';
}

echo '<p><a href="index.php">Inicio</a> · <a href="generar_cuotas.php">Generar cuotas</a> · <a href="alumnos.php">Alumnos (recalcular saldos)</a></p>';

layout_end();
