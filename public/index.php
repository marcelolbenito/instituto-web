<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/ParametrosFe.php';
require_once dirname(__DIR__) . '/src/InstitutoLogo.php';

$dbOk = false;
$nombreInstituto = trim((string) ($config['app']['name'] ?? 'Instituto'));
$logoInstitutoUrl = null;
$stats = [
    'alumnos' => 0,
    'alumnos_activos' => 0,
    'articulos' => 0,
    'cuotas_pendientes' => 0,
    'cobros_mes' => 0.0,
];
$estadoCuotas = ['pendiente' => 0, 'parcial' => 0, 'pagada' => 0];
$cobrosPorMes = [];
$meses = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
try {
    $pdo = web_init($config);
    $pdo->query('SELECT 1');
    $dbOk = true;

    $emisor = fe_emisor_cargar($pdo, $config);
    $nombreInstituto = trim((string) ($emisor['nombre_fantasia'] ?? ''));
    if ($nombreInstituto === '') {
        $nombreInstituto = trim((string) ($emisor['razon_social'] ?? ''));
    }
    if ($nombreInstituto === '') {
        $nombreInstituto = trim((string) ($config['app']['name'] ?? 'Instituto'));
    }
    $logoInstitutoUrl = instituto_logo_url($pdo);

    $stats['alumnos'] = (int) $pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn();
    $stats['alumnos_activos'] = (int) $pdo->query('SELECT COUNT(*) FROM alumnos WHERE activo = 1')->fetchColumn();
    $stats['articulos'] = (int) $pdo->query('SELECT COUNT(*) FROM articulos WHERE activo = 1')->fetchColumn();
    $stats['cuotas_pendientes'] = (int) $pdo->query("SELECT COUNT(*) FROM cuota_mensual WHERE estado IN ('pendiente','parcial')")->fetchColumn();
    $filtroAnul = '';
    try {
        require_once dirname(__DIR__) . '/src/PagoAnulacion.php';
        if (pago_anulacion_schema_ok($pdo)) {
            $filtroAnul = ' AND ' . pago_sql_solo_vigentes();
        }
    } catch (Throwable $e) {
        $filtroAnul = '';
    }
    $stCobroMes = $pdo->query(
        'SELECT COALESCE(SUM(importe), 0) FROM pago_registrado
         WHERE YEAR(fecha_pago) = YEAR(CURDATE()) AND MONTH(fecha_pago) = MONTH(CURDATE())'
        . $filtroAnul
    );
    $stats['cobros_mes'] = (float) $stCobroMes->fetchColumn();

    $stEstado = $pdo->query("SELECT estado, COUNT(*) c FROM cuota_mensual GROUP BY estado");
    foreach ($stEstado->fetchAll() as $row) {
        $estado = (string) $row['estado'];
        if (array_key_exists($estado, $estadoCuotas)) {
            $estadoCuotas[$estado] = (int) $row['c'];
        }
    }

    $stMes = $pdo->query('
        SELECT YEAR(fecha_pago) anio, MONTH(fecha_pago) mes, COALESCE(SUM(importe), 0) total
        FROM pago_registrado
        WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)'
        . $filtroAnul . '
        GROUP BY YEAR(fecha_pago), MONTH(fecha_pago)
        ORDER BY YEAR(fecha_pago), MONTH(fecha_pago)
    ');
    foreach ($stMes->fetchAll() as $row) {
        $key = (int) $row['anio'] . '-' . str_pad((string) ((int) $row['mes']), 2, '0', STR_PAD_LEFT);
        $label = $meses[(int) $row['mes']] . ' ' . (int) $row['anio'];
        $cobrosPorMes[$key] = ['label' => $label, 'total' => (float) $row['total']];
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    error_log('index.php DB: ' . $dbError);
}

layout_start($config, 'Inicio');
?>
<header class="inicio-brand">
<?php if ($logoInstitutoUrl !== null) { ?>
    <div class="inicio-logo-wrap instituto-logo-wrap">
        <img src="<?= h($logoInstitutoUrl) ?>" alt="" class="instituto-logo-print inicio-logo" width="220" height="80">
    </div>
<?php } ?>
    <h1><?= h($nombreInstituto) ?></h1>
</header>

<section class="dashboard-grid">
    <article class="kpi"><div class="kpi-label">Alumnos activos</div><div class="kpi-value"><?= number_format($stats['alumnos_activos'], 0, ',', '.') ?></div></article>
    <article class="kpi"><div class="kpi-label">Alumnos (total)</div><div class="kpi-value"><?= number_format($stats['alumnos'], 0, ',', '.') ?></div></article>
    <article class="kpi"><div class="kpi-label">Artículos activos</div><div class="kpi-value"><?= number_format($stats['articulos'], 0, ',', '.') ?></div></article>
    <article class="kpi"><div class="kpi-label">Cuotas pendientes/parcial</div><div class="kpi-value"><?= number_format($stats['cuotas_pendientes'], 0, ',', '.') ?></div></article>
    <article class="kpi"><div class="kpi-label">Cobrado este mes</div><div class="kpi-value">$ <?= number_format($stats['cobros_mes'], 2, ',', '.') ?></div></article>
</section>

<section class="card">
    <h2>Acciones rápidas</h2>
    <div class="quick-actions">
        <a class="qa-item" href="alumnos.php" title="Alumnos"><span class="qa-icon">👥</span><span class="qa-label">Alumnos</span></a>
        <a class="qa-item" href="cuenta_corriente.php" title="Cuenta corriente"><span class="qa-icon">💳</span><span class="qa-label">Cta Cte</span></a>
        <a class="qa-item" href="registrar_cobro.php" title="Registrar cobro"><span class="qa-icon">💵</span><span class="qa-label">Cobro</span></a>
        <a class="qa-item" href="caja.php" title="Caja del día: movimientos, arqueo y cerrar hoy"><span class="qa-icon">🏧</span><span class="qa-label">Caja del día</span></a>
        <a class="qa-item" href="caja_cierres.php" title="Consultar días de caja ya cerrados"><span class="qa-icon">📒</span><span class="qa-label">Historial de cierres</span></a>
        <a class="qa-item" href="ajuste_debe.php" title="Carga manual"><span class="qa-icon">📝</span><span class="qa-label">Carga Manual</span></a>
        <a class="qa-item" href="articulos.php" title="Artículos"><span class="qa-icon">📦</span><span class="qa-label">Artículos</span></a>
        <a class="qa-item" href="conceptos_alumno.php" title="Conceptos"><span class="qa-icon">✅</span><span class="qa-label">Conceptos</span></a>
        <a class="qa-item" href="generar_cuotas.php" title="Generar cuotas"><span class="qa-icon">🧾</span><span class="qa-label">Generar cuotas</span></a>
    </div>
</section>

<section class="charts-grid">
    <article class="chart-card">
        <h3>Distribución de cuotas por estado</h3>
        <canvas id="chartEstadoCuotas" class="chart-canvas" width="520" height="220"></canvas>
    </article>
    <article class="chart-card">
        <h3>Cobros últimos meses</h3>
        <canvas id="chartCobrosMes" class="chart-canvas" width="520" height="220"></canvas>
    </article>
</section>
<?php if (!$dbOk) { ?>
<p class="err">No se pudo conectar con la base de datos. Si el problema continúa, contacte al administrador del sistema.</p>
<?php } ?>

<script>
(() => {
  const estadoData = <?= json_encode($estadoCuotas, JSON_UNESCAPED_UNICODE) ?>;
  const cobrosData = <?= json_encode(array_values($cobrosPorMes), JSON_UNESCAPED_UNICODE) ?>;

  function drawBarChart(canvasId, labels, values, color) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    const left = 40, right = 14, top = 14, bottom = 34;
    const chartW = w - left - right;
    const chartH = h - top - bottom;
    const max = Math.max(...values, 1);
    const barW = chartW / Math.max(values.length, 1) * 0.65;

    ctx.strokeStyle = '#d3d9e2';
    ctx.beginPath();
    ctx.moveTo(left, top);
    ctx.lineTo(left, top + chartH);
    ctx.lineTo(left + chartW, top + chartH);
    ctx.stroke();

    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#425466';

    values.forEach((v, i) => {
      const x = left + (i + 0.5) * (chartW / values.length) - barW / 2;
      const bh = (v / max) * (chartH - 8);
      const y = top + chartH - bh;
      ctx.fillStyle = color;
      ctx.fillRect(x, y, barW, bh);
      ctx.fillStyle = '#425466';
      ctx.fillText(String(v), x, y - 4);
      const label = labels[i] || '';
      ctx.fillText(label, x - 4, top + chartH + 16);
    });
  }

  const estadoLabels = ['Pendiente', 'Parcial', 'Pagada'];
  const estadoValues = [estadoData.pendiente || 0, estadoData.parcial || 0, estadoData.pagada || 0];
  drawBarChart('chartEstadoCuotas', estadoLabels, estadoValues, '#0b57d0');

  const cobroLabels = cobrosData.map((x) => x.label);
  const cobroValues = cobrosData.map((x) => Number(x.total || 0).toFixed(0));
  drawBarChart('chartCobrosMes', cobroLabels, cobroValues.map(Number), '#0f9d58');
})();
</script>
<?php
layout_end();
