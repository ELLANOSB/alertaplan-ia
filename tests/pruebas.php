<?php
declare(strict_types=1);

/**
 * Pruebas del Hito 3.  Uso:  php tests/pruebas.php
 * Genera evidencias/resultados_pruebas.json (lo muestra la página "Pruebas y resultados").
 * Las pruebas de flujo crean una obra temporal "TST" en la base de datos y la eliminan al final.
 */

if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\IA\ClasificadorLocal;
use App\IA\ProveedorLLM;
use App\IA\ServicioIA;
use App\Importador;
use App\Motor;
use App\Repo;

$casos = ['motor' => [], 'flujo' => [], 'llm' => []];
function caso(string $grupo, string $id, string $nombre, string $esperado, mixed $obtenido, bool $ok): void
{
    global $casos;
    $casos[$grupo][] = ['id' => $id, 'nombre' => $nombre, 'esperado' => $esperado, 'obtenido' => is_string($obtenido) ? $obtenido : json_encode($obtenido, JSON_UNESCAPED_UNICODE), 'ok' => $ok];
    echo ($ok ? "  ✓ " : "  ✗ ") . "{$id} {$nombre}" . ($ok ? '' : "  → obtenido: " . (is_string($obtenido) ? $obtenido : json_encode($obtenido, JSON_UNESCAPED_UNICODE))) . "\n";
}
function act(array $x): array
{
    return $x + ['codigo' => 'A', 'nombre' => 'Actividad', 'inicio' => '2026-11-02', 'fin' => '2026-11-13', 'total' => 10, 'ejecutado' => 0, 'rendimiento' => 1,
        'unidad' => 'm2', 'predecesoras' => '', 'restriccion' => '', 'liberacion' => '', 'fin_real' => ''];
}
function uno(array $x, string $corte = '2026-11-09', string $cierre = '2026-11-13', array $dias = [1, 2, 3, 4, 5], int $umbral = 2): array
{
    return (new Motor($dias, ['2026-12-08']))->analizar([act($x)], $corte, $cierre, $umbral)[0];
}

