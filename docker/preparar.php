<?php
declare(strict_types=1);
/**
 * Espera a MySQL y deja la base lista:
 *  - web: si la base está vacía instala tablas + demo; si ya existe, solo migra (no borra datos).
 *  - bot: espera a que la web haya creado las tablas.
 */
require __DIR__ . '/../app/bootstrap.php';
$rol = $argv[1] ?? 'web';
for ($i = 1; ; $i++) {
    try {
        $pdo = db();
        break;
    } catch (Throwable $e) {
        if ($i >= 30) {
            fwrite(STDERR, "MySQL no responde: {$e->getMessage()}\n");
            exit(1);
        }
        echo "Esperando MySQL ({$i})…\n";
        sleep(3);
    }
}
$hay = fn() => (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'")->fetchColumn();
if ($rol === 'bot') {
    for ($i = 0; !$hay() && $i < 40; $i++) {
        echo "Esperando que la web cree las tablas…\n";
        sleep(3);
    }
    exit(0);
}
if (!$hay()) {
    echo "Base vacía: instalando tablas y datos de demostración…\n";
    passthru('php ' . escapeshellarg(RAIZ . '/database/instalar.php'), $rc);
    exit($rc);
}
passthru('php ' . escapeshellarg(RAIZ . '/database/migrar.php'), $rc);
exit($rc);
