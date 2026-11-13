<?php
declare(strict_types=1);

/**
 * Arranque común: .env, autoload, zona horaria, base de datos y servicio de IA.
 */

const RAIZ = __DIR__ . '/..';

spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($ruta)) {
            require $ruta;
        }
    }
});

function env(string $clave, ?string $def = null): ?string
{
    static $vals = null;
    if ($vals === null) {
        $vals = [];
        $archivo = RAIZ . '/.env';
        if (is_file($archivo)) {
            foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                if (str_starts_with(ltrim($l), '#') || !str_contains($l, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $l, 2);
                $vals[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    $v = getenv($clave);
    return $v !== false ? $v : ($vals[$clave] ?? $def);
}

require __DIR__ . '/Web.php';

date_default_timezone_set(env('APP_TZ', 'America/Lima'));
mb_internal_encoding('UTF-8');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $sock = env('DB_SOCKET', '');
        $dsn = $sock !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $sock, env('DB_DATABASE', 'alertaplan_ia'))
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_DATABASE', 'alertaplan_ia'));
        $pdo = new PDO($dsn, env('DB_USERNAME', 'alertaplan'), env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function ia(): App\IA\ServicioIA
{
    static $s = null;
    if ($s === null) {
        $local = App\IA\ClasificadorLocal::desdeArchivo(RAIZ . '/datos/ia_entrenamiento.json');
        $key = (string)env('ANTHROPIC_API_KEY', '');
        $claude = $key !== '' ? new App\IA\ClienteClaude($key, (string)env('ANTHROPIC_MODEL', 'claude-haiku-4-5'), (int)env('IA_TIMEOUT', '20')) : null;
        $s = new App\IA\ServicioIA($local, $claude, (string)env('IA_MODO', 'auto'));
    }
    return $s;
}