echo "Motor (lun-vie, corte lunes 09/11/2026, cierre viernes 13/11/2026)\n";
$r = uno(['fin' => '2026-11-16', 'total' => 10, 'ejecutado' => 6, 'rendimiento' => 2]);
caso('motor', 'T1', 'A tiempo: resta 2 días, margen 3 > umbral 2', 'verde · 11/11', "{$r['estado']} · {$r['proyeccion']}", $r['estado'] === 'verde' && $r['proyeccion'] === '2026-11-11');
$r = uno(['fin' => '2026-11-27', 'total' => 100, 'ejecutado' => 0, 'rendimiento' => 10]);
caso('motor', 'T2', 'Cruza la semana pero cumple su fin', 'verde y continúa=sí (no rojo automático)', "{$r['estado']} · continúa=" . ($r['continua'] ? 'sí' : 'no'), $r['estado'] === 'verde' && $r['continua']);
$r = uno(['fin' => '2026-11-13', 'total' => 60, 'ejecutado' => 0, 'rendimiento' => 10]);
caso('motor', 'T3', 'Atraso proyectado: resta 6 días y vencen en 4', 'rojo · margen −2 laborables · 17/11', "{$r['estado']} · margen {$r['holgura']} · {$r['proyeccion']}", $r['estado'] === 'rojo' && $r['holgura'] === -2 && $r['proyeccion'] === '2026-11-17');
$r = uno(['fin' => '2026-11-13', 'total' => 30, 'ejecutado' => 0, 'rendimiento' => 10]);
caso('motor', 'T4', 'Poco margen (rendimiento justo): vigilar', 'amarillo · margen 1', "{$r['estado']} · margen {$r['holgura']}", $r['estado'] === 'amarillo' && $r['holgura'] === 1);
$m = (new Motor())->analizar([act(['codigo' => 'P', 'total' => 30, 'rendimiento' => 10]), act(['codigo' => 'S', 'predecesoras' => 'P', 'total' => 2, 'rendimiento' => 1, 'fin' => '2026-11-20'])], '2026-11-09', '2026-11-13');
caso('motor', 'T5a', 'Predecesora pendiente desplaza a la sucesora (FC)', 'P fin 12/11 → S inicia 13/11 y termina 16/11', "P {$m[0]['proyeccion']} → S {$m[1]['proyeccion']}", $m[0]['proyeccion'] === '2026-11-12' && $m[1]['proyeccion'] === '2026-11-16');
$m = (new Motor())->analizar([act(['codigo' => 'P', 'restriccion' => 'Plano sin aprobar']), act(['codigo' => 'S', 'predecesoras' => 'P'])], '2026-11-09', '2026-11-13');
caso('motor', 'T5b', 'Predecesora bloqueada sin liberación', 'P y S naranja', "{$m[0]['estado']} / {$m[1]['estado']}", $m[0]['estado'] === 'naranja' && $m[1]['estado'] === 'naranja');
$r = uno(['rendimiento' => '']);
caso('motor', 'T6', 'Sin rendimiento: no se proyecta', 'gris, sin fecha', "{$r['estado']} · " . ($r['proyeccion'] ?? 'sin fecha'), $r['estado'] === 'gris' && $r['proyeccion'] === null);
$acts = (new Motor())->analizar([act(['codigo' => 'C', 'fin' => '2026-11-12', 'total' => 5, 'ejecutado' => 5, 'fin_real' => '2026-11-12']), act(['codigo' => 'L', 'fin' => '2026-11-20', 'total' => 100, 'ejecutado' => 0, 'rendimiento' => 5])], '2026-11-09', '2026-11-13');
$p = Motor::ppc($acts);
caso('motor', 'T8', 'PPC 100 % no oculta un atraso fuera del compromiso', 'PPC 100 y una actividad roja', "PPC {$p['ppc']} · L {$acts[1]['estado']}", $p['ppc'] == 100 && $acts[1]['estado'] === 'rojo');
$r = uno(['fin' => '2026-12-10', 'total' => 2, 'ejecutado' => 0, 'rendimiento' => 1], '2026-12-04', '2026-12-11');
caso('motor', 'X1', 'Feriado 08/12 no cuenta como día laborable', '07/12 y 09/12 → fin 09/12', $r['proyeccion'], $r['proyeccion'] === '2026-12-09');
$m = (new Motor())->analizar([act(['codigo' => 'A', 'predecesoras' => 'B']), act(['codigo' => 'B', 'predecesoras' => 'A'])], '2026-11-09', '2026-11-13');
caso('motor', 'X2', 'Ciclo de dependencias', 'ambas gris con "Ciclo"', $m[0]['motivo'], $m[0]['estado'] === 'gris' && str_contains($m[0]['motivo'], 'Ciclo'));
$m = (new Motor())->analizar([act(['codigo' => 'A']), act(['codigo' => 'A'])], '2026-11-09', '2026-11-13');
caso('motor', 'X3', 'ID duplicado', 'gris "ID duplicado"', $m[0]['motivo'], $m[0]['estado'] === 'gris' && str_contains($m[0]['motivo'], 'duplicado'));
$r = uno(['total' => 5, 'ejecutado' => 5, 'fin_real' => '2026-11-20']);
caso('motor', 'X4', 'Fin real posterior al cierre', 'gris', $r['motivo'], $r['estado'] === 'gris');
$r = uno(['fin' => '2026-11-10', 'total' => 5, 'ejecutado' => 5, 'fin_real' => '2026-11-12']);
caso('motor', 'X5', 'Terminada tarde con fin real', 'azul · atraso +2', "{$r['estado']} · {$r['atraso']}", $r['estado'] === 'azul' && $r['atraso'] === 2);
caso('motor', 'X6', 'Fecha serial de Excel (sistema 1900)', '46335 → 2026-11-09', Motor::iso(Motor::fecha('46335')), Motor::iso(Motor::fecha('46335')) === '2026-11-09');
$r = uno(['fin' => '2026-11-14', 'total' => 6, 'ejecutado' => 0, 'rendimiento' => 1], '2026-11-07', '2026-11-14', [1, 2, 3, 4, 5, 6]);
caso('motor', 'X7', 'Calendario lunes-sábado', '6 días desde el lunes → sábado 14/11 · amarillo', "{$r['proyeccion']} · {$r['estado']}", $r['proyeccion'] === '2026-11-14' && $r['estado'] === 'amarillo');
$r = (new Motor())->analizar([act(['fin' => '2026-11-20', 'total' => 10, 'ejecutado' => 4, 'rendimiento' => 2])], '2026-11-09', '2026-11-13', 2, '2026-11-11')[0];
caso('motor', 'X8', 'Fecha de datos (último parte) mueve el inicio del restante', 'inicia 12/11 → fin 16/11', $r['proyeccion'], $r['proyeccion'] === '2026-11-16');
try {
    uno([], '2026-11-09', '2026-11-13', [1, 2, 3, 4, 5], 99);
    caso('motor', 'X9', 'Umbral fuera de rango', 'excepción', 'sin excepción', false);
} catch (RuntimeException $e) {
    caso('motor', 'X9', 'Umbral fuera de rango', 'excepción', $e->getMessage(), true);
}
$r = uno(['total' => 10, 'ejecutado' => 12]);
caso('motor', 'X10', 'Ejecutado mayor que el total', 'gris', $r['motivo'], $r['estado'] === 'gris');
$r = uno(['restriccion' => 'Permiso', 'liberacion' => '2026-11-12', 'total' => 2, 'ejecutado' => 0, 'rendimiento' => 1, 'fin' => '2026-11-20']);
caso('motor', 'X11', 'Restricción con liberación programada', 'inicia 12/11 → fin 13/11', $r['proyeccion'], $r['proyeccion'] === '2026-11-13');

