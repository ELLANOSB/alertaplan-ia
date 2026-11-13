<?php
declare(strict_types=1);

namespace App;

/**
 * Utilidades web: sesión, autenticación por rol, CSRF, mensajes y formato.
 */
final class Web
{
    public const ROLES = [
        'planeamiento' => 'Planeamiento',
        'produccion' => 'Producción',
        'gerencia' => 'Gerencia (solo lectura)',
    ];

    public const INACTIVIDAD = 7200; // segundos sin actividad antes de cerrar la sesión

    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (env('CONFIAR_PROXY', '') === '1' && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }

    /** IP del cliente. Detrás de un proxy de confianza (Railway) se toma la que informa el proxy. */
    public static function ip(): string
    {
        if (env('CONFIAR_PROXY', '') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    public static function iniciarSesion(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            session_name('alertaplan');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => self::https()]);
            session_start();
        }
        if (isset($_SESSION['usuario']) && time() - (int)($_SESSION['visto'] ?? 0) > self::INACTIVIDAD) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('error', 'Tu sesión se cerró por inactividad. Ingresa nuevamente.');
        }
        $_SESSION['visto'] = time();
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /** @return string|null mensaje de error, o null si ingresó */
    public static function login(string $email, string $clave): ?string
    {
        $r = Accesos::autenticar($email, $clave, self::ip());
        if (!$r['ok']) {
            return $r['error'];
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = $r['usuario'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return null;
    }

    public static function requiere(array $roles = []): array
    {
        $s = self::usuario();
        if (!$s) {
            self::ir('login');
        }
        // la cuenta se revalida en cada pedido: una suspensión o cambio de rol aplica de inmediato
        $u = Accesos::vigente((int)$s['id']);
        if (!$u) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('error', 'Tu cuenta ya no está activa. Consulta con Gerencia.');
            self::ir('login');
        }
        $_SESSION['usuario'] = $u;
        if ($roles && !in_array($u['rol'], $roles, true)) {
            http_response_code(403);
            self::flash('error', 'Tu rol (' . self::ROLES[$u['rol']] . ') no puede realizar esta acción.');
            self::ir('tablero');
        }
        return $u;
    }

    public static function puede(string ...$roles): bool
    {
        $u = self::usuario();
        return $u && in_array($u['rol'], $roles, true);
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function campoCsrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::csrf() . '">';
    }

    public static function verificarCsrf(): void
    {
        if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
            http_response_code(419);
            exit('Sesión expirada o formulario inválido. Vuelve atrás y recarga la página.');
        }
    }

    public static function flash(string $tipo, string $msg): void
    {
        $_SESSION['flash'][] = [$tipo, $msg];
    }

    public static function mensajes(): array
    {
        $m = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $m;
    }

    public static function url(string $p, array $q = []): string
    {
        return 'index.php?' . http_build_query(['p' => $p] + $q);
    }

    public static function ir(string $p, array $q = []): never
    {
        header('Location: ' . self::url($p, $q));
        exit;
    }
}

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fd(?string $iso, string $fmt = 'd/m/Y'): string
{
    if (!$iso) {
        return '—';
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));
    return $d ? $d->format($fmt) : e($iso);
}

function fdc(?string $iso): string
{
    static $m = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    if (!$iso) {
        return '—';
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));
    return $d ? $d->format('j') . ' ' . $m[(int)$d->format('n')] : e($iso);
}

function num(mixed $v, int $dec = 0): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    $f = (float)$v;
    if ($dec === 0 && abs($f - round($f)) > 1e-9) {
        $dec = 1;
    }
    return number_format($f, $dec, '.', ',');
}

function chip(string $estado): string
{
    $txt = Motor::ETIQUETAS[$estado] ?? $estado;
    return '<span class="chip chip-' . e($estado) . '"><i></i>' . e($txt) . '</span>';
}
