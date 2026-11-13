<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use RuntimeException;

/**
 * C4 · Motor determinístico.
 * Calcula cantidad restante, duración, fecha probable, margen, atraso, semáforo,
 * compromisos de la semana y PPC. La IA nunca modifica estos valores.
 */
final class Motor
{
    public const ETIQUETAS = [
        'verde'    => 'En fecha',
        'amarillo' => 'Vigilar',
        'rojo'     => 'Atraso probable',
        'naranja'  => 'Bloqueada',
        'gris'     => 'Revisar datos',
        'azul'     => 'Terminada',
    ];

    private array $dias;      // 0=domingo … 6=sábado
    private array $feriados;  // 'Y-m-d' => true

    public function __construct(array $diasLaborables = [1, 2, 3, 4, 5], array $feriados = [])
    {
        if (!$diasLaborables) {
            throw new RuntimeException('El calendario necesita al menos un día laborable.');
        }
        foreach ($diasLaborables as $d) {
            if (!is_int($d) || $d < 0 || $d > 6) {
                throw new RuntimeException('Día laborable no válido en el calendario.');
            }
        }
        $this->dias = array_fill_keys($diasLaborables, true);
        $this->feriados = [];
        foreach ($feriados as $f) {
            $d = self::fecha($f);
            if (!$d) {
                throw new RuntimeException("Feriado no válido: {$f}");
            }
            $this->feriados[$d->format('Y-m-d')] = true;
        }
    }

