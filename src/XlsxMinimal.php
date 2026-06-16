<?php
declare(strict_types=1);

/**
 * Lectura mínima de .xlsx (primera hoja) sin librerías externas.
 * Requiere ext-zip y ext-simplexml.
 */
final class XlsxMinimal
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return list<list<mixed>> Filas como listas indexadas 0..n (A=0, B=1, …)
     */
    public static function readFirstSheet(string $path): array
    {
        if (!is_readable($path)) {
            throw new InvalidArgumentException('No se puede leer el archivo: ' . $path);
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Falta ext-zip en PHP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No es un .xlsx válido: ' . $path);
        }

        $shared = self::readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('No se encontró la hoja 1 en el Excel.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('XML de hoja inválido.');
        }

        $ns = self::NS;
        $sheetData = $sheet->children($ns)->sheetData ?? null;
        if ($sheetData === null) {
            return [];
        }

        $rowsOut = [];
        foreach ($sheetData->children($ns) as $rowEl) {
            $rowNum = (int) self::attr($rowEl, 'r');
            $cells = [];
            $colFallback = 0;
            foreach ($rowEl->children($ns) as $cell) {
                $ref = self::attr($cell, 'r');
                if ($ref !== '') {
                    $col = self::columnIndexFromRef($ref);
                } else {
                    $col = $colFallback;
                    $colFallback++;
                }
                $cells[$col] = self::cellValue($cell, $shared, $ns);
            }
            if ($cells === []) {
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($c = 0; $c <= $max; $c++) {
                $line[] = $cells[$c] ?? null;
            }
            $rowsOut[$rowNum] = $line;
        }

        ksort($rowsOut);

        return array_values($rowsOut);
    }

    /**
     * @return list<string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }

        $ns = self::NS;
        $out = [];
        foreach ($root->children($ns) as $si) {
            $text = '';
            foreach ($si->children($ns) as $child) {
                if ($child->getName() === 't') {
                    $text .= (string) $child;
                }
                if ($child->getName() === 'r') {
                    foreach ($child->children($ns) as $rt) {
                        if ($rt->getName() === 't') {
                            $text .= (string) $rt;
                        }
                    }
                }
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param list<string> $shared
     * @return mixed
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared, string $ns)
    {
        $type = self::attr($cell, 't');
        if ($type === 'inlineStr') {
            $is = $cell->children($ns)->is ?? null;
            if ($is === null) {
                return '';
            }
            $t = $is->children($ns)->t ?? null;

            return $t !== null ? (string) $t : '';
        }
        $v = $cell->children($ns)->v ?? null;
        if ($v === null) {
            return null;
        }
        $raw = (string) $v;
        if ($type === 's') {
            $idx = (int) $raw;

            return $shared[$idx] ?? '';
        }
        if (is_numeric($raw)) {
            return strpos($raw, '.') !== false ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    private static function attr(SimpleXMLElement $el, string $name): string
    {
        $a = $el->attributes();

        return $a !== null && isset($a[$name]) ? (string) $a[$name] : '';
    }

    private static function columnIndexFromRef(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $letters = $m[1];
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $n - 1;
    }
}
