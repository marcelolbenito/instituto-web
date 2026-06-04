<?php
declare(strict_types=1);

/**
 * Clasificación de regularidad (misma lógica que alumnos.php).
 *
 * @return array{key: string, label: string, class: string, dias: int|null}
 */
function regularidad_clasificar(bool $activo, ?string $ultimoPago): array
{
    if (!$activo) {
        return ['key' => 'no_activo', 'label' => 'Inactivo (alumno)', 'class' => 'badge-muted', 'dias' => null];
    }
    if ($ultimoPago === null || trim($ultimoPago) === '') {
        return ['key' => 'sin_pagos', 'label' => 'Sin pagos', 'class' => 'badge-muted', 'dias' => null];
    }
    $ts = strtotime($ultimoPago);
    if ($ts === false) {
        return ['key' => 'sin_pagos', 'label' => 'Sin pagos', 'class' => 'badge-muted', 'dias' => null];
    }
    $dias = (int) floor((time() - $ts) / 86400);
    if ($dias <= 45) {
        return ['key' => 'regular', 'label' => 'Regular', 'class' => 'badge-ok', 'dias' => $dias];
    }
    if ($dias <= 90) {
        return ['key' => 'riesgo', 'label' => 'Riesgo', 'class' => 'badge-warn', 'dias' => $dias];
    }

    return ['key' => 'irregular', 'label' => 'Irregular', 'class' => 'badge-err', 'dias' => $dias];
}

/**
 * @param list<string> $keys
 */
function regularidad_coincide(array $keys, string $regKey): bool
{
    if ($keys === []) {
        return true;
    }

    return in_array($regKey, $keys, true);
}
