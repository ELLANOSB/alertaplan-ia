<?php
declare(strict_types=1);

/**
 * Instalador / semilla de la demo.
 *   php database/instalar.php          → crea tablas y carga la demo (Claveles S44-S46 + Vía Andina)
 *   php database/instalar.php --vacio  → solo tablas y usuarios
 * La semilla recorre el MISMO flujo que la aplicación (importar → partes diarios → IA → revisión → aprobación),
 * de modo que las semanas aprobadas tienen auditoría y snapshot reales.
 */

if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Importador;
use App\Repo;

$vacio = in_array('--vacio', $argv, true);
$pdo = db();

echo "→ Creando tablas…\n";
$sql = (string)file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}

echo "→ Usuarios de demostración…\n";
$clave = password_hash('Aecode2026!', PASSWORD_DEFAULT);
$usuarios = [
    ['Ing. Patricia Salazar', 'Ingeniera de Planeamiento', 'planeamiento@claveles.demo', 'planeamiento'],
    ['Ing. Carlos Mendoza', 'Ingeniero de Producción', 'produccion@claveles.demo', 'produccion'],
    ['Arq. Roberto Vega', 'Gerente de Proyecto', 'gerencia@claveles.demo', 'gerencia'],
];
$ins = $pdo->prepare('INSERT INTO usuarios (nombre, cargo, email, rol, password_hash) VALUES (?,?,?,?,?)');
$uid = [];
foreach ($usuarios as $u) {
    $ins->execute([...$u, $clave]);
    $uid[$u[3]] = (int)$pdo->lastInsertId();
}
if ($vacio) {
    echo "Listo (sin datos de demo).\n";
    exit;
}

$spec = json_decode((string)file_get_contents(RAIZ . '/datos/semilla_demo.json'), true, 512, JSON_THROW_ON_ERROR);
// causa que la persona (Planeamiento) confirma en las semanas ya aprobadas
$decision = ['SOT-01' => 'equipos', 'ACE-01' => 'materiales', 'PAT-01' => 'mano_obra', 'ING-01' => 'ingenieria'];

foreach ($spec['obras'] as $o) {
    $pdo->prepare('INSERT INTO obras (codigo, nombre, cliente, ubicacion, etapa, dias_laborables, feriados, umbral_vigilar) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$o['codigo'], $o['nombre'], $o['cliente'], $o['ubicacion'], $o['etapa'], $o['dias'], $o['feriados'], $o['umbral']]);
    $oid = (int)$pdo->lastInsertId();
    echo "→ Obra {$o['nombre']}\n";
    foreach ($o['cortes'] as $c) {
        $filas = [$spec['encabezados'], ...array_map(fn($r) => array_map('strval', $r), $c['acts'])];
        $regs = Importador::aplicarMapeo($filas, Importador::proponerMapeo($filas[0]));
        $cid = Repo::importar($oid, $c['semana'], $c['corte'], $c['cierre'], $regs, $c['archivo'], $uid['planeamiento']);
        $partes = $c['partes'];
        usort($partes, fn($a, $b) => strcmp($a[1], $b[1]));
        $ids = [];
        foreach (Repo::actividades($cid) as $a) {
            $ids[$a['codigo']] = (int)$a['id'];
        }
        foreach ($partes as [$cod, $fecha, $cant, $nota]) {
            Repo::registrarAvance($ids[$cod], $fecha, (float)$cant, $nota, $uid['produccion']);
        }
        echo "   S{$c['semana']}: " . count($regs) . ' actividades, ' . count($partes) . " partes diarios";
        if ($c['aprobar'] === null) {
            echo " (abierta)\n";
            continue;
        }
        Repo::proponerCausas($cid, $uid['planeamiento']);
        foreach (Repo::incumplidas(Repo::actividades($cid)) as $a) {
            $quiere = $decision[$a['codigo']] ?? null;
            if ($quiere && $a['categoria_ia'] === $quiere) {
                Repo::revisarCausa((int)$a['id'], 'aceptar', '', 'Confirmado en reunión de producción', $uid['planeamiento']);
            } elseif ($quiere) {
                Repo::revisarCausa((int)$a['id'], 'editar', $quiere, 'Corregido por Planeamiento tras revisar el parte', $uid['planeamiento']);
            } else {
                Repo::revisarCausa((int)$a['id'], 'rechazar', '', 'Falta detalle en el parte diario', $uid['planeamiento']);
            }
            [$f, $acc] = $c['repro'][$a['codigo']] ?? [$a['proyeccion'], 'Seguimiento en reunión semanal'];
            Repo::reprogramar((int)$a['id'], $f, $acc, $uid['planeamiento']);
        }
        Repo::aprobar($cid, $c['aprobar'], $uid['planeamiento']);
        $k = Repo::corte($cid);
        echo " · aprobada con PPC {$k['ppc']}%\n";
    }
}
echo "Listo. Ingresa con planeamiento@claveles.demo / Aecode2026!\n";
