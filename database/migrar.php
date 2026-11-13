<?php
declare(strict_types=1);
/** Actualiza una base ya instalada sin borrar datos:  php database/migrar.php */
if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';
$pdo = db();
$col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cortes' AND COLUMN_NAME = 'fecha_datos'")->fetchColumn();
if (!$col) {
    $pdo->exec('ALTER TABLE cortes ADD COLUMN fecha_datos DATE NULL AFTER fecha_cierre');
    echo "→ cortes.fecha_datos agregado\n";
}
$sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/migracion_telegram.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $st) {
    $pdo->exec($st);
}
echo "Base actualizada (tablas del bot de Telegram listas).\n";