echo "Importación\n";
$csv = Importador::leer(RAIZ . '/datos/Claveles_S46_plan.csv', 'x.csv');
$xls = Importador::leer(RAIZ . '/datos/Claveles_S46_plan.xlsx', 'x.xlsx');
$rc = Importador::aplicarMapeo($csv, Importador::proponerMapeo($csv[0]));
$rx = Importador::aplicarMapeo($xls, Importador::proponerMapeo($xls[0]));
$igual = count($rc) === count($rx) && array_column($rc, 'fin') === array_column($rx, 'fin') && array_column($rc, 'codigo') === array_column($rx, 'codigo');
caso('motor', 'I1', 'CSV y XLSX de la misma planificación producen los mismos registros', '13 filas iguales', count($rc) . ' / ' . count($rx) . ($igual ? ' iguales' : ' distintas'), $igual && count($rc) === 13);
caso('motor', 'I2', 'Mapeo automático de los 15 encabezados', '15 campos', count(Importador::proponerMapeo($csv[0])) . ' campos', count(Importador::proponerMapeo($csv[0])) === 15);
$e = Importador::leer(RAIZ . '/datos/Prueba_errores.csv', 'e.csv');
$b = Repo::erroresBloqueantes(Importador::aplicarMapeo($e, Importador::proponerMapeo($e[0])));
caso('motor', 'I3', 'Archivo con errores se bloquea antes de guardar', '3 errores (ID repetido, fecha 31/02, ejecutado no numérico)', count($b) . ': ' . implode(' ', $b), count($b) === 3);
try {
    Importador::leer(RAIZ . '/datos/semilla_demo.json', 'x.json');
    caso('motor', 'I4', 'Formato no admitido', 'rechazo', 'aceptado', false);
} catch (RuntimeException $ex) {
    caso('motor', 'I4', 'Formato no admitido', 'rechazo', $ex->getMessage(), true);
}