    public static function desdeObra(array $obra): self
    {
        $dias = array_map('intval', array_filter(explode(',', (string)($obra['dias_laborables'] ?? '1,2,3,4,5')), 'strlen'));
        $fer = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($obra['feriados'] ?? '')) ?: []));
        return new self($dias, $fer);
    }

    /* ---------- utilidades de fecha y número ---------- */

    public static function fecha(mixed $v): ?DateTimeImmutable
    {
        if ($v instanceof DateTimeImmutable) {
            return $v;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', trim($v)))) {
            $n = (float)$v; // serial Excel (sistema 1900)
            if ($n > 20000 && $n < 100000) {
                return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)round($n) . ' days');
            }
            return null;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[3], (int)$m[2], (int)$m[1]];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d));
    }

    public static function iso(?DateTimeImmutable $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function numero(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return is_finite((float)$v) ? (float)$v : null;
        }
        $s = str_replace(',', '.', trim((string)$v));
        return preg_match('/^[-+]?\d+(\.\d+)?$/', $s) ? (float)$s : null;
    }

    public function esLaborable(DateTimeImmutable $d): bool
    {
        return isset($this->dias[(int)$d->format('w')]) && !isset($this->feriados[$d->format('Y-m-d')]);
    }

    /** Primer día laborable igual o posterior a $d. */
    public function laborable(DateTimeImmutable $d): DateTimeImmutable
    {
        for ($i = 0; $i < 3660; $i++) {
            if ($this->esLaborable($d)) {
                return $d;
            }
            $d = $d->modify('+1 day');
        }
        throw new RuntimeException('Calendario fuera del horizonte de 10 años.');
    }

    /** Fecha en la que termina un trabajo de $n días laborables que empieza en $inicio. */
    public function terminar(DateTimeImmutable $inicio, int $n): DateTimeImmutable
    {
        if ($n > 3660) {
            throw new RuntimeException('Duración fuera del horizonte de 10 años.');
        }
        $a = $this->laborable($inicio);
        for ($k = 1; $k < $n; $k++) {
            $a = $this->laborable($a->modify('+1 day'));
        }
        return $a;
    }

    /** Días laborables entre $a y $b (positivo si $b es posterior). */
    public function diasLaborables(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        if ($a == $b) {
            return 0;
        }
        $signo = $b > $a ? 1 : -1;
        $d = min($a, $b);
        $fin = max($a, $b);
        $c = 0;
        while ($d < $fin) {
            $d = $d->modify('+1 day');
            if ($this->esLaborable($d)) {
                $c++;
            }
        }
        return $c * $signo;
    }

    public static function diasCalendario(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return (int)$a->diff($b)->format('%r%a');
    }

    /* ---------- análisis del corte ---------- */

    /**
     * @param array $filas  actividades con claves: codigo, nombre, inicio, fin, total, ejecutado,
     *                      rendimiento, unidad, predecesoras, restriccion, liberacion, fin_real
     * @return array filas con: errores[], restante, duracion, proyeccion, holgura, atraso,
     *               continua, estado, motivo, supuestos[], programado_pct, ejecutado_pct, comprometida_auto
     */
    public function analizar(array $filas, string $corte, string $cierre, int $umbral = 2, ?string $fechaDato = null): array
    {
        $fc = self::fecha($corte);
        $fz = self::fecha($cierre);
        if (!$fc || !$fz || $fz < $fc) {
            throw new RuntimeException('Revisa la fecha de corte y la de cierre semanal.');
        }
        // fecha de datos: último día con parte diario registrado (entre corte y cierre); por defecto, el corte
        $fdat = $fechaDato ? self::fecha($fechaDato) : null;
        $fdat = ($fdat && $fdat > $fc) ? min($fdat, $fz) : $fc;
        if ($umbral < 0 || $umbral > 30) {
            throw new RuntimeException('El margen de vigilancia debe estar entre 0 y 30 días.');
        }
        $n = count($filas);
        if ($n < 1 || $n > 5000) {
            throw new RuntimeException('El corte debe tener entre 1 y 5000 actividades.');
        }

        $conteo = [];
        $indice = [];
        foreach ($filas as $i => $f) {
            $id = trim((string)($f['codigo'] ?? ''));
            $filas[$i]['codigo'] = $id;
            $conteo[$id] = ($conteo[$id] ?? 0) + 1;
            $indice[$id] = $i;
        }

        $errores = [];
        $preds = [];
        foreach ($filas as $i => $f) {
            $e = [];
            $ini = self::fecha($f['inicio'] ?? null);
            $fin = self::fecha($f['fin'] ?? null);
            $tot = self::numero($f['total'] ?? null);
            $eje = self::numero($f['ejecutado'] ?? null);
            $ren = self::numero($f['rendimiento'] ?? null);
            if ($f['codigo'] === '') {
                $e[] = 'Falta ID de actividad';
            } elseif ($conteo[$f['codigo']] > 1) {
                $e[] = 'ID duplicado';
            }
            if (trim((string)($f['nombre'] ?? '')) === '') {
                $e[] = 'Falta nombre de actividad';
            }
            if (!$ini || !$fin) {
                $e[] = 'Inicio o fin planificado no válido';
            } elseif ($fin < $ini) {
                $e[] = 'Fin planificado anterior al inicio';
            }
            if ($tot === null || $tot <= 0) {
                $e[] = 'Cantidad total debe ser mayor que cero';
            }
            if ($eje === null || $eje < 0 || ($tot !== null && $eje > $tot + 1e-9)) {
                $e[] = 'Ejecutado fuera de rango o vacío';
            }
            if ($tot !== null && $eje !== null && abs($tot - $eje) > 1e-9 && ($ren === null || $ren <= 0)) {
                $e[] = 'Falta rendimiento positivo (unidad/día)';
            }
            if (trim((string)($f['unidad'] ?? '')) === '') {
                $e[] = 'Falta unidad del metrado';
            }
            $fr = $f['fin_real'] ?? null;
            if ($fr !== null && $fr !== '') {
                $dfr = self::fecha($fr);
                if (!$dfr || $dfr > $fz) {
                    $e[] = 'Fin real inválido o posterior al cierre';
                }
                if ($tot !== null && $eje !== null && abs($tot - $eje) > 1e-9) {
                    $e[] = 'Fin real con cantidad pendiente';
                }
            }
            $lib = $f['liberacion'] ?? null;
            if ($lib !== null && $lib !== '' && !self::fecha($lib)) {
                $e[] = 'Fecha de liberación no válida';
            }
            $p = array_values(array_filter(array_map('trim', preg_split('/[|,;]/', (string)($f['predecesoras'] ?? '')) ?: []), 'strlen'));
            foreach ($p as $x) {
                if ($x === $f['codigo']) {
                    $e[] = 'La actividad depende de sí misma';
                } elseif (!isset($indice[$x])) {
                    $e[] = "Predecesora {$x} no existe en esta obra";
                }
            }
            $preds[$i] = $p;
            $errores[$i] = $e;
        }

        // detección de ciclos
        $marca = [];
        $ciclo = function (int $i, array $pila) use (&$ciclo, &$marca, &$errores, $preds, $indice) {
            if (($marca[$i] ?? 0) === 1) {
                foreach (array_slice($pila, (int)array_search($i, $pila, true)) as $k) {
                    $errores[$k][] = 'Ciclo de dependencias';
                }
                return;
            }
            if (($marca[$i] ?? 0) === 2) {
                return;
            }
            $marca[$i] = 1;
            foreach ($preds[$i] as $p) {
                if (isset($indice[$p])) {
                    $ciclo($indice[$p], [...$pila, $i]);
                }
            }
            $marca[$i] = 2;
        };
        foreach (array_keys($filas) as $i) {
            $ciclo($i, []);
        }

        $res = [];
        $calc = function (int $i) use (&$calc, &$res, $filas, $errores, $preds, $indice, $fc, $fz, $fdat, $umbral) {
            if (isset($res[$i])) {
                return $res[$i];
            }
            $f = $filas[$i];
            $tot = self::numero($f['total'] ?? null);
            $eje = self::numero($f['ejecutado'] ?? null);
            $ini = self::fecha($f['inicio'] ?? null);
            $fin = self::fecha($f['fin'] ?? null);
            $o = array_merge($f, [
                'errores' => array_values(array_unique($errores[$i])),
                'restante' => ($tot !== null && $eje !== null) ? round($tot - $eje, 4) : null,
                'duracion' => null, 'proyeccion' => null, 'holgura' => null, 'atraso' => null,
                'continua' => false, 'estado' => 'gris', 'motivo' => '', 'supuestos' => [],
                'programado_pct' => null, 'ejecutado_pct' => null, 'comprometida_auto' => false,
            ]);
            if ($ini && $fin) {
                // compromiso de la semana: fin planificado entre el corte (exclusivo) y el cierre (inclusive)
                $o['comprometida_auto'] = $fin > $fc && $fin <= $fz;
                $durPlan = max(1, $this->diasLaborables($ini, $fin) + ($this->esLaborable($ini) ? 1 : 0));
                $trans = $fdat < $ini ? 0 : min($durPlan, $this->diasLaborables($ini, $fdat) + ($this->esLaborable($ini) ? 1 : 0));
                $o['programado_pct'] = round(100 * $trans / $durPlan, 1);
            }
            if ($tot !== null && $tot > 0 && $eje !== null) {
                $o['ejecutado_pct'] = round(100 * min($eje, $tot) / $tot, 1);
            }
            $res[$i] = $o;
            if ($o['errores']) {
                $o['motivo'] = implode('. ', $o['errores']);
                return $res[$i] = $o;
            }
            if (abs($o['restante']) < 1e-9) {
                $o['estado'] = 'azul';
                $fr = self::fecha($f['fin_real'] ?? null);
                $o['proyeccion'] = $fr ? self::iso($fr) : null;
                $o['motivo'] = $fr ? 'Actividad terminada. Fin real confirmado.' : 'Actividad terminada. Falta registrar la fecha real para evaluar cumplimiento.';
                if ($fr) {
                    $o['atraso'] = self::diasCalendario($fin, $fr);
                    $o['holgura'] = $this->diasLaborables($fr, $fin);
                    if ($o['atraso'] > 0) {
                        $o['motivo'] .= " Terminó {$o['atraso']} día(s) calendario después del plan.";
                    }
                }
                return $res[$i] = $o;
            }
            $ren = self::numero($f['rendimiento']);
            $o['duracion'] = (int)ceil($o['restante'] / $ren - 1e-9);
            $inicio = $this->laborable($fdat->modify('+1 day'));
            if ($ini > $inicio) {
                $inicio = $this->laborable($ini);
            }
            foreach ($preds[$i] as $p) {
                $padre = $calc($indice[$p]);
                if ($padre['estado'] === 'gris') {
                    $o['motivo'] = "La predecesora {$p} tiene datos por corregir";
                    return $res[$i] = $o;
                }
                if ($padre['estado'] === 'naranja' && !$padre['proyeccion']) {
                    $o['estado'] = 'naranja';
                    $o['motivo'] = "Predecesora {$p} bloqueada sin fecha de liberación";
                    return $res[$i] = $o;
                }
                $pf = $padre['proyeccion'] ? self::fecha($padre['proyeccion']) : $fdat;
                if (!$padre['proyeccion']) {
                    $o['supuestos'][] = "Fin real de {$p} desconocido: se usa la fecha de datos como límite conservador.";
                }
                $cand = $this->laborable($pf->modify('+1 day'));
                if ($cand > $inicio) {
                    $inicio = $cand;
                    $o['supuestos'][] = "Inicia después de la predecesora {$p} (fin a inicio).";
                }
            }
            $restr = trim((string)($f['restriccion'] ?? ''));
            if ($restr !== '') {
                $lib = self::fecha($f['liberacion'] ?? null);
                if (!$lib) {
                    $o['estado'] = 'naranja';
                    $o['motivo'] = "Restricción activa sin fecha de liberación: {$restr}";
                    return $res[$i] = $o;
                }
                $lib = $this->laborable($lib);
                if ($lib > $inicio) {
                    $inicio = $lib;
                }
                $o['supuestos'][] = 'La liberación programada es un escenario; no confirma que la restricción ya desapareció.';
            }
            try {
                $pfin = $this->terminar($inicio, $o['duracion']);
            } catch (RuntimeException $ex) {
                $o['errores'][] = $ex->getMessage();
                $o['motivo'] = $ex->getMessage();
                return $res[$i] = $o;
            }
            $o['proyeccion'] = self::iso($pfin);
            $o['atraso'] = self::diasCalendario($fin, $pfin);
            $o['holgura'] = $this->diasLaborables($pfin, $fin);
            $o['continua'] = $pfin > $fz;
            if ($o['atraso'] > 0) {
                $o['estado'] = 'rojo';
                $o['motivo'] = "Terminaría {$o['atraso']} día(s) calendario después del fin planificado";
            } elseif ($o['holgura'] <= $umbral) {
                $o['estado'] = 'amarillo';
                $o['motivo'] = "Quedan {$o['holgura']} día(s) laborables de margen";
            } else {
                $o['estado'] = 'verde';
                $o['motivo'] = "Proyección dentro del plazo con {$o['holgura']} día(s) laborables de margen";
            }
            if ($o['continua']) {
                $o['motivo'] .= '. Continúa después del cierre semanal';
            }
            if ($restr !== '') {
                $o['motivo'] .= '. Incluye liberación programada';
            }
            return $res[$i] = $o;
        };
        $salida = [];
        foreach (array_keys($filas) as $i) {
            $salida[] = $calc($i);
        }
        return $salida;
    }

    /**
     * PPC = compromisos cumplidos / compromisos de la semana.
     * Una actividad comprometida se cumple si su cantidad está completa y su fin real no excede el cierre.
     */
    public static function ppc(array $acts): array
    {
        $comp = array_values(array_filter($acts, fn($a) => (bool)($a['comprometida'] ?? $a['comprometida_auto'] ?? false)));
        $ok = array_values(array_filter($comp, fn($a) => self::cumplida($a)));
        $n = count($comp);
        return [
            'comprometidas' => $n,
            'cumplidas' => count($ok),
            'ppc' => $n ? round(100 * count($ok) / $n, 1) : null,
        ];
    }

    public static function cumplida(array $a): bool
    {
        $tot = self::numero($a['total'] ?? null);
        $eje = self::numero($a['ejecutado'] ?? null);
        return $tot !== null && $eje !== null && abs($tot - $eje) < 1e-9 && !empty($a['fin_real']);
    }
}
