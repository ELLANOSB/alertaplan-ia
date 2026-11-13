<?php
declare(strict_types=1);

namespace App;

use RuntimeException;
use ZipArchive;

/**
 * C2 · Carga e importación.
 * Lee CSV (coma o punto y coma) y XLSX (valores guardados de la primera hoja o la elegida),
 * propone el mapeo de columnas por alias y devuelve filas en el esquema común.
 */
final class Importador
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** campo del esquema => alias aceptados (normalizados) */
    public const ALIAS = [
        'codigo'       => ['id', 'codigo', 'cod', 'item', 'id actividad', 'wbs'],
        'nombre'       => ['actividad', 'descripcion', 'partida', 'nombre', 'tarea'],
        'inicio'       => ['inicio', 'fecha inicio', 'inicio plan', 'comienzo'],
        'fin'          => ['fin', 'fecha fin', 'fin plan', 'termino', 'fin planificado'],
        'total'        => ['total', 'metrado', 'cantidad total', 'cantidad', 'meta'],
        'ejecutado'    => ['ejecutado', 'avance', 'cantidad ejecutada', 'acumulado', 'real'],
        'rendimiento'  => ['rendimiento', 'rend', 'productividad', 'rendimiento dia'],
        'unidad'       => ['unidad', 'und', 'um', 'u m'],
        'sector'       => ['sector', 'frente', 'nivel', 'zona'],
        'responsable'  => ['responsable', 'encargado', 'dueno'],
        'predecesoras' => ['predecesoras', 'predecesora', 'vinculo', 'dependencias'],
        'restriccion'  => ['restriccion', 'restricciones', 'traba'],
        'liberacion'   => ['liberacion', 'fecha liberacion'],
        'fin_real'     => ['fin real', 'fecha fin real', 'termino real'],
        'observacion'  => ['observacion', 'observaciones', 'nota', 'comentario', 'eventualidad'],
    ];

    public const OBLIGATORIOS = ['codigo', 'nombre', 'inicio', 'fin', 'total', 'ejecutado', 'unidad'];

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '.' => ' ', '_' => ' ', '/' => ' ']);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    /** @return string[][] filas (la primera son encabezados) */
    public static function leer(string $ruta, string $nombreOriginal): array
    {
        if (!is_file($ruta)) {
            throw new RuntimeException('No se recibió el archivo.');
        }
        if (filesize($ruta) > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera 5 MB.');
        }
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        return match ($ext) {
            'csv', 'txt' => self::leerCsv((string)file_get_contents($ruta)),
            'xlsx' => self::leerXlsx($ruta),
            default => throw new RuntimeException('Formato no admitido. Use .csv o .xlsx (Excel 2007+).'),
        };
    }

    public static function leerCsv(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $primera = strtok($texto, "\r\n") ?: '';
        $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        $f = fopen('php://memory', 'r+');
        fwrite($f, $texto);
        rewind($f);
        $filas = [];
        while (($r = fgetcsv($f, 0, $sep, '"', '')) !== false) {
            if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) {
                continue;
            }
            $filas[] = array_map(fn($x) => trim((string)$x), $r);
        }
        fclose($f);
        return self::verificar($filas);
    }

    public static function leerXlsx(string $ruta): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión zip de PHP no está disponible.');
        }
        $z = new ZipArchive();
        if ($z->open($ruta) !== true) {
            throw new RuntimeException('El Excel está dañado, cifrado o no es .xlsx.');
        }
        $wb = $z->getFromName('xl/workbook.xml');
        if ($wb === false) {
            throw new RuntimeException('El archivo no contiene un libro de Excel válido.');
        }
        if (preg_match('/date1904="(1|true)"/', $wb)) {
            throw new RuntimeException('Excel con sistema de fechas 1904 no admitido. Guarde con sistema 1900.');
        }
        // primera hoja
        $rels = (string)$z->getFromName('xl/_rels/workbook.xml.rels');
        preg_match('/<sheet [^>]*r:id="([^"]+)"/', $wb, $m);
        $destino = 'worksheets/sheet1.xml';
        if ($m && preg_match('/Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"|Target="([^"]+)"[^>]*Id="' . preg_quote($m[1], '/') . '"/', $rels, $t)) {
            $destino = $t[1] !== '' ? $t[1] : $t[2];
        }
        $destino = ltrim(str_replace('/xl/', '', $destino), '/');
        $hoja = $z->getFromName('xl/' . $destino);
        if ($hoja === false) {
            throw new RuntimeException('No se encontró la primera hoja del Excel.');
        }
        $compartidas = [];
        $ss = $z->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = simplexml_load_string($ss);
            foreach ($x->si as $si) {
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string)$si->t;
                }
                foreach ($si->r as $r) {
                    $txt .= (string)$r->t;
                }
                $compartidas[] = $txt;
            }
        }
        $z->close();
        $x = simplexml_load_string($hoja);
        $filas = [];
        foreach ($x->sheetData->row as $row) {
            $fila = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = self::columna(preg_replace('/\d+/', '', $ref));
                $tipo = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : '';
                if ($tipo === 's') {
                    $v = $compartidas[(int)$v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = (string)$c->is->t;
                }
                $fila[$col] = trim($v);
            }
            if (!$fila || !array_filter($fila, fn($v) => $v !== '')) {
                continue;
            }
            $max = max(array_keys($fila));
            $filas[] = array_map(fn($i) => $fila[$i] ?? '', range(0, $max));
        }
        if ($filas) {
            $ancho = count($filas[0]);
            $filas = array_map(fn($r) => array_slice(array_pad($r, $ancho, ''), 0, $ancho), $filas);
        }
        return self::verificar($filas);
    }

    private static function columna(string $letras): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private static function verificar(array $filas): array
    {
        if (count($filas) < 2) {
            throw new RuntimeException('El archivo necesita una fila de encabezados y al menos una actividad.');
        }
        $enc = array_map([self::class, 'norm'], $filas[0]);
        if (in_array('', $enc, true) || count(array_unique($enc)) !== count($enc)) {
            throw new RuntimeException('Hay encabezados vacíos o repetidos en la primera fila.');
        }
        if (count($filas) - 1 > 5000) {
            throw new RuntimeException('Máximo 5000 actividades por corte.');
        }
        return $filas;
    }

    /** Propone el mapeo campo => índice de columna. */
    public static function proponerMapeo(array $encabezados): array
    {
        $enc = array_map([self::class, 'norm'], $encabezados);
        $map = [];
        foreach (self::ALIAS as $campo => $alias) {
            foreach ($enc as $i => $h) {
                if (in_array($h, $alias, true) && !in_array($i, $map, true)) {
                    $map[$campo] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    /** Convierte filas crudas en registros del esquema común usando el mapeo confirmado. */
    public static function aplicarMapeo(array $filas, array $mapeo): array
    {
        $falt = array_diff(self::OBLIGATORIOS, array_keys(array_filter($mapeo, fn($v) => $v !== null && $v !== '')));
        if ($falt) {
            throw new RuntimeException('Faltan columnas obligatorias: ' . implode(', ', $falt));
        }
        $out = [];
        foreach (array_slice($filas, 1) as $k => $r) {
            $reg = ['fila_origen' => $k + 2];
            foreach (array_keys(self::ALIAS) as $campo) {
                $i = $mapeo[$campo] ?? null;
                $reg[$campo] = ($i === null || $i === '') ? '' : trim((string)($r[(int)$i] ?? ''));
            }
            // fechas a ISO cuando sea posible (incluye serial de Excel)
            foreach (['inicio', 'fin', 'liberacion', 'fin_real'] as $c) {
                if ($reg[$c] !== '') {
                    $d = Motor::fecha($reg[$c]);
                    $reg[$c] = $d ? $d->format('Y-m-d') : $reg[$c];
                }
            }
            $out[] = $reg;
        }
        return $out;
    }
}