$cx = Importador::leer(RAIZ . '/datos/Plantilla_Casuisticas_S47.xlsx', 'c.xlsx');
$rcx = Importador::aplicarMapeo($cx, Importador::proponerMapeo($cx[0]));
$ana = (new Motor([1, 2, 3, 4, 5, 6], ['2026-12-08', '2026-12-25']))->analizar($rcx, '2026-11-14', '2026-11-21', 2, '2026-11-21');
$esp = ['C01' => 'verde', 'C02' => 'azul', 'C03' => 'rojo', 'C04' => 'verde', 'C05' => 'rojo', 'C06' => 'naranja', 'C07' => 'naranja', 'C08' => 'rojo', 'C09' => 'gris', 'C10' => 'gris', 'C11' => 'rojo', 'C12' => 'azul', 'C13' => 'rojo', 'C14' => 'rojo', 'C15' => 'rojo', 'C16' => 'rojo', 'C17' => 'rojo', 'C18' => 'amarillo', 'C19' => 'rojo', 'C20' => 'amarillo', 'C21' => 'azul', 'C22' => 'azul', 'C23' => 'azul'];
$obt = array_column($ana, 'estado', 'codigo');
$dif = array_keys(array_diff_assoc($esp, $obt));
$pc = Motor::ppc($ana);
caso('motor', 'I5', 'Plantilla de casuísticas (23 casos): cada fila obtiene el estado documentado', '23 estados como la guía · PPC 5/16', (23 - count($dif)) . '/23 · PPC ' . $pc['cumplidas'] . '/' . $pc['comprometidas'] . ($dif ? ' · difieren: ' . implode(',', $dif) : ''), !$dif && $pc['cumplidas'] === 5 && $pc['comprometidas'] === 16);

echo "Rendimiento\n";
$big = [];
for ($i = 1; $i <= 5000; $i++) {
    $big[] = act(['codigo' => "R{$i}", 'predecesoras' => $i > 1 && $i % 10 ? 'R' . ($i - 1) : '', 'total' => 10, 'ejecutado' => 2, 'rendimiento' => 4, 'fin' => '2027-06-30']);
}
$t0 = microtime(true);
(new Motor())->analizar($big, '2026-11-09', '2026-11-13');
$ms = (microtime(true) - $t0) * 1000;
echo '  5000 actividades en ' . round($ms) . " ms\n";

echo "Validación de salidas del LLM y respaldo\n";
$svc = ia();
$nota = 'Falta acero por entrega atrasada del proveedor';
$v = fn($r) => $svc->validar($r, $nota);
caso('llm', 'V1', 'Salida correcta', 'válida', $v(['categoria' => 'materiales', 'cita' => 'entrega atrasada del proveedor', 'confianza' => 0.9, 'justificacion' => 'x'])['ok'] ? 'válida' : 'rechazada', $v(['categoria' => 'materiales', 'cita' => 'entrega atrasada del proveedor', 'confianza' => 0.9, 'justificacion' => 'x'])['ok']);
$x = $v(['categoria' => 'materiales', 'cita' => 'el camión se volcó', 'confianza' => 0.9, 'justificacion' => 'x']);
caso('llm', 'V2', 'Cita inventada (no está en la nota)', 'rechazada: cita_en_fuente', implode(',', $x['fallos']), !$x['ok'] && in_array('validacion:cita_en_fuente', $x['fallos'], true));
$x = $v(['categoria' => 'presupuesto', 'cita' => 'Falta acero', 'confianza' => 0.9, 'justificacion' => 'x']);
caso('llm', 'V3', 'Categoría fuera de la lista', 'rechazada', implode(',', $x['fallos']), !$x['ok']);
$x = $v(['categoria' => 'materiales', 'cita' => 'Falta acero', 'confianza' => 1.7, 'justificacion' => 'x']);
caso('llm', 'V4', 'Confianza fuera de [0,1]', 'rechazada', implode(',', $x['fallos']), !$x['ok']);
$x = $v(['categoria' => 'materiales']);
caso('llm', 'V5', 'JSON incompleto', 'rechazada', implode(',', $x['fallos']), !$x['ok']);
$x = $v(['categoria' => 'sin_dato', 'cita' => null, 'confianza' => 0.2, 'justificacion' => 'no hay evidencia']);
caso('llm', 'V6', 'Abstención del LLM ("sin_dato" sin cita)', 'válida', $x['ok'] ? 'válida' : 'rechazada', $x['ok']);

