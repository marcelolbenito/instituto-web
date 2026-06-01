<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/ParametrosFe.php';
require_once dirname(__DIR__) . '/src/InstitutoLogo.php';

$pdo = Db::pdo($config);
$tablaOk = fe_parametros_tabla_ok($pdo);
$emisorOk = $tablaOk && fe_emisor_tabla_extendida_ok($pdo);
$logoOk = $tablaOk && instituto_logo_tabla_ok($pdo);

if ($tablaOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $msgLogo = '';
    if ($logoOk && !empty($_POST['eliminar_logo'])) {
        $rLogo = instituto_logo_eliminar($pdo);
        if (!$rLogo['ok']) {
            header('Location: parametros_factura_electronica.php?err=' . rawurlencode($rLogo['msg']));
            exit;
        }
        $msgLogo = $rLogo['msg'];
    } elseif ($logoOk && isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $rLogo = instituto_logo_subir($pdo, $_FILES['logo']);
        if (!$rLogo['ok']) {
            header('Location: parametros_factura_electronica.php?err=' . rawurlencode($rLogo['msg']));
            exit;
        }
        if ($rLogo['msg'] !== 'Sin archivo nuevo.') {
            $msgLogo = $rLogo['msg'];
        }
    }

    $res = fe_parametros_guardar($pdo, $_POST);
    if ($res['ok']) {
        $q = 'ok=1';
        if ($msgLogo !== '') {
            $q .= '&logo_ok=' . rawurlencode($msgLogo);
        }
        header('Location: parametros_factura_electronica.php?' . $q);
    } else {
        header('Location: parametros_factura_electronica.php?err=' . rawurlencode($res['msg']));
    }
    exit;
}

$row = $tablaOk ? fe_parametros_cargar($pdo) : null;
$cfgVista = fe_gesis_config($config, $pdo);
$emisorVista = fe_emisor_desde_fila($row, $config);
$faltanImp = $emisorOk ? fe_emisor_campos_faltantes_impresion($emisorVista) : [];

layout_start($config, 'Parámetros factura electrónica');
if (isset($_GET['ok'])) {
    echo '<p class="ok">Parámetros guardados.';
    if (!empty($_GET['logo_ok'])) {
        echo ' ' . h((string) $_GET['logo_ok']);
    }
    echo '</p>';
}
if (isset($_GET['err'])) {
    echo '<p class="err">' . h((string) $_GET['err']) . '</p>';
}

echo '<h1>Parámetros — factura electrónica (Gesis)</h1>';
echo '<p class="muted">Datos para emitir por Gesis/ARCA y para la <strong>impresión</strong> del comprobante '
    . '(razón social, domicilio, inicio de actividades, IIBB, QR, etc.). '
    . 'El receptor sale de la ficha de cada alumno al facturar un recibo.</p>';

if (!$tablaOk) {
    echo '<p class="err">Ejecute la migración <code>sql/migracion/31_parametros_factura_electronica_compat.sql</code>.</p>';
    layout_end();
    exit;
}

if (!$emisorOk) {
    echo '<p class="warn">Para los datos completos del emisor en la impresión, ejecute también '
        . '<code>sql/migracion/32_parametros_fe_datos_emisor_compat.sql</code>.</p>';
}

if ($emisorOk && count($faltanImp) > 0) {
    echo '<p class="warn">Faltan datos obligatorios para imprimir facturas: <strong>'
        . h(implode(', ', $faltanImp)) . '</strong>.</p>';
}

$logoUrl = $logoOk ? instituto_logo_url($pdo) : null;

echo '<form method="post" class="form form-grid" enctype="multipart/form-data" style="max-width:48rem">';
echo '<h2>Logo del instituto (impresiones)</h2>';
echo '<p class="muted small">Se muestra en recibos, factura electrónica, cierre de caja y demás vistas de impresión. '
    . 'Si no subís ninguno, no aparece nada.</p>';
