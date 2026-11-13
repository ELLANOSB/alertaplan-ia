<?php
declare(strict_types=1);

namespace App;

/** Gráficos SVG generados en el servidor (sin librerías ni internet). */
final class Graficos
{
    public const COLOR = ['verde' => '#1d8f52', 'amarillo' => '#d9a514', 'rojo' => '#cf3f3f', 'naranja' => '#d8691a', 'gris' => '#9a96ab', 'azul' => '#2a67b1'];

    /** Barra apilada horizontal con la distribución de estados. */
    public static function distribucion(array $estados): string
    {
        $total = array_sum($estados) ?: 1;
        $x = 0.0;
        $seg = '';
        foreach ($estados as $k => $n) {
            if (!$n) {
                continue;
            }
            $w = 600 * $n / $total;
            $seg .= sprintf('<rect x="%.1f" y="0" width="%.1f" height="26" fill="%s"><title>%s: %d</title></rect>', $x, max($w - 2, 0), self::COLOR[$k], Motor::ETIQUETAS[$k], $n);
            if ($w > 26) {
                $seg .= sprintf('<text x="%.1f" y="17.5" fill="#fff" font-size="12" font-weight="700" text-anchor="middle">%d</text>', $x + $w / 2 - 1, $n);
            }
            $x += $w;
        }
        return '<svg viewBox="0 0 600 26" role="img" aria-label="Distribución de estados"><clipPath id="cp"><rect width="600" height="26" rx="7"/></clipPath><g clip-path="url(#cp)">' . $seg . '</g></svg>';
    }

    /** Programado vs ejecutado por actividad (barras horizontales). */
    public static function avance(array $acts): string
    {
        $acts = array_values(array_filter($acts, fn($a) => $a['programado_pct'] !== null));
        $h = 26;
        $alto = count($acts) * $h + 30;
        $s = '';
        foreach ($acts as $i => $a) {
            $y = $i * $h + 6;
            $p = (float)$a['programado_pct'];
            $e = (float)($a['ejecutado_pct'] ?? 0);
            $col = self::COLOR[$a['estado']] ?? '#999';
            $s .= sprintf('<text x="0" y="%d" font-size="11.5" fill="#4a4658">%s</text>', $y + 13, htmlspecialchars($a['codigo']));
            $s .= sprintf('<rect x="62" y="%d" width="%.1f" height="16" rx="3" fill="#ece9f5"/>', $y + 2, 4.3 * 100);
            $s .= sprintf('<rect x="62" y="%d" width="%.1f" height="16" rx="3" fill="%s"><title>%s ejecutado %.0f%%</title></rect>', $y + 2, 4.3 * $e, $col, htmlspecialchars($a['codigo']), $e);
            $s .= sprintf('<line x1="%.1f" x2="%.1f" y1="%d" y2="%d" stroke="#16131f" stroke-width="2"><title>Programado %.0f%%</title></line>', 62 + 4.3 * $p, 62 + 4.3 * $p, $y, $y + 20, $p);
            $s .= sprintf('<text x="%d" y="%d" font-size="11" fill="#4a4658">%.0f%% / %.0f%%</text>', 500, $y + 13, $e, $p);
        }
        $yl = count($acts) * $h + 20;
        $s .= sprintf('<rect x="62" y="%d" width="12" height="8" fill="#5b2bc4"/><text x="80" y="%d" font-size="11" fill="#77738a">ejecutado (color = estado)</text><line x1="240" x2="240" y1="%d" y2="%d" stroke="#16131f" stroke-width="2"/><text x="248" y="%d" font-size="11" fill="#77738a">programado al corte</text><text x="500" y="%d" font-size="11" fill="#77738a">ejec. / prog.</text>', $yl - 8, $yl, $yl - 10, $yl, $yl, $yl);
        return '<svg viewBox="0 0 580 ' . $alto . '" role="img" aria-label="Avance programado vs ejecutado">' . $s . '</svg>';
    }

    /** Tendencia de PPC por semana aprobada. */
    public static function ppc(array $cortes): string
    {
        $c = array_values(array_filter(array_reverse($cortes), fn($x) => $x['ppc'] !== null));
        if (!$c) {
            return '<p class="suave">Aún no hay semanas aprobadas.</p>';
        }
        $W = 560;
        $H = 190;
        $n = count($c);
        $paso = $n > 1 ? ($W - 80) / ($n - 1) : 0;
        $pts = [];
        $s = '';
        foreach ([0, 50, 80, 100] as $g) {
            $y = 20 + ($H - 50) * (1 - $g / 100);
            $s .= sprintf('<line x1="40" x2="%d" y1="%.1f" y2="%.1f" stroke="%s" stroke-dasharray="%s"/><text x="32" y="%.1f" font-size="10.5" text-anchor="end" fill="#77738a">%d%%</text>', $W - 20, $y, $y, $g === 80 ? '#1d8f52' : '#e4e1ee', $g === 80 ? '4 4' : '', $y + 3, $g);
        }
        foreach ($c as $i => $x) {
            $px = 50 + $i * $paso;
            $py = 20 + ($H - 50) * (1 - (float)$x['ppc'] / 100);
            $pts[] = sprintf('%.1f,%.1f', $px, $py);
            $s .= sprintf('<circle cx="%.1f" cy="%.1f" r="5" fill="#380489"><title>S%d: %.0f%%</title></circle><text x="%.1f" y="%.1f" font-size="11.5" font-weight="700" text-anchor="middle" fill="#380489">%.0f%%</text><text x="%.1f" y="%d" font-size="11" text-anchor="middle" fill="#4a4658">S%d</text>', $px, $py, $x['semana'], $x['ppc'], $px, $py - 10, $x['ppc'], $px, $H - 8, $x['semana']);
        }
        $linea = $n > 1 ? '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#5b2bc4" stroke-width="2.5"/>' : '';
        $s .= sprintf('<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#1d8f52">meta referencial 80%%</text>', $W - 22, 20 + ($H - 50) * 0.2 - 4);
        return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="PPC por semana">' . $linea . $s . '</svg>';
    }

    /** Pareto de causas revisadas. */
    public static function causas(array $conteo): string
    {
        if (!$conteo) {
            return '<p class="suave">Sin causas revisadas todavía.</p>';
        }
        arsort($conteo);
        $max = max($conteo);
        $s = '';
        $i = 0;
        foreach ($conteo as $k => $n) {
            $y = $i * 30;
            $w = 260 * $n / $max;
            $s .= sprintf('<text x="0" y="%d" font-size="12" fill="#4a4658">%s</text><rect x="240" y="%d" width="%.1f" height="18" rx="4" fill="#5b2bc4"/><text x="%.1f" y="%d" font-size="12" font-weight="700" fill="#16131f">%d</text>',
                $y + 14, htmlspecialchars(IA\ServicioIA::CATEGORIAS[$k] ?? $k), $y + 1, $w, 246 + $w, $y + 14, $n);
            $i++;
        }
        return '<svg viewBox="0 0 540 ' . ($i * 30) . '" role="img" aria-label="Causas de incumplimiento">' . $s . '</svg>';
    }
}
