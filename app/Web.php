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

    public static function iniciarSesion(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('alertaplan');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function login(string $email, string $clave): bool
    {
        $st = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND activo = 1');
        $st->execute([mb_strtolower(trim($email))]);
        $u = $st->fetch();
        if (!$u || !password_verify($clave, $u['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        unset($u['password_hash']);
        $_SESSION['usuario'] = $u;
        Repo::auditar((int)$u['id'], 'inicio_sesion', 'usuario', (int)$u['id'], null);
        return true;
    }

    public static function requiere(array $roles = []): array
    {
        $u = self::usuario();
        if (!$u) {
            self::ir('login');
        }
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