final class LlmFalso implements ProveedorLLM
{
    public function __construct(private string $resp, private bool $falla = false) {}
    public function disponible(): bool { return true; }
    public function modelo(): string { return 'simulado'; }
    public function completar(string $s, string $u, int $m = 400): string
    {
        if ($this->falla) {
            throw new RuntimeException('timeout simulado');
        }
        return $this->resp;
    }
}
$local = ClasificadorLocal::desdeArchivo(RAIZ . '/datos/ia_entrenamiento.json');
$a = ['codigo' => 'CL-02', 'nombre' => 'Acero', 'observacion' => $nota, 'motivo' => ''];
$p = (new ServicioIA($local, new LlmFalso('{"categoria":"materiales","cita":"entrega atrasada del proveedor","confianza":0.93,"justificacion":"Retraso de proveedor"}')))->proponerCausa($a);
caso('llm', 'V7', 'LLM válido → se usa su propuesta', 'modo claude · materiales', "{$p['modo']} · {$p['categoria']}", $p['modo'] === 'claude' && $p['categoria'] === 'materiales');
$p = (new ServicioIA($local, new LlmFalso('{"categoria":"clima","cita":"llovió fuerte","confianza":0.9,"justificacion":"x"}')))->proponerCausa($a);
caso('llm', 'V8', 'LLM con cita inventada → respaldo local', 'modo local · materiales', "{$p['modo']} · {$p['categoria']}", $p['modo'] === 'local' && $p['categoria'] === 'materiales');
$p = (new ServicioIA($local, new LlmFalso('', true)))->proponerCausa($a);
caso('llm', 'V9', 'API caída / timeout → respaldo local', 'modo local', "{$p['modo']} · {$p['categoria']}", $p['modo'] === 'local');
$p = (new ServicioIA($local, null, 'manual'))->proponerCausa($a);
caso('llm', 'V10', 'Modo manual → no propone', 'sin_dato · manual', "{$p['categoria']} · {$p['modo']}", $p['categoria'] === 'sin_dato' && $p['modo'] === 'manual');
$p = (new ServicioIA($local))->proponerCausa(['codigo' => 'X', 'nombre' => 'x', 'observacion' => '', 'motivo' => '']);
caso('llm', 'V11', 'Sin observación → no inventa causa', 'sin_dato', $p['categoria'], $p['categoria'] === 'sin_dato');

echo "Evaluación del clasificador local\n";
function evaluar(ClasificadorLocal $c, string $archivo): array
{
    $casos = json_decode((string)file_get_contents(RAIZ . '/datos/' . $archivo), true)['casos'];
    $det = [];
    $acept = $corr = $abst = $conCausa = $sdOk = $sd = 0;
    foreach ($casos as $x) {
        $r = $c->clasificar($x['nota']);
        $det[] = ['nota' => $x['nota'], 'esperado' => $x['esperado'], 'obtenido' => $r['categoria'], 'candidata' => $r['candidata'], 'puntaje' => $r['puntaje']];
        if ($x['esperado'] === 'sin_dato') {
            $sd++;
            $sdOk += $r['categoria'] === 'sin_dato';
            continue;
        }
        $conCausa++;
        if ($r['aceptada']) {
            $acept++;
            $corr += $r['categoria'] === $x['esperado'];
        } else {
            $abst++;
        }
    }
    return ['notas' => count($casos), 'notas_con_causa' => $conCausa, 'aceptadas' => $acept, 'correctas' => $corr, 'abstenciones' => $abst,
        'precision' => $acept ? round(100 * $corr / $acept, 1) : null, 'cobertura' => $conCausa ? round(100 * $acept / $conCausa, 1) : null,
        'sin_dato_correctos' => $sdOk, 'sin_dato_total' => $sd, 'detalle' => $det];
}
$ev = evaluar($local, 'ia_evaluacion.json');
$ci = evaluar($local, 'ia_evaluacion_ciega.json');
printf("  evaluación: %d/%d aceptadas correctas, cobertura %.1f%%\n  ciega: %d/%d aceptadas correctas, cobertura %.1f%%\n", $ev['correctas'], $ev['aceptadas'], $ev['cobertura'], $ci['correctas'], $ci['aceptadas'], $ci['cobertura']);

