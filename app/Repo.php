<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Operaciones de negocio sobre la base de datos (C3 + C6 + C7).
 */
final class Repo
{
    /* ---------------- lecturas ---------------- */

    public static function obras(): array
    {
        return db()->query('SELECT * FROM obras ORDER BY id')->fetchAll();
    }

    public static function obra(int $id): array
    {
        $st = db()->prepare('SELECT * FROM obras WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new RuntimeException('Obra no encontrada.');
    }

    public static function cortes(int $obraId): array
    {
        $st = db()->prepare('SELECT c.*, u.nombre AS aprobador FROM cortes c LEFT JOIN usuarios u ON u.id = c.aprobado_por WHERE obra_id = ? ORDER BY semana DESC');
        $st->execute([$obraId]);
        return $st->fetchAll();
    }

    public static function corte(int $id): array
    {
        $st = db()->prepare('SELECT c.*, u.nombre AS aprobador, u.cargo AS aprobador_cargo, i.nombre AS importador
            FROM cortes c LEFT JOIN usuarios u ON u.id = c.aprobado_por LEFT JOIN usuarios i ON i.id = c.importado_por WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new RuntimeException('Corte no encontrado.');
    }

    /** Corte vigente de la obra: el abierto más reciente o, si no hay, el último aprobado. */
    public static function corteVigente(int $obraId): ?array
    {
        $st = db()->prepare("SELECT id FROM cortes WHERE obra_id = ? ORDER BY (estado='abierto') DESC, semana DESC LIMIT 1");
        $st->execute([$obraId]);
        $id = $st->fetchColumn();
        return $id ? self::corte((int)$id) : null;
    }

    public static function actividades(int $corteId): array
    {
        $st = db()->prepare('SELECT a.*, c.id AS causa_id, c.categoria_ia, c.cita_ia, c.confianza_ia, c.justificacion_ia, c.modo_ia, c.modelo_ia,
                c.validacion_ia, c.estado AS causa_estado, c.categoria_final, c.comentario AS causa_comentario, c.revisado_en, r.nombre AS revisor
            FROM actividades a LEFT JOIN causas c ON c.actividad_id = a.id LEFT JOIN usuarios r ON r.id = c.revisado_por
            WHERE a.corte_id = ? ORDER BY a.codigo');
        $st->execute([$corteId]);
        $out = [];
        foreach ($st->fetchAll() as $a) {
            $a['comprometida_efectiva'] = self::esComprometida($a);
            $a['cumplida'] = Motor::cumplida($a);
            $out[] = $a;
        }
        return $out;
    }

    public static function actividad(int $id): array
    {
        $st = db()->prepare('SELECT corte_id FROM actividades WHERE id = ?');
        $st->execute([$id]);
        $cid = $st->fetchColumn();
        if (!$cid) {
            throw new RuntimeException('Actividad no encontrada.');
        }
        foreach (self::actividades((int)$cid) as $a) {
            if ((int)$a['id'] === $id) {
                return $a;
            }
        }
        throw new RuntimeException('Actividad no encontrada.');
    }

    public static function avances(int $actId): array
    {
        $st = db()->prepare('SELECT d.*, u.nombre FROM avances_diarios d JOIN usuarios u ON u.id = d.usuario_id WHERE actividad_id = ? ORDER BY fecha DESC, d.id DESC');
        $st->execute([$actId]);
        return $st->fetchAll();
    }

    public static function auditoria(int $limite = 200, ?int $obraId = null): array
    {
        $st = db()->prepare('SELECT a.*, u.nombre, u.rol FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id ORDER BY a.id DESC LIMIT ' . (int)$limite);
        $st->execute();
        return $st->fetchAll();
    }

    public static function esComprometida(array $a): bool
    {
        if ($a['comprometida'] !== null && $a['comprometida'] !== '') {
            return (bool)$a['comprometida'];
        }
        $st = db()->prepare('SELECT fecha_corte, fecha_cierre FROM cortes WHERE id = ?');
        static $cache = [];
        if (!isset($cache[$a['corte_id']])) {
            $st->execute([$a['corte_id']]);
            $cache[$a['corte_id']] = $st->fetch();
        }
        $c = $cache[$a['corte_id']];
        return $a['fin'] !== null && $a['fin'] > $c['fecha_corte'] && $a['fin'] <= $c['fecha_cierre'];
    }

    /* ---------------- motor ---------------- */

    public static function recalcular(int $corteId): array
    {
        $corte = self::corte($corteId);
        $obra = self::obra((int)$corte['obra_id']);
        $st = db()->prepare('SELECT * FROM actividades WHERE corte_id = ? ORDER BY id');
        $st->execute([$corteId]);
        $acts = $st->fetchAll();
        if (!$acts) {
            return [];
        }
        $motor = Motor::desdeObra($obra);
        $res = $motor->analizar($acts, $corte['fecha_corte'], $corte['fecha_cierre'], (int)$obra['umbral_vigilar'], self::fechaDato($corteId));
        $up = db()->prepare('UPDATE actividades SET estado=?, motivo=?, proyeccion=?, holgura=?, atraso=?, duracion=?, continua=?,
            programado_pct=?, ejecutado_pct=?, supuestos=?, errores=? WHERE id=?');
        foreach ($res as $r) {
            $up->execute([$r['estado'], mb_substr($r['motivo'], 0, 400), $r['proyeccion'], $r['holgura'], $r['atraso'], $r['duracion'],
                $r['continua'] ? 1 : 0, $r['programado_pct'], $r['ejecutado_pct'],
                $r['supuestos'] ? json_encode($r['supuestos'], JSON_UNESCAPED_UNICODE) : null,
                $r['errores'] ? json_encode($r['errores'], JSON_UNESCAPED_UNICODE) : null, $r['id']]);
        }
        return $res;
    }

    /** Último día con parte diario registrado en el corte (o null). */
    public static function fechaDato(int $corteId): ?string
    {
        // la mayor entre el último parte diario y la fecha de datos declarada al importar el archivo
        $st = db()->prepare('SELECT MAX(d.fecha) FROM avances_diarios d JOIN actividades a ON a.id = d.actividad_id WHERE a.corte_id = ?');
        $st->execute([$corteId]);
        $v = $st->fetchColumn() ?: null;
        $st = db()->prepare('SELECT fecha_datos FROM cortes WHERE id = ?');
        $st->execute([$corteId]);
        $f = $st->fetchColumn() ?: null;
        return ($v && $f) ? max($v, $f) : ($v ?: $f);
    }

    public static function kpis(array $acts): array
    {
        $cnt = array_fill_keys(array_keys(Motor::ETIQUETAS), 0);
        $prog = $ejec = [];
        foreach ($acts as $a) {
            $cnt[$a['estado'] ?? 'gris'] = ($cnt[$a['estado'] ?? 'gris'] ?? 0) + 1;
            if ($a['programado_pct'] !== null && $a['ejecutado_pct'] !== null) {
                $prog[] = (float)$a['programado_pct'];
                $ejec[] = (float)$a['ejecutado_pct'];
            }
        }
        $comp = array_filter($acts, fn($a) => $a['comprometida_efectiva']);
        $ok = array_filter($comp, fn($a) => $a['cumplida']);
        $p = $prog ? round(array_sum($prog) / count($prog), 1) : null;
        $e = $ejec ? round(array_sum($ejec) / count($ejec), 1) : null;
        return [
            'total' => count($acts),
            'estados' => $cnt,
            'comprometidas' => count($comp),
            'cumplidas' => count($ok),
            'ppc' => $comp ? round(100 * count($ok) / count($comp), 1) : null,
            'programado' => $p,
            'ejecutado' => $e,
            'desviacion' => ($p !== null && $e !== null) ? round($e - $p, 1) : null,
            'continuan' => count(array_filter($acts, fn($a) => (int)$a['continua'] === 1)),
        ];
    }

    /** Actividades comprometidas en la semana que no se cumplieron: requieren causa y reprogramación. */
    public static function incumplidas(array $acts): array
    {
        return array_values(array_filter($acts, fn($a) => $a['comprometida_efectiva'] && !$a['cumplida']));
    }

    public static function bloqueos(int $corteId): array
    {
        $acts = self::actividades($corteId);
        $b = [];
        foreach ($acts as $a) {
            if ($a['estado'] === 'gris') {
                $b[] = "{$a['codigo']}: datos por corregir ({$a['motivo']}).";
            }
        }
        foreach (self::incumplidas($acts) as $a) {
            if (!$a['causa_id']) {
                $b[] = "{$a['codigo']}: falta proponer y revisar la causa de incumplimiento.";
            } elseif ($a['causa_estado'] === 'propuesta') {
                $b[] = "{$a['codigo']}: la causa propuesta por IA aún no tiene decisión humana.";
            }
            if (!$a['nueva_fecha']) {
                $b[] = "{$a['codigo']}: falta la nueva fecha comprometida (reprogramación).";
            }
        }
        return $b;
    }

    /* ---------------- escrituras ---------------- */

    public static function auditar(?int $uid, string $accion, string $entidad, ?int $id, mixed $detalle): void
    {
        $st = db()->prepare('INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle) VALUES (?,?,?,?,?)');
        $st->execute([$uid, $accion, $entidad, $id, $detalle === null ? null : (is_string($detalle) ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE))]);
    }

    private static function abierto(int $corteId): array
    {
        $c = self::corte($corteId);
        if ($c['estado'] !== 'abierto') {
            throw new RuntimeException('El corte de la semana ' . $c['semana'] . ' ya fue aprobado y es inmutable.');
        }
        return $c;
    }

    /** Valida que los registros puedan guardarse (tipos). Devuelve errores bloqueantes por fila. */
    public static function erroresBloqueantes(array $regs): array
    {
        $err = [];
        $vistos = [];
        foreach ($regs as $r) {
            $f = $r['fila_origen'];
            if ($r['codigo'] === '') {
                $err[] = "Fila {$f}: falta ID.";
            } elseif (isset($vistos[$r['codigo']])) {
                $err[] = "Fila {$f}: ID {$r['codigo']} repetido.";
            }
            $vistos[$r['codigo']] = true;
            foreach (['inicio', 'fin'] as $c) {
                if (!Motor::fecha($r[$c])) {
                    $err[] = "Fila {$f}: fecha de {$c} no válida ('{$r[$c]}').";
                }
            }
            foreach (['liberacion', 'fin_real'] as $c) {
                if ($r[$c] !== '' && !Motor::fecha($r[$c])) {
                    $err[] = "Fila {$f}: {$c} no válida ('{$r[$c]}').";
                }
            }
            foreach (['total', 'ejecutado'] as $c) {
                if (Motor::numero($r[$c]) === null) {
                    $err[] = "Fila {$f}: {$c} debe ser numérico.";
                }
            }
            if ($r['rendimiento'] !== '' && Motor::numero($r['rendimiento']) === null) {
                $err[] = "Fila {$f}: rendimiento debe ser numérico.";
            }
        }
        return $err;
    }

    public static function importar(int $obraId, int $semana, string $fCorte, string $fCierre, array $regs, string $archivo, int $uid, ?string $fDatos = null): int
    {
        $fDatos = ($fDatos !== null && trim($fDatos) !== '') ? Motor::iso(Motor::fecha($fDatos) ?? throw new RuntimeException('Fecha de datos no válida.')) : null;
        if ($fDatos !== null && ($fDatos < $fCorte || $fDatos > $fCierre)) {
            throw new RuntimeException('La fecha de datos debe estar entre el corte y el cierre.');
        }
        if ($e = self::erroresBloqueantes($regs)) {
            throw new RuntimeException('Corrige el archivo antes de importar: ' . implode(' ', array_slice($e, 0, 5)));
        }
        if (!Motor::fecha($fCorte) || !Motor::fecha($fCierre) || $fCierre < $fCorte) {
            throw new RuntimeException('Fechas de corte y cierre no válidas.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, estado FROM cortes WHERE obra_id = ? AND semana = ?');
            $st->execute([$obraId, $semana]);
            $prev = $st->fetch();
            if ($prev && $prev['estado'] === 'aprobado') {
                throw new RuntimeException("La semana {$semana} ya está aprobada; no se puede reemplazar.");
            }
            if ($prev) {
                $pdo->prepare('DELETE FROM actividades WHERE corte_id = ?')->execute([$prev['id']]);
                $pdo->prepare('UPDATE cortes SET fecha_corte=?, fecha_cierre=?, fecha_datos=?, archivo_origen=?, importado_por=?, importado_en=NOW() WHERE id=?')
                    ->execute([$fCorte, $fCierre, $fDatos, $archivo, $uid, $prev['id']]);
                $cid = (int)$prev['id'];
            } else {
                $pdo->prepare('INSERT INTO cortes (obra_id, semana, fecha_corte, fecha_cierre, fecha_datos, archivo_origen, importado_por, importado_en) VALUES (?,?,?,?,?,?,?,NOW())')
                    ->execute([$obraId, $semana, $fCorte, $fCierre, $fDatos, $archivo, $uid]);
                $cid = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO actividades (corte_id, codigo, nombre, sector, responsable, unidad, inicio, fin, fin_base, total, ejecutado, rendimiento,
                predecesoras, restriccion, liberacion, fin_real, observacion, fila_origen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($regs as $r) {
                $ins->execute([$cid, $r['codigo'], mb_substr($r['nombre'] ?: '(sin nombre)', 0, 200), $r['sector'] ?: null, $r['responsable'] ?: null,
                    $r['unidad'] ?: null, Motor::iso(Motor::fecha($r['inicio'])), Motor::iso(Motor::fecha($r['fin'])), Motor::iso(Motor::fecha($r['fin'])),
                    Motor::numero($r['total']), Motor::numero($r['ejecutado']), Motor::numero($r['rendimiento']),
                    $r['predecesoras'] ?: null, $r['restriccion'] ?: null, Motor::iso(Motor::fecha($r['liberacion'])),
                    Motor::iso(Motor::fecha($r['fin_real'])), $r['observacion'] ?: null, $r['fila_origen']]);
            }
            $pdo->commit();
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        self::recalcular($cid);
        self::auditar($uid, 'importar_corte', 'corte', $cid, ['archivo' => $archivo, 'actividades' => count($regs), 'semana' => $semana]);
        return $cid;
    }

    public static function registrarAvance(int $actId, string $fecha, float $cantidad, string $nota, int $uid): void
    {
        $a = self::actividad($actId);
        $c = self::abierto((int)$a['corte_id']);
        $d = Motor::fecha($fecha);
        if (!$d) {
            throw new RuntimeException('Fecha del parte no válida.');
        }
        if ($d->format('Y-m-d') > $c['fecha_cierre']) {
            throw new RuntimeException('El parte diario no puede ser posterior al cierre de la semana (' . $c['fecha_cierre'] . ').');
        }
        if ($cantidad < 0) {
            throw new RuntimeException('La cantidad del día no puede ser negativa.');
        }
        $tot = (float)$a['total'];
        $nuevo = (float)$a['ejecutado'] + $cantidad;
        if ($nuevo > $tot + 1e-9) {
            throw new RuntimeException('El acumulado (' . num($nuevo) . ') superaría el metrado total (' . num($tot) . ').');
        }
        $finReal = $a['fin_real'];
        if (abs($nuevo - $tot) < 1e-9 && !$finReal) {
            $finReal = $d->format('Y-m-d');
        }
        $obs = trim((string)$a['observacion']);
        if (trim($nota) !== '') {
            $obs = trim($obs . "\n[" . $d->format('d/m') . '] ' . trim($nota));
        }
        db()->prepare('INSERT INTO avances_diarios (actividad_id, fecha, cantidad, nota, usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$actId, $d->format('Y-m-d'), $cantidad, $nota ?: null, $uid]);
        db()->prepare('UPDATE actividades SET ejecutado = ?, fin_real = ?, observacion = ? WHERE id = ?')
            ->execute([$nuevo, $finReal, $obs ?: null, $actId]);
        self::recalcular((int)$a['corte_id']);
        self::auditar($uid, 'parte_diario', 'actividad', $actId, ['codigo' => $a['codigo'], 'fecha' => $d->format('Y-m-d'), 'cantidad' => $cantidad, 'acumulado' => $nuevo]);
    }

    public static function editarActividad(int $actId, array $in, int $uid): void
    {
        $a = self::actividad($actId);
        self::abierto((int)$a['corte_id']);
        $campos = [];
        $antes = [];
        foreach (['rendimiento', 'restriccion', 'liberacion', 'fin_real', 'observacion', 'comprometida'] as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
            if ($v === '') {
                $v = null;
            }
            if (in_array($k, ['liberacion', 'fin_real'], true) && $v !== null) {
                $f = Motor::fecha($v) ?? throw new RuntimeException("Fecha no válida en {$k}.");
                $v = $f->format('Y-m-d');
            }
            if ($k === 'rendimiento' && $v !== null) {
                $n = Motor::numero($v);
                if ($n === null || $n <= 0) {
                    throw new RuntimeException('El rendimiento debe ser un número mayor que cero.');
                }
                $v = $n;
            }
            if ($k === 'comprometida' && $v !== null) {
                $v = $v === 'auto' ? null : (int)(bool)$v;
            }
            if ((string)$a[$k] !== (string)$v) {
                $campos[$k] = $v;
                $antes[$k] = $a[$k];
            }
        }
        if (!$campos) {
            return;
        }
        $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
        db()->prepare("UPDATE actividades SET {$set} WHERE id = ?")->execute([...array_values($campos), $actId]);
        self::recalcular((int)$a['corte_id']);
        self::auditar($uid, 'editar_actividad', 'actividad', $actId, ['codigo' => $a['codigo'], 'antes' => $antes, 'despues' => $campos]);
    }

    public static function proponerCausa(int $actId, int $uid): array
    {
        $a = self::actividad($actId);
        self::abierto((int)$a['corte_id']);
        if ($a['causa_id'] && $a['causa_estado'] !== 'propuesta') {
            throw new RuntimeException("La causa de {$a['codigo']} ya fue revisada por una persona.");
        }
        $p = ia()->proponerCausa($a);
        db()->prepare('DELETE FROM causas WHERE actividad_id = ?')->execute([$actId]);
        db()->prepare('INSERT INTO causas (actividad_id, categoria_ia, cita_ia, confianza_ia, justificacion_ia, modo_ia, modelo_ia, validacion_ia) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$actId, $p['categoria'], $p['cita'], $p['confianza'], $p['justificacion'], $p['modo'], $p['modelo'], json_encode($p['validacion'], JSON_UNESCAPED_UNICODE)]);
        self::auditar($uid, 'ia_propone_causa', 'actividad', $actId, ['codigo' => $a['codigo'], 'categoria' => $p['categoria'], 'confianza' => $p['confianza'], 'modo' => $p['modo']]);
        return $p;
    }

    public static function proponerCausas(int $corteId, int $uid): int
    {
        self::abierto($corteId);
        $n = 0;
        foreach (self::incumplidas(self::actividades($corteId)) as $a) {
            if (!$a['causa_id'] || $a['causa_estado'] === 'propuesta') {
                self::proponerCausa((int)$a['id'], $uid);
                $n++;
            }
        }
        return $n;
    }

    /** C6 · Revisión humana obligatoria: aceptar, editar o marcar "sin dato suficiente". */
    public static function revisarCausa(int $actId, string $decision, string $categoria, string $comentario, int $uid): void
    {
        $a = self::actividad($actId);
        self::abierto((int)$a['corte_id']);
        if (!$a['causa_id']) {
            throw new RuntimeException('Primero solicita la propuesta de causa.');
        }
        $comentario = trim($comentario);
        switch ($decision) {
            case 'aceptar':
                if ($a['categoria_ia'] === 'sin_dato') {
                    throw new RuntimeException('La IA no propuso causa: edítala o márcala como sin dato suficiente con un comentario.');
                }
                $final = $a['categoria_ia'];
                $estado = 'aceptada';
                break;
            case 'editar':
                if (!isset(IA\ServicioIA::CATEGORIAS[$categoria]) || $categoria === 'sin_dato') {
                    throw new RuntimeException('Elige una categoría de causa válida.');
                }
                if ($comentario === '') {
                    throw new RuntimeException('Al corregir a la IA, explica brevemente por qué (queda en la auditoría).');
                }
                $final = $categoria;
                $estado = 'editada';
                break;
            case 'rechazar':
                if ($comentario === '') {
                    throw new RuntimeException('Indica qué dato falta para poder atribuir la causa.');
                }
                $final = 'sin_dato';
                $estado = 'rechazada';
                break;
            default:
                throw new RuntimeException('Decisión no válida.');
        }
        db()->prepare('UPDATE causas SET estado=?, categoria_final=?, comentario=?, revisado_por=?, revisado_en=NOW() WHERE actividad_id=?')
            ->execute([$estado, $final, $comentario ?: null, $uid, $actId]);
        self::auditar($uid, 'revision_causa', 'actividad', $actId, ['codigo' => $a['codigo'], 'ia' => $a['categoria_ia'], 'decision' => $estado, 'final' => $final]);
    }

    public static function reprogramar(int $actId, string $fecha, string $accion, int $uid): void
    {
        $a = self::actividad($actId);
        $c = self::abierto((int)$a['corte_id']);
        $d = Motor::fecha($fecha) ?? throw new RuntimeException('Nueva fecha no válida.');
        if ($d->format('Y-m-d') <= $c['fecha_corte']) {
            throw new RuntimeException('La nueva fecha debe ser posterior al corte.');
        }
        if (trim($accion) === '') {
            throw new RuntimeException('Registra la acción acordada y su responsable.');
        }
        db()->prepare('UPDATE actividades SET nueva_fecha=?, accion=? WHERE id=?')->execute([$d->format('Y-m-d'), trim($accion), $actId]);
        self::auditar($uid, 'reprogramar', 'actividad', $actId, ['codigo' => $a['codigo'], 'fin_vigente' => $a['fin'], 'fin_base' => $a['fin_base'], 'nueva_fecha' => $d->format('Y-m-d')]);
    }

    public static function aprobar(int $corteId, string $comentario, int $uid): void
    {
        self::abierto($corteId);
        if ($b = self::bloqueos($corteId)) {
            throw new RuntimeException('Aprobación bloqueada: ' . count($b) . ' pendiente(s). ' . $b[0]);
        }
        $acts = self::actividades($corteId);
        $k = self::kpis($acts);
        $snap = ['kpis' => $k, 'actividades' => $acts, 'generado' => date('c')];
        db()->prepare("UPDATE cortes SET estado='aprobado', aprobado_por=?, aprobado_en=NOW(), comentario_aprobacion=?, ppc=?, comprometidas=?, cumplidas=?, snapshot=? WHERE id=?")
            ->execute([$uid, trim($comentario) ?: null, $k['ppc'], $k['comprometidas'], $k['cumplidas'], json_encode($snap, JSON_UNESCAPED_UNICODE), $corteId]);
        self::auditar($uid, 'aprobar_corte', 'corte', $corteId, ['ppc' => $k['ppc'], 'comprometidas' => $k['comprometidas'], 'cumplidas' => $k['cumplidas']]);
    }

    /** Prepara la semana siguiente con las actividades pendientes y la fecha reprogramada como fin vigente. */
    public static function siguienteCorte(int $corteId, int $uid): int
    {
        $c = self::corte($corteId);
        if ($c['estado'] !== 'aprobado') {
            throw new RuntimeException('Primero aprueba el corte actual.');
        }
        $st = db()->prepare('SELECT id FROM cortes WHERE obra_id = ? AND semana = ?');
        $st->execute([$c['obra_id'], (int)$c['semana'] + 1]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
        $fc = Motor::fecha($c['fecha_cierre']);
        $nCorte = $fc->format('Y-m-d');
        $nCierre = $fc->modify('+7 days')->format('Y-m-d');
        $pdo = db();
        $pdo->prepare('INSERT INTO cortes (obra_id, semana, fecha_corte, fecha_cierre, archivo_origen, importado_por, importado_en) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$c['obra_id'], (int)$c['semana'] + 1, $nCorte, $nCierre, 'Arrastre de semana ' . $c['semana'], $uid]);
        $nid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO actividades (corte_id, codigo, nombre, sector, responsable, unidad, inicio, fin, fin_base, total, ejecutado, rendimiento,
                predecesoras, restriccion, liberacion, fin_real, observacion, fila_origen)
            SELECT ?, codigo, nombre, sector, responsable, unidad, inicio, COALESCE(nueva_fecha, fin), fin_base, total, ejecutado, rendimiento,
                predecesoras, restriccion, liberacion, fin_real, NULL, fila_origen
            FROM actividades WHERE corte_id = ? AND NOT (ejecutado >= total AND fin_real IS NOT NULL)")
            ->execute([$nid, $corteId]);
        // limpiar predecesoras que ya no existen en el nuevo corte (terminadas)
        $cods = $pdo->prepare('SELECT codigo FROM actividades WHERE corte_id = ?');
        $cods->execute([$nid]);
        $vivos = array_flip($cods->fetchAll(\PDO::FETCH_COLUMN));
        $st = $pdo->prepare('SELECT id, predecesoras FROM actividades WHERE corte_id = ? AND predecesoras IS NOT NULL');
        $st->execute([$nid]);
        foreach ($st->fetchAll() as $r) {
            $p = array_filter(array_map('trim', preg_split('/[|,;]/', $r['predecesoras'])), fn($x) => isset($vivos[$x]));
            $pdo->prepare('UPDATE actividades SET predecesoras = ? WHERE id = ?')->execute([$p ? implode('|', $p) : null, $r['id']]);
        }
        self::recalcular($nid);
        self::auditar($uid, 'crear_siguiente_corte', 'corte', $nid, ['desde' => $corteId, 'semana' => (int)$c['semana'] + 1]);
        return $nid;
    }

    /** Resumen ejecutivo determinístico (reglas, no IA) para el informe semanal. */
    public static function resumen(array $corte, array $acts, array $k): array
    {
        $l = [];
        $l[] = sprintf('Semana %d (corte %s, cierre %s): PPC %s — %d de %d compromisos cumplidos.',
            $corte['semana'], fd($corte['fecha_corte']), fd($corte['fecha_cierre']),
            $k['ppc'] === null ? 'sin compromisos' : num($k['ppc']) . '%', $k['cumplidas'], $k['comprometidas']);
        if ($k['programado'] !== null) {
            $l[] = sprintf('Avance promedio por actividad: %s%% ejecutado frente a %s%% programado (%s pp).',
                num($k['ejecutado'], 1), num($k['programado'], 1), ($k['desviacion'] >= 0 ? '+' : '') . num($k['desviacion'], 1));
        }
        $roj = array_filter($acts, fn($a) => $a['estado'] === 'rojo');
        if ($roj) {
            usort($roj, fn($x, $y) => (int)$y['atraso'] <=> (int)$x['atraso']);
            $t = array_map(fn($a) => "{$a['codigo']} (+{$a['atraso']} d)", array_slice($roj, 0, 3));
            $l[] = count($roj) . ' actividad(es) con atraso probable: ' . implode(', ', $t) . '.';
        }
        $blo = array_filter($acts, fn($a) => $a['estado'] === 'naranja');
        if ($blo) {
            $l[] = count($blo) . ' actividad(es) bloqueada(s) por restricción o predecesora: ' . implode(', ', array_map(fn($a) => $a['codigo'], $blo)) . '.';
        }
        $causas = [];
        foreach ($acts as $a) {
            if ($a['categoria_final']) {
                $causas[$a['categoria_final']] = ($causas[$a['categoria_final']] ?? 0) + 1;
            }
        }
        if ($causas) {
            arsort($causas);
            $l[] = 'Causas de incumplimiento revisadas: ' . implode(', ', array_map(fn($c, $n) => IA\ServicioIA::CATEGORIAS[$c] . " ({$n})", array_keys($causas), $causas)) . '.';
        }
        return $l;
    }
}
