<?php
declare(strict_types=1);
/** Actualiza una base ya instalada sin borrar datos:  php database/migrar.php */
if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Accesos;

$pdo = db();
$hay = function (string $tabla, ?string $col = null) use ($pdo): bool {
    $st = $col === null
        ? $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?')
        : $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute($col === null ? [$tabla] : [$tabla, $col]);
    return (bool)$st->fetchColumn();
};

if (!$hay('cortes', 'fecha_datos')) {
    $pdo->exec('ALTER TABLE cortes ADD COLUMN fecha_datos DATE NULL AFTER fecha_cierre');
    echo "→ cortes.fecha_datos agregado\n";
}
$sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/migracion_telegram.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $st) {
    $pdo->exec($st);
}

// Control de acceso (registro con código de equipo + aprobación de Gerencia, bloqueo por intentos)
$columnas = [
    'estado' => "ENUM('activo','pendiente','rechazado','suspendido') NOT NULL DEFAULT 'activo' AFTER activo",
    'rol_solicitado' => "ENUM('planeamiento','produccion','gerencia') NULL AFTER estado",
    'motivo_solicitud' => 'VARCHAR(300) NULL AFTER rol_solicitado',
    'invitacion_id' => 'INT NULL AFTER motivo_solicitud',
    'revisado_por' => 'INT NULL AFTER invitacion_id',
    'revisado_en' => 'DATETIME NULL AFTER revisado_por',
    'comentario_revision' => 'VARCHAR(300) NULL AFTER revisado_en',
    'intentos_fallidos' => 'SMALLINT NOT NULL DEFAULT 0 AFTER comentario_revision',
    'bloqueado_hasta' => 'DATETIME NULL AFTER intentos_fallidos',
    'ultimo_ingreso' => 'DATETIME NULL AFTER bloqueado_hasta',
];
foreach ($columnas as $c => $def) {
    if (!$hay('usuarios', $c)) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN {$c} {$def}");
        echo "→ usuarios.{$c} agregado\n";
    }
}
$pdo->exec("UPDATE usuarios SET estado = 'suspendido' WHERE activo = 0 AND estado = 'activo'");
$sql = (string)file_get_contents(__DIR__ . '/schema.sql');
foreach (['invitaciones', 'intentos_acceso'] as $t) {
    if (!$hay($t) && preg_match('/CREATE TABLE ' . $t . ' \(.*?\) ENGINE=[^;]+;/s', $sql, $m)) {
        $pdo->exec($m[0]);
        echo "→ tabla {$t} creada\n";
    }
}

// La contraseña que estuvo publicada en versiones anteriores no puede seguir sirviendo para entrar
$demo = (string)env('DEMO_CLAVE', '');
[$n, $clave] = Accesos::rotarClavePublicada($demo ?: null);
if ($n > 0) {
    if ($demo) {
        echo "→ {$n} cuenta(s) con la contraseña publicada ahora usan la definida en DEMO_CLAVE.\n";
    } else {
        $f = RAIZ . '/storage/credenciales_demo.txt';
        @mkdir(dirname($f), 0700, true);
        file_put_contents($f, "Contraseña de las cuentas demo: {$clave}\n");
        @chmod($f, 0600);
        echo "→ {$n} cuenta(s) con la contraseña publicada recibieron una nueva: {$clave}  (guardada en storage/credenciales_demo.txt)\n";
    }
}
echo "Base actualizada (Telegram y control de acceso listos).\n";