echo "Flujo extremo a extremo (BD)\n";
$pdo = db();
$pdo->exec("DELETE FROM obras WHERE codigo = 'TST'");
$u = $pdo->query("SELECT id, rol FROM usuarios")->fetchAll(PDO::FETCH_KEY_PAIR);
$uP = (int)array_search('planeamiento', $u, true);
$uR = (int)array_search('produccion', $u, true);
$pdo->exec("INSERT INTO obras (codigo, nombre, dias_laborables, feriados, umbral_vigilar) VALUES ('TST', 'Obra de prueba automatizada', '1,2,3,4,5,6', '2026-12-08', 2)");
$oid = (int)$pdo->lastInsertId();
try {
    $cid = Repo::importar($oid, 46, '2026-11-07', '2026-11-14', $rx, 'Claveles_S46_plan.xlsx', $uP);
    $acts = Repo::actividades($cid);
    $porCod = fn() => array_column(Repo::actividades($cid), null, 'codigo');
    caso('flujo', 'E1', 'Importar XLSX crea el corte y analiza 13 actividades', '13 actividades con estado', count(array_filter($acts, fn($a) => $a['estado'])) . ' con estado', count($acts) === 13 && !array_filter($acts, fn($a) => !$a['estado']));
    $antes = $porCod()['CL-02'];
    Repo::registrarAvance((int)$antes['id'], '2026-11-10', 1, 'Falta acero por entrega atrasada del proveedor', $uR);
    $desp = $porCod()['CL-02'];
    caso('flujo', 'E2', 'Parte diario suma avance, fija fecha de datos y recalcula', 'ejecutado 4 · fecha de datos 10/11', "ejecutado {$desp['ejecutado']} · fecha datos " . Repo::fechaDato($cid) . " · proyección {$antes['proyeccion']}→{$desp['proyeccion']}", (float)$desp['ejecutado'] === 4.0 && Repo::fechaDato($cid) === '2026-11-10');
    try {
        Repo::registrarAvance((int)$desp['id'], '2026-11-11', 99, '', $uR);
        caso('flujo', 'E3', 'Rechaza acumulado mayor al metrado', 'error', 'aceptado', false);
    } catch (RuntimeException $ex) {
        caso('flujo', 'E3', 'Rechaza acumulado mayor al metrado', 'error', $ex->getMessage(), true);
    }
    $b0 = Repo::bloqueos($cid);
    caso('flujo', 'E4', 'Aprobación bloqueada con datos pendientes y causas sin decisión', '> 0 bloqueos', count($b0) . ' bloqueos', count($b0) > 0);
    try {
        Repo::aprobar($cid, '', $uP);
        caso('flujo', 'E5', 'Intento de aprobar con bloqueos', 'rechazado', 'aprobado', false);
    } catch (RuntimeException $ex) {
        caso('flujo', 'E5', 'Intento de aprobar con bloqueos', 'rechazado', mb_substr($ex->getMessage(), 0, 90), true);
    }
    // cerrar la semana: completar lo que se cumplió
    foreach (['CL-01' => 30, 'CL-03' => 240, 'CL-05' => 6, 'CL-08' => 1, 'CL-12' => 1] as $cod => $q) {
        Repo::registrarAvance((int)$porCod()[$cod]['id'], '2026-11-12', $q, '', $uR);
    }
    Repo::editarActividad((int)$porCod()['CL-06']['id'], ['rendimiento' => '1.5'], $uP);
    caso('flujo', 'E6', 'Corregir rendimiento saca la actividad de "revisar datos"', 'CL-06 deja de ser gris', $porCod()['CL-06']['estado'], $porCod()['CL-06']['estado'] !== 'gris');
    $n = Repo::proponerCausas($cid, $uP);
    $inc = Repo::incumplidas(Repo::actividades($cid));
    $conCita = array_filter($inc, fn($a) => $a['categoria_ia'] === 'sin_dato' || ($a['cita_ia'] && str_contains(ServicioIA::norm((string)$a['observacion']), ServicioIA::norm($a['cita_ia']))));
    caso('flujo', 'E7', 'IA propone causa para cada compromiso incumplido, con cita literal o abstención', "{$n} propuestas, todas con cita verificable", count($conCita) . "/{$n} verificables", $n === count($inc) && count($conCita) === $n);
    try {
        Repo::revisarCausa((int)$inc[0]['id'], 'editar', 'clima', '', $uP);
        caso('flujo', 'E8', 'Editar sin comentario', 'rechazado', 'aceptado', false);
    } catch (RuntimeException $ex) {
        caso('flujo', 'E8', 'Editar la propuesta IA exige comentario', 'rechazado', $ex->getMessage(), true);
    }
    foreach ($inc as $a) {
        $a['categoria_ia'] === 'sin_dato'
            ? Repo::revisarCausa((int)$a['id'], 'rechazar', '', 'Falta detalle', $uP)
            : Repo::revisarCausa((int)$a['id'], 'aceptar', '', '', $uP);
        Repo::reprogramar((int)$a['id'], '2026-11-20', 'Seguimiento en reunión (prueba)', $uP);
    }
    $b1 = Repo::bloqueos($cid);
    caso('flujo', 'E9', 'Sin bloqueos tras revisión humana y reprogramación', '0 bloqueos', count($b1) . ' bloqueos', count($b1) === 0);
    Repo::aprobar($cid, 'Prueba automatizada', $uP);
    $c = Repo::corte($cid);
    caso('flujo', 'E10', 'Aprobación congela el corte, calcula PPC y guarda snapshot', 'aprobado · PPC · snapshot', "{$c['estado']} · PPC {$c['ppc']}% ({$c['cumplidas']}/{$c['comprometidas']}) · snapshot " . strlen((string)$c['snapshot']) . ' B', $c['estado'] === 'aprobado' && $c['ppc'] !== null && strlen((string)$c['snapshot']) > 1000);
    try {
        Repo::registrarAvance((int)$porCod()['CL-02']['id'], '2026-11-13', 1, '', $uR);
        caso('flujo', 'E11', 'Corte aprobado es inmutable', 'rechazo', 'modificado', false);
    } catch (RuntimeException $ex) {
        caso('flujo', 'E11', 'Corte aprobado es inmutable', 'rechazo', $ex->getMessage(), true);
    }
    $nid = Repo::siguienteCorte($cid, $uP);
    $sig = array_column(Repo::actividades($nid), null, 'codigo');
    $okT7 = isset($sig['CL-02']) && $sig['CL-02']['fin'] === '2026-11-20' && $sig['CL-02']['fin_base'] === '2026-11-11' && !isset($sig['CL-01']);
    caso('flujo', 'T7', 'Semana siguiente: pendiente con nueva fecha vigente y línea base conservada', 'CL-02 fin 20/11, base 11/11; CL-01 terminada no pasa', isset($sig['CL-02']) ? "fin {$sig['CL-02']['fin']} · base {$sig['CL-02']['fin_base']} · " . count($sig) . ' actividades' : 'sin CL-02', $okT7);
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE (entidad='corte' AND entidad_id IN ({$cid},{$nid})) OR (entidad='actividad' AND entidad_id IN (SELECT id FROM actividades WHERE corte_id={$cid}))")->fetchColumn();
    caso('flujo', 'E12', 'Cada acción queda en la auditoría', '≥ 15 eventos (importar, partes, IA, revisiones, aprobación…)', "{$aud} eventos", $aud >= 15);
} finally {
    $pdo->exec("DELETE FROM auditoria WHERE (entidad='corte' AND entidad_id IN (SELECT id FROM cortes WHERE obra_id={$oid})) OR (entidad='actividad' AND entidad_id IN (SELECT a.id FROM actividades a JOIN cortes c ON c.id=a.corte_id WHERE c.obra_id={$oid}))");
    $pdo->exec("DELETE FROM obras WHERE id = {$oid}");
}

