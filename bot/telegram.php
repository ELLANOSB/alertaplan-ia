<?php
declare(strict_types=1);

/**
 * Bot de Telegram de AlertaPlan IA (modo "long polling": solo conexiones salientes,
 * funciona en una Mac local o en un VPS sin abrir puertos).
 *   php bot/telegram.php              → atiende mensajes indefinidamente
 *   php bot/telegram.php --comandos   → registra el menú de comandos en Telegram y sale
 */
if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Telegram\Bot;
use App\Telegram\Cliente;

$dir = RAIZ . '/storage';
@mkdir($dir . '/telegram', 0700, true);
$log = fn(string $m) => file_put_contents($dir . '/telegram.log', date('Y-m-d H:i:s') . " {$m}\n", FILE_APPEND) && print(date('H:i:s') . " {$m}\n");

$lock = fopen($dir . '/telegram.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Ya hay otra instancia del bot en ejecución.\n");
}

$tg = new Cliente((string)env('TELEGRAM_BOT_TOKEN', ''));
$yo = $tg->llamar('getMe');
$log("Bot @{$yo['username']} conectado · IA: " . ia()->descripcionModo());

$comandos = [
    ['command' => 'estado', 'description' => 'PPC, avance y riesgos de la obra'],
    ['command' => 'atrasos', 'description' => 'Actividades con atraso probable o bloqueadas'],
    ['command' => 'actividad', 'description' => 'Detalle y causa: /actividad C03'],
    ['command' => 'causas', 'description' => 'Causas propuestas por la IA y decisiones'],
    ['command' => 'informe', 'description' => 'Informe semanal como archivo'],
    ['command' => 'parte', 'description' => 'Parte diario: /parte C03 1.5 nota'],
    ['command' => 'cargar', 'description' => 'Cómo enviar un Excel/CSV de planificación'],
    ['command' => 'obras', 'description' => 'Elegir obra (botones)'],
    ['command' => 'ayuda', 'description' => 'Qué puedo hacer'],
];
$tg->llamar('setMyCommands', ['commands' => $comandos]);
$tg->llamar('setMyDescription', ['description' => 'AlertaPlan IA · seguimiento de obra con alertas tempranas y causas propuestas por IA. Proyecto del Diplomado de IA en Ingeniería y Construcción (AECODE).']);
if (in_array('--comandos', $argv, true)) {
    $log('Comandos registrados.');
    exit;
}

$bot = new Bot($tg, $dir . '/telegram');
$offFile = $dir . '/telegram.offset';
$offset = is_file($offFile) ? (int)file_get_contents($offFile) : 0;
$pausa = 1;
while (true) {
    try {
        $ups = $tg->llamar('getUpdates', ['offset' => $offset, 'timeout' => 30, 'allowed_updates' => ['message', 'callback_query']], 45);
        $pausa = 1;
        foreach ($ups as $u) {
            $offset = (int)$u['update_id'] + 1;
            file_put_contents($offFile, (string)$offset);
            $from = $u['message']['from']['id'] ?? $u['callback_query']['from']['id'] ?? '?';
            $txt = $u['message']['text'] ?? $u['message']['caption'] ?? ($u['callback_query']['data'] ?? (isset($u['message']['document']) ? '[archivo]' : ''));
            $log("← {$from}: " . mb_substr(preg_replace('/\s+/', ' ', (string)$txt), 0, 80));
            try {
                db()->query('SELECT 1'); // reconecta si MySQL se reinició
            } catch (Throwable) {
            }
            $bot->procesar($u);
        }
    } catch (Throwable $e) {
        $log('Error: ' . $e->getMessage());
        sleep($pausa);
        $pausa = min($pausa * 2, 60);
    }
}
