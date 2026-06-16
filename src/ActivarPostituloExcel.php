<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxMinimal.php';
require_once __DIR__ . '/util.php';

/**
 * Activa alumnos postgrado/postítulo desde Excel (col A=articulo_id, D=DNI).
 */
final class ActivarPostituloExcel
{
    /**
     * @return list<array{articulo_id:int,dni:string,apellido:string,nombre:string}>
     */
    public static function readExcel(string $path): array
    {
        $sheet = XlsxMinimal::readFirstSheet($path);
        $rows = [];
        foreach ($sheet as $i => $r) {
            if ($i < 2) {
                continue;
            }
            $artRaw = $r[0] ?? null;
            $dniRaw = $r[3] ?? null;
            if ($artRaw === null || $dniRaw === null) {
                continue;
            }
            $articuloId = (int) $artRaw;
            if ($articuloId <= 0) {
                continue;
            }
            $dni = is_numeric($dniRaw) ? (string) (int) $dniRaw : trim((string) $dniRaw);
            if ($dni === '') {
                continue;
            }
            $rows[] = [
                'articulo_id' => $articuloId,
                'dni' => $dni,
                'apellido' => trim((string) ($r[1] ?? '')),
                'nombre' => trim((string) ($r[2] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{articulo_id:int,dni:string,apellido:string,nombre:string}> $rows
     * @return list<array<string,mixed>>
     */
    public static function enrich(PDO $pdo, array $rows): array
    {
        $stAl = $pdo->prepare(
            'SELECT id, activo, COALESCE(nombre_completo, \'\') AS nom, COALESCE(tipo_alumno, \'regular\') AS tipo
             FROM alumnos WHERE TRIM(documento) = TRIM(?) LIMIT 1'
        );
        $stArt = $pdo->prepare('SELECT id, COALESCE(detalle, \'\') AS det, activo FROM articulos WHERE id = ? LIMIT 1');
        $stAa = $pdo->prepare(
            'SELECT 1 FROM alumno_articulo WHERE alumno_id = ? AND articulo_id = ? LIMIT 1'
        );

        $out = [];
        foreach ($rows as $row) {
            $enriched = $row;
            $stAl->execute([$row['dni']]);
            $al = $stAl->fetch(PDO::FETCH_ASSOC);
            if (!$al) {
                $enriched['error'] = 'DNI no encontrado en alumnos';
                $enriched['alumno_id'] = null;
                $out[] = $enriched;
                continue;
            }
            $enriched['alumno_id'] = (int) $al['id'];
            $enriched['activo_antes'] = (int) $al['activo'];
            $enriched['nombre_bd'] = (string) $al['nom'];
            $enriched['tipo_antes'] = (string) $al['tipo'];

            $stArt->execute([$row['articulo_id']]);
            $art = $stArt->fetch(PDO::FETCH_ASSOC);
            if (!$art) {
                $enriched['error'] = 'articulo_id ' . $row['articulo_id'] . ' no existe';
            } elseif ((int) $art['activo'] !== 1) {
                $enriched['error'] = 'articulo_id ' . $row['articulo_id'] . ' inactivo en articulos';
            } else {
                $enriched['articulo_detalle'] = (string) $art['det'];
                $stAa->execute([$enriched['alumno_id'], $row['articulo_id']]);
                $enriched['ya_tiene_concepto'] = (bool) $stAa->fetchColumn();
            }
            $out[] = $enriched;
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $enriched
     */
    public static function printReport(array $enriched, bool $dryRun): int
    {
        $ok = array_values(array_filter($enriched, static fn (array $r): bool => empty($r['error'])));
        $err = array_values(array_filter($enriched, static fn (array $r): bool => !empty($r['error'])));
        $activar = array_values(array_filter($ok, static fn (array $r): bool => (int) ($r['activo_antes'] ?? 0) !== 1));
        $yaActivos = array_values(array_filter($ok, static fn (array $r): bool => (int) ($r['activo_antes'] ?? 0) === 1));
        $nuevosConcepto = array_values(array_filter($ok, static fn (array $r): bool => empty($r['ya_tiene_concepto'])));

        $prefix = $dryRun ? 'DRY-RUN — ' : '';
        echo "\n=== {$prefix}Resumen ===\n";
        echo 'Filas Excel:        ' . count($enriched) . "\n";
        echo 'OK (matchean BD):   ' . count($ok) . "\n";
        echo 'Errores:            ' . count($err) . "\n";
        echo 'A activar (inactivos): ' . count($activar) . "\n";
        echo 'Ya activos:         ' . count($yaActivos) . "\n";
        echo 'Concepto a vincular: ' . count($nuevosConcepto) . " filas (INSERT IGNORE alumno_articulo)\n";

        $byArt = [];
        foreach ($ok as $r) {
            $aid = (int) $r['articulo_id'];
            $byArt[$aid] = ($byArt[$aid] ?? 0) + 1;
        }
        if ($byArt !== []) {
            ksort($byArt);
            $parts = [];
            foreach ($byArt as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
            echo 'Por articulo_id: ' . implode(', ', $parts) . "\n";
        }

        if ($err !== []) {
            echo "\nErrores (hasta 25):\n";
            foreach (array_slice($err, 0, 25) as $r) {
                $nom = trim(($r['apellido'] ?? '') . ', ' . ($r['nombre'] ?? ''), ', ');
                echo '  DNI ' . $r['dni'] . ' | art ' . $r['articulo_id'] . ' | ' . $r['error'] . ' | ' . $nom . "\n";
            }
        }

        if ($dryRun && $activar !== []) {
            echo "\nMuestra a activar (hasta 10):\n";
            foreach (array_slice($activar, 0, 10) as $r) {
                echo '  id=' . $r['alumno_id'] . ' DNI ' . $r['dni'] . ' art=' . $r['articulo_id']
                    . ' | ' . ($r['nombre_bd'] ?? '') . "\n";
            }
        }

        return count($err);
    }

    /**
     * @param list<array<string,mixed>> $enriched
     * @return array{activados:int,conceptos:int}
     */
    public static function apply(PDO $pdo, array $enriched): array
    {
        $hasTipo = db_has_column($pdo, 'alumnos', 'tipo_alumno');
        $hasEstado = db_has_column($pdo, 'alumnos', 'estado_cuenta');
        $hasFechaIna = db_has_column($pdo, 'alumnos', 'fecha_inactivacion');

        $sets = ['activo = 1'];
        if ($hasEstado) {
            $sets[] = "estado_cuenta = 'activo'";
        }
        if ($hasFechaIna) {
            $sets[] = 'fecha_inactivacion = NULL';
        }
        if ($hasTipo) {
            $sets[] = "tipo_alumno = 'postgrado'";
        }
        $setSql = implode(', ', $sets);

        $stUp = $pdo->prepare("UPDATE alumnos SET {$setSql} WHERE id = ? AND TRIM(documento) = TRIM(?)");
        $stAa = $pdo->prepare('INSERT IGNORE INTO alumno_articulo (alumno_id, articulo_id) VALUES (?, ?)');

        $activados = 0;
        $conceptos = 0;
        $pdo->beginTransaction();
        try {
            foreach ($enriched as $row) {
                if (!empty($row['error']) || empty($row['alumno_id'])) {
                    continue;
                }
                $alumnoId = (int) $row['alumno_id'];
                $articuloId = (int) $row['articulo_id'];
                $stUp->execute([$alumnoId, $row['dni']]);
                if ((int) ($row['activo_antes'] ?? 0) !== 1) {
                    $activados++;
                }
                $stAa->execute([$alumnoId, $articuloId]);
                if (empty($row['ya_tiene_concepto'])) {
                    $conceptos++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['activados' => $activados, 'conceptos' => $conceptos];
    }
}