echo "Línea base (back-test sobre la semana 46 de Claveles)\n";
// Predicción al corte (07/11, sin partes) vs. lo que ocurrió al cierre (14/11) según la BD de la demo.
$obraC = $pdo->query("SELECT * FROM obras WHERE codigo='CLV'")->fetch();
$c46 = $pdo->query("SELECT * FROM cortes WHERE obra_id={$obraC['id']} AND semana=46")->fetch();
$lb = null;
if ($c46) {
    $pred = Motor::desdeObra($obraC)->analizar($rc, '2026-11-07', '2026-11-14', (int)$obraC['umbral_vigilar']);
    $real = array_column(Repo::actividades((int)$c46['id']), null, 'codigo');
    $evaluables = array_filter($pred, fn($a) => $a['fin'] <= '2026-11-14' && $a['estado'] !== 'azul');
    $tarde = [];
    foreach ($evaluables as $a) {
        $r2 = $real[$a['codigo']];
        $tarde[$a['codigo']] = !$r2['fin_real'] || $r2['fin_real'] > $r2['fin'];
    }
    $metodo = function (callable $f) use ($evaluables, $tarde) {
        $vp = $fp = $fn = 0;
        $det = [];
        foreach ($evaluables as $a) {
            $s = $f($a);
            $t = $tarde[$a['codigo']];
            if ($s && $t) $vp++; elseif ($s && !$t) $fp++; elseif (!$s && $t) $fn++;
            if ($s) $det[] = $a['codigo'];
        }
        return ['detecta' => $vp + $fp, 'vp' => $vp, 'fp' => $fp, 'fn' => $fn, 'recall' => ($vp + $fn) ? round(100 * $vp / ($vp + $fn), 1) : null, 'precision' => ($vp + $fp) ? round(100 * $vp / ($vp + $fp), 1) : null, 'marcadas' => $det];
    };
    $lb = [
        'descripcion' => 'Predicción hecha con el plan al corte del 07/11 (sin partes diarios) contra lo que ocurrió al cierre del 14/11 en la semana 46 de la obra Claveles. "Tarde" = no terminó o terminó después de su fin planificado. Se evalúan las actividades con fin planificado dentro de la semana.',
        'evaluadas' => count($evaluables),
        'tarde_real' => array_keys(array_filter($tarde)),
        'metodos' => [
            ['nombre' => 'Regla reactiva: fin planificado anterior al corte y sin terminar', 'r' => $metodo(fn($a) => $a['fin'] < '2026-11-07')],
            ['nombre' => 'Motor: solo "Atraso probable" (rojo)', 'r' => $metodo(fn($a) => $a['estado'] === 'rojo')],
            ['nombre' => 'Motor: rojo + bloqueada + vigilar (requiere atención)', 'r' => $metodo(fn($a) => in_array($a['estado'], ['rojo', 'naranja', 'amarillo'], true))],
        ],
        'lectura' => 'Datos ficticios diseñados por el equipo: la comparación ilustra el método de validación, no prueba desempeño en obra real. Debe repetirse con cortes reales anonimizados.',
    ];
    foreach ($lb['metodos'] as $mm) {
        printf("  %-62s detecta %d · VP %d · FP %d · FN %d\n", $mm['nombre'], $mm['r']['detecta'], $mm['r']['vp'], $mm['r']['fp'], $mm['r']['fn']);
    }
}