if (!$logoOk) {
    echo '<p class="warn">Ejecute <code>sql/migracion/33_parametros_logo_instituto_compat.sql</code> para habilitar el logo.</p>';
} else {
    if ($logoUrl !== null) {
        echo '<p><img src="' . h($logoUrl) . '" alt="Logo actual" class="instituto-logo-preview"></p>';
        echo '<label class="check"><input type="checkbox" name="eliminar_logo" value="1"> Quitar logo actual</label>';
    }
    echo '<label>Subir o reemplazar logo (JPG, PNG, GIF o WebP, máx. 2 MB)';
    echo '<input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
}

echo '<h2>Conexión Gesis</h2>';
echo '<label>URL del servicio <input name="gesis_url" type="url" value="' . h((string) ($row['gesis_url'] ?? $cfgVista['base_url'])) . '" placeholder="https://servicios.gesis2.com"></label>';
echo '<label>Email integración Gesis <input name="gesis_email" type="email" autocomplete="username" value="' . h((string) ($row['gesis_email'] ?? '')) . '" required></label>';
echo '<label>Contraseña Gesis <input name="gesis_password" type="password" autocomplete="new-password" placeholder="Dejar vacío para no cambiar la guardada"></label>';

if ($emisorOk) {
echo '<h2>Emisor — datos en la factura impresa</h2>';
echo '<p class="muted small">Según RG AFIP 4291 y formato habitual de comprobantes electrónicos: '
    . 'razón social, domicilio comercial, CUIT, condición IVA, inicio de actividades e inscripción en Ingresos Brutos.</p>';

echo '<label>Razón social / denominación <span class="req">*</span>';
echo '<input name="razon_social" maxlength="200" required value="' . h((string) ($emisorVista['razon_social'] ?? '')) . '"></label>';

echo '<label>Nombre de fantasía (opcional)';
echo '<input name="nombre_fantasia" maxlength="120" value="' . h((string) ($emisorVista['nombre_fantasia'] ?? '')) . '"></label>';

echo '<label>Domicilio comercial (calle y número) <span class="req">*</span>';
echo '<input name="domicilio_comercial" maxlength="200" required value="' . h((string) ($emisorVista['domicilio_comercial'] ?? '')) . '"></label>';

echo '<label>Localidad <input name="localidad" maxlength="80" value="' . h((string) ($emisorVista['localidad'] ?? '')) . '"></label>';
echo '<label>Provincia <input name="provincia" maxlength="60" value="' . h((string) ($emisorVista['provincia'] ?? '')) . '"></label>';
echo '<label>Código postal <input name="codigo_postal" maxlength="12" value="' . h((string) ($emisorVista['codigo_postal'] ?? '')) . '"></label>';

echo '<label>CUIT del instituto <span class="req">*</span>';
echo '<input name="cuit_emisor" maxlength="13" required inputmode="numeric" autocomplete="off" '
    . 'placeholder="30-59283090-2" value="' . h((string) ($row['cuit_emisor'] ?? '')) . '"></label>';
echo '<p class="muted small">Debe coincidir con el negocio dado de alta en Gesis/AFIP (11 dígitos, con o sin guiones).</p>';

echo '<label>Condición frente al IVA (emisor) <span class="req">*</span> <select name="condicion_iva_emisor" required>';
$condEm = (string) ($emisorVista['condicion_iva_emisor'] ?? 'monotributo');
$condsEmisor = [
    'monotributo' => 'IVA Responsable Monotributo',
    'responsable_inscripto' => 'IVA Responsable Inscripto',
    'exento' => 'IVA Sujeto Exento',
    'no_inscripto' => 'IVA No Responsable',
];
foreach ($condsEmisor as $k => $lbl) {
    $sel = $condEm === $k ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></label>';

echo '<label>Fecha de inicio de actividades <span class="req">*</span>';
echo '<input name="inicio_actividades" type="date" required value="' . h((string) ($emisorVista['inicio_actividades'] ?? '')) . '"></label>';

echo '<label>Nº inscripción Ingresos Brutos <span class="req">*</span>';
echo '<input name="ingresos_brutos" maxlength="40" required placeholder="Ej. según constancia provincial" value="'
    . h((string) ($emisorVista['ingresos_brutos'] ?? '')) . '"></label>';

echo '<label>Jurisdicción IIBB <input name="jurisdiccion_iibb" maxlength="80" placeholder="Ej. Tucumán, Convenio Multilateral" value="'
    . h((string) ($emisorVista['jurisdiccion_iibb'] ?? '')) . '"></label>';

echo '<label>Actividad principal (rubro, referencia impresión)';
echo '<input name="actividad_principal" maxlength="120" placeholder="Ej. Enseñanza privada" value="'
    . h((string) ($emisorVista['actividad_principal'] ?? '')) . '"></label>';

echo '<label>Teléfono <input name="telefono" maxlength="40" value="' . h((string) ($emisorVista['telefono'] ?? '')) . '"></label>';
echo '<label>Email de contacto (en factura) <input name="email_contacto" type="email" maxlength="120" value="'
    . h((string) ($emisorVista['email_contacto'] ?? '')) . '"></label>';
} else {
    echo '<h2>Emisor</h2>';
    echo '<label>CUIT del instituto <span class="req">*</span>';
    echo '<input name="cuit_emisor" maxlength="13" required placeholder="30123456789" value="'
        . h((string) ($row['cuit_emisor'] ?? '')) . '"></label>';
}

echo '<h2>Comprobante por defecto (emisión Gesis)</h2>';
echo '<label>Punto de venta <input name="punto_venta" type="number" min="1" max="9999" value="' . (int) ($row['punto_venta'] ?? 1) . '" required></label>';
echo '<label>Tipo comprobante AFIP <select name="cbte_tipo">';
$tipos = [
    11 => '11 — Factura C (monotributo / sin IVA discriminado)',
    6 => '6 — Factura B',
    1 => '1 — Factura A',
];
$cbteSel = (int) ($row['cbte_tipo'] ?? 11);
foreach ($tipos as $k => $lbl) {
    $sel = $cbteSel === $k ? ' selected' : '';
    echo '<option value="' . $k . '"' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></label>';
echo '<label>Concepto <select name="concepto">';
$conceptos = [1 => '1 — Productos', 2 => '2 — Servicios (recomendado instituto)', 3 => '3 — Productos y servicios'];
$cSel = (int) ($row['concepto'] ?? 2);
foreach ($conceptos as $k => $lbl) {
    $sel = $cSel === $k ? ' selected' : '';
    echo '<option value="' . $k . '"' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></label>';
$prod = !empty($row['production']);
echo '<label class="check"><input type="checkbox" name="production" value="1"' . ($prod ? ' checked' : '') . '> Producción AFIP (desmarcado = homologación)</label>';
echo '<label>Observaciones internas <textarea name="observaciones" rows="2" placeholder="Notas de uso interno (no se imprimen)">'
    . h((string) ($row['observaciones'] ?? '')) . '</textarea></label>';

echo '<p><button type="submit" class="btn-primary">Guardar parámetros</button> ';
echo '<a class="btn-secondary" href="factura_electronica.php">Ir a emitir desde recibo</a></p>';
echo '</form>';

echo '<section class="card" style="margin-top:1.5rem"><h2>Requisitos en la impresión</h2>';
echo '<table class="table"><thead><tr><th>Sección</th><th>Dato</th><th>Origen</th></tr></thead><tbody>';
$reqs = [
    ['Emisor', 'Razón social, domicilio, CUIT, condición IVA', 'Esta pantalla'],
    ['Emisor', 'Inicio de actividades, Ingresos Brutos', 'Esta pantalla'],
    ['Emisor', 'Actividad / teléfono / email (recomendado)', 'Esta pantalla'],
    ['Comprobante', 'Tipo, PV, número, fecha, CAE y vto.', 'Emisión Gesis + BD'],
    ['Comprobante', 'Período de servicios (si concepto 2/3)', 'Mes del recibo / voucher AFIP'],
    ['Comprobante', 'Código QR verificable', 'Generado al imprimir (RG 4291)'],
    ['Receptor', 'Nombre, documento/CUIT, condición IVA', 'Ficha alumno'],
    ['Receptor', 'Domicilio (si está cargado)', 'Ficha alumno'],
];
foreach ($reqs as $r) {
    echo '<tr><td>' . h($r[0]) . '</td><td>' . h($r[1]) . '</td><td>' . h($r[2]) . '</td></tr>';
}
echo '</tbody></table></section>';

layout_end();