$tot = fn($g) => ['casos' => $casos[$g], 'aprobados' => count(array_filter($casos[$g], fn($c) => $c['ok'])), 'total' => count($casos[$g])];
$salida = [
    'generado' => date('Y-m-d H:i'),
    'entorno' => ['php' => PHP_VERSION, 'bd' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'ia' => ia()->descripcionModo()],
    'motor' => $tot('motor'),
    'flujo' => $tot('flujo'),
    'rendimiento' => ['actividades' => 5000, 'ms' => round($ms, 1)],
    'ia' => [
        'validacion_llm' => $tot('llm'),
        'local' => ['eval' => $ev, 'ciega' => $ci],
        'lectura' => 'El conjunto de evaluación se usó para ampliar el corpus (de 13/30 a 28/30 aciertos exactos), por eso su resultado es optimista. El conjunto ciego, redactado después y no usado para ajustar, muestra el comportamiento esperado: cuando acepta, acierta; pero se abstiene en muchas notas con vocabulario nuevo y las deriva a la persona. Para subir la cobertura: usar el modo Claude API o ampliar el corpus con notas reales etiquetadas.',
    ],
    'linea_base' => $lb,
];
@mkdir(RAIZ . '/evidencias');
file_put_contents(RAIZ . '/evidencias/resultados_pruebas.json', json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fallos = array_sum(array_map(fn($g) => count(array_filter($casos[$g], fn($c) => !$c['ok'])), array_keys($casos)));
echo "\nResultado: " . ($fallos ? "{$fallos} FALLO(S)" : 'todos los casos aprobados') . " → evidencias/resultados_pruebas.json\n";
exit($fallos ? 1 : 0);
