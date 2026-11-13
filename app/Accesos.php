<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Control de acceso al sistema.
 *  - Autenticación con bloqueo temporal por intentos fallidos (por cuenta y por conexión).
 *  - Registro de nuevos integrantes con código de equipo + aprobación obligatoria de Gerencia.
 *  - Gestión de cuentas (aprobar, rechazar, suspender, reactivar) con auditoría.
 * La lógica vive aquí (y no en el controlador) para poder probarla sin HTTP: tests/pruebas_accesos.php.
 */
final class Accesos
{
    public const MAX_FALLOS_CUENTA = 5;     // intentos seguidos antes de bloquear la cuenta
    public const MIN_BLOQUEO = 15;          // minutos de bloqueo
    public const MAX_FALLOS_IP = 20;        // intentos fallidos por conexión en la ventana
    public const MAX_CODIGOS_IP = 5;        // códigos de equipo inválidos por conexión en una hora
    public const DIAS_INVITACION = 7;
    public const USOS_INVITACION = 10;
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sin 0/O, 1/I/L para dictarlo sin errores
    private const CLAVES_COMUNES = ['aecode2026!', 'password123', 'contraseña1', 'contrasena1', '1234567890', 'qwerty12345', 'admin12345', 'claveles2026'];
    /** Contraseña que estuvo publicada en versiones anteriores del proyecto: nunca debe seguir activa. */
    public const CLAVE_PUBLICADA = 'Aecode2026!';

    // ───────────────────────── autenticación ─────────────────────────

    /** @return array{ok:bool, usuario?:array, error?:string} */
    public static function autenticar(string $email, string $clave, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $generico = 'Correo o contraseña incorrectos.';
        if (self::limite('login', $ip, self::MAX_FALLOS_IP, self::MIN_BLOQUEO)) {
            return ['ok' => false, 'error' => 'Demasiados intentos fallidos desde esta conexión. Espera ' . self::MIN_BLOQUEO . ' minutos.'];
        }
        $st = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u) {
            password_verify($clave, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // tiempo constante
            self::anotar('login', $ip);
            return ['ok' => false, 'error' => $generico];
        }
        if ($u['bloqueado_hasta'] && strtotime((string)$u['bloqueado_hasta']) > time()) {
            self::anotar('login', $ip);
            $min = max(1, (int)ceil((strtotime((string)$u['bloqueado_hasta']) - time()) / 60));
            return ['ok' => false, 'error' => "Demasiados intentos fallidos. Inténtalo nuevamente en {$min} minuto(s)."];
        }
        if (!password_verify($clave, $u['password_hash'])) {
            self::anotar('login', $ip);
            $n = (int)$u['intentos_fallidos'] + 1;
            if ($n >= self::MAX_FALLOS_CUENTA) {
                db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ' . self::MIN_BLOQUEO . ' MINUTE) WHERE id = ?')->execute([$u['id']]);
                Repo::auditar(null, 'cuenta_bloqueada', 'usuario', (int)$u['id'], ['intentos' => $n]);
            } else {
                db()->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?')->execute([$n, $u['id']]);
            }
            return ['ok' => false, 'error' => $generico];
        }
        // contraseña correcta: el estado de la cuenta decide
        $msg = match ($u['estado']) {
            'pendiente' => 'Tu solicitud de acceso está pendiente de aprobación por Gerencia.',
            'rechazado' => 'Tu solicitud de acceso fue rechazada. Consulta con Gerencia.',
            'suspendido' => 'Tu cuenta está suspendida. Consulta con Gerencia.',
            default => (int)$u['activo'] === 1 ? null : 'Tu cuenta no está activa. Consulta con Gerencia.',
        };
        if ($msg) {
            return ['ok' => false, 'error' => $msg];
        }
        $nuevoHash = password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT) ? password_hash($clave, PASSWORD_DEFAULT) : $u['password_hash'];
        db()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_ingreso = NOW(), password_hash = ? WHERE id = ?')->execute([$nuevoHash, $u['id']]);
        Repo::auditar((int)$u['id'], 'inicio_sesion', 'usuario', (int)$u['id'], null);
        return ['ok' => true, 'usuario' => self::publico($u)];
    }

    /** Datos del usuario vigente (para revalidar la sesión en cada pedido). */
    public static function vigente(int $id): ?array
    {
        $st = db()->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1 AND estado = 'activo'");
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ? self::publico($u) : null;
    }

    private static function publico(array $u): array
    {
        return array_intersect_key($u, array_flip(['id', 'nombre', 'cargo', 'email', 'rol']));
    }

    // ───────────────────────── límites por conexión ─────────────────────────

    private static function anotar(string $tipo, string $ip): void
    {
        db()->prepare('INSERT INTO intentos_acceso (tipo, clave) VALUES (?, ?)')->execute([$tipo, hash('sha256', $ip)]);
        if (random_int(1, 50) === 1) { // limpieza ocasional
            db()->exec('DELETE FROM intentos_acceso WHERE creado_en < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    }

    private static function limite(string $tipo, string $ip, int $max, int $minutos): bool
    {
        $st = db()->prepare('SELECT COUNT(*) FROM intentos_acceso WHERE tipo = ? AND clave = ? AND creado_en > DATE_SUB(NOW(), INTERVAL ' . $minutos . ' MINUTE)');
        $st->execute([$tipo, hash('sha256', $ip)]);
        return (int)$st->fetchColumn() >= $max;
    }

    // ───────────────────────── contraseñas ─────────────────────────

    public static function errorClave(string $clave, string $email = '', string $nombre = ''): ?string
    {
        if (mb_strlen($clave) < 10) {
            return 'La contraseña debe tener al menos 10 caracteres.';
        }
        if (!preg_match('/\p{L}/u', $clave) || !preg_match('/\d/', $clave)) {
            return 'La contraseña debe combinar letras y números.';
        }
        if (in_array(mb_strtolower($clave), self::CLAVES_COMUNES, true)) {
            return 'Esa contraseña es demasiado común o ya fue publicada. Elige otra.';
        }
        $local = mb_strtolower((string)strstr($email, '@', true));
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains(mb_strtolower($clave), $local)) {
            return 'La contraseña no debe contener tu correo.';
        }
        return null;
    }

    /** Reemplaza la contraseña publicada en versiones anteriores por una nueva (o aleatoria). Devuelve [cuentas, clave]. */
    public static function rotarClavePublicada(?string $nueva = null, ?array $soloIds = null): array
    {
        $nueva = $nueva ?: self::claveAleatoria();
        if ($e = self::errorClave($nueva)) {
            throw new RuntimeException('DEMO_CLAVE no cumple la política: ' . $e);
        }
        $n = 0;
        $sql = 'SELECT id, password_hash FROM usuarios' . ($soloIds ? ' WHERE id IN (' . implode(',', array_map('intval', $soloIds)) . ')' : '');
        foreach (db()->query($sql)->fetchAll() as $u) {
            if (password_verify(self::CLAVE_PUBLICADA, $u['password_hash'])) {
                db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
                Repo::auditar(null, 'clave_rotada', 'usuario', (int)$u['id'], 'contraseña publicada reemplazada');
                $n++;
            }
        }
        return [$n, $nueva];
    }

    public static function claveAleatoria(): string
    {
        $c = '';
        for ($i = 0; $i < 4; $i++) {
            $c .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }
        $c .= '-';
        for ($i = 0; $i < 4; $i++) {
            $c .= strtolower(self::ALFABETO[random_int(0, 22)]);
        }
        return $c . '-' . random_int(10, 99) . random_int(10, 99);
    }

    // ───────────────────────── códigos de equipo ─────────────────────────

    private static function normalizar(string $codigo): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    }

    /** Genera un código de equipo. Solo se muestra una vez: en la base queda su hash. */
    public static function crearInvitacion(int $gerenteId, int $dias = self::DIAS_INVITACION, int $usos = self::USOS_INVITACION): string
    {
        self::exigirGerencia($gerenteId);
        $dias = max(1, min(30, $dias));
        $usos = max(1, min(50, $usos));
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }
        $codigo = 'EQ-' . substr($raw, 0, 4) . '-' . substr($raw, 4);
        db()->prepare('INSERT INTO invitaciones (codigo_hash, pista, creado_por, vence_en, max_usos) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $dias . ' DAY), ?)')
            ->execute([hash('sha256', self::normalizar($codigo)), substr($raw, -4), $gerenteId, $usos]);
        Repo::auditar($gerenteId, 'codigo_equipo_creado', 'invitacion', (int)db()->lastInsertId(), ['dias' => $dias, 'usos' => $usos]);
        return $codigo;
    }

    public static function revocarInvitacion(int $id, int $gerenteId): void
    {
        self::exigirGerencia($gerenteId);
        db()->prepare('UPDATE invitaciones SET activo = 0 WHERE id = ?')->execute([$id]);
        Repo::auditar($gerenteId, 'codigo_equipo_revocado', 'invitacion', $id, null);
    }

    public static function invitaciones(): array
    {
        return db()->query('SELECT i.*, u.nombre AS creador, (i.activo = 1 AND i.vence_en > NOW() AND i.usos < i.max_usos) AS vigente
            FROM invitaciones i JOIN usuarios u ON u.id = i.creado_por ORDER BY i.id DESC LIMIT 20')->fetchAll();
    }

    // ───────────────────────── registro ─────────────────────────

    /**
     * Solicitud de acceso. Nunca da acceso inmediato: la cuenta queda 'pendiente' hasta que Gerencia la apruebe.
     * @return array{ok:bool, error?:string}
     */
    public static function registrar(array $d, string $ip): array
    {
        if (self::limite('registro', $ip, self::MAX_CODIGOS_IP, 60)) {
            return ['ok' => false, 'error' => 'Demasiados intentos con códigos inválidos desde esta conexión. Espera una hora o pide ayuda a Gerencia.'];
        }
        $nombre = trim((string)($d['nombre'] ?? ''));
        $email = mb_strtolower(trim((string)($d['email'] ?? '')));
        $cargo = trim((string)($d['cargo'] ?? ''));
        $rol = (string)($d['rol'] ?? '');
        $motivo = trim((string)($d['motivo'] ?? ''));
        $clave = (string)($d['clave'] ?? '');
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
            return ['ok' => false, 'error' => 'Indica tu nombre completo.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            return ['ok' => false, 'error' => 'Correo no válido.'];
        }
        if (mb_strlen($cargo) < 2 || mb_strlen($cargo) > 120) {
            return ['ok' => false, 'error' => 'Indica tu cargo en la obra.'];
        }
        if (!isset(Web::ROLES[$rol])) {
            return ['ok' => false, 'error' => 'Elige el rol que solicitas.'];
        }
        if (mb_strlen($motivo) > 300) {
            return ['ok' => false, 'error' => 'El motivo debe tener como máximo 300 caracteres.'];
        }
        if ($clave !== (string)($d['clave2'] ?? '')) {
            return ['ok' => false, 'error' => 'Las contraseñas no coinciden.'];
        }
        if ($e = self::errorClave($clave, $email, $nombre)) {
            return ['ok' => false, 'error' => $e];
        }
        $st = db()->prepare('SELECT * FROM invitaciones WHERE codigo_hash = ? AND activo = 1 AND vence_en > NOW() AND usos < max_usos');
        $st->execute([hash('sha256', self::normalizar((string)($d['codigo'] ?? '')))]);
        $inv = $st->fetch();
        if (!$inv) {
            self::anotar('registro', $ip);
            return ['ok' => false, 'error' => 'El código de equipo no es válido o ya venció. Pídelo a Gerencia.'];
        }
        $ex = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
        $ex->execute([$email]);
        if ($ex->fetchColumn()) {
            // misma respuesta que un registro nuevo: no se revela si el correo ya existe
            Repo::auditar(null, 'solicitud_duplicada', 'invitacion', (int)$inv['id'], null);
            return ['ok' => true];
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO usuarios (nombre, cargo, email, rol, rol_solicitado, password_hash, activo, estado, motivo_solicitud, invitacion_id)
                VALUES (?, ?, ?, ?, ?, ?, 0, 'pendiente', ?, ?)")
                ->execute([$nombre, $cargo, $email, $rol, $rol, password_hash($clave, PASSWORD_DEFAULT), $motivo ?: null, $inv['id']]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE invitaciones SET usos = usos + 1 WHERE id = ?')->execute([$inv['id']]);
            Repo::auditar($uid, 'solicitud_acceso', 'usuario', $uid, ['rol_solicitado' => $rol, 'invitacion' => (int)$inv['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }

    // ───────────────────────── gestión por Gerencia ─────────────────────────

    private static function exigirGerencia(int $id): array
    {
        $g = self::vigente($id);
        if (!$g || $g['rol'] !== 'gerencia') {
            throw new RuntimeException('Solo Gerencia puede gestionar los accesos.');
        }
        return $g;
    }

    private static function cuenta(int $id): array
    {
        $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new RuntimeException('Cuenta no encontrada.');
    }

    public static function pendientes(): array
    {
        return db()->query("SELECT u.id, u.nombre, u.cargo, u.email, u.rol_solicitado, u.motivo_solicitud, u.creado_en, i.pista
            FROM usuarios u LEFT JOIN invitaciones i ON i.id = u.invitacion_id WHERE u.estado = 'pendiente' ORDER BY u.creado_en")->fetchAll();
    }

    public static function equipo(): array
    {
        return db()->query("SELECT u.id, u.nombre, u.cargo, u.email, u.rol, u.estado, u.ultimo_ingreso, u.revisado_en, u.comentario_revision, r.nombre AS revisor,
              (SELECT COUNT(*) FROM telegram_vinculos t WHERE t.usuario_id = u.id) AS chats
            FROM usuarios u LEFT JOIN usuarios r ON r.id = u.revisado_por WHERE u.estado <> 'pendiente' ORDER BY FIELD(u.estado,'activo','suspendido','rechazado'), u.nombre")->fetchAll();
    }

    public static function aprobar(int $id, string $rol, string $comentario, int $gerenteId): void
    {
        self::exigirGerencia($gerenteId);
        if (!isset(Web::ROLES[$rol])) {
            throw new RuntimeException('Rol no válido.');
        }
        $u = self::cuenta($id);
        if ($u['estado'] !== 'pendiente') {
            throw new RuntimeException('Esa solicitud ya fue atendida.');
        }
        db()->prepare("UPDATE usuarios SET estado = 'activo', activo = 1, rol = ?, revisado_por = ?, revisado_en = NOW(), comentario_revision = ? WHERE id = ?")
            ->execute([$rol, $gerenteId, mb_substr(trim($comentario), 0, 300) ?: null, $id]);
        Repo::auditar($gerenteId, 'acceso_aprobado', 'usuario', $id, ['rol' => $rol, 'rol_solicitado' => $u['rol_solicitado']]);
    }

    public static function rechazar(int $id, string $comentario, int $gerenteId): void
    {
        self::exigirGerencia($gerenteId);
        if (mb_strlen(trim($comentario)) < 5) {
            throw new RuntimeException('Indica el motivo del rechazo.');
        }
        $u = self::cuenta($id);
        if ($u['estado'] !== 'pendiente') {
            throw new RuntimeException('Esa solicitud ya fue atendida.');
        }
        db()->prepare("UPDATE usuarios SET estado = 'rechazado', activo = 0, revisado_por = ?, revisado_en = NOW(), comentario_revision = ? WHERE id = ?")
            ->execute([$gerenteId, mb_substr(trim($comentario), 0, 300), $id]);
        Repo::auditar($gerenteId, 'acceso_rechazado', 'usuario', $id, trim($comentario));
    }

    public static function suspender(int $id, string $comentario, int $gerenteId): void
    {
        self::exigirGerencia($gerenteId);
        if ($id === $gerenteId) {
            throw new RuntimeException('No puedes suspender tu propia cuenta.');
        }
        if (mb_strlen(trim($comentario)) < 5) {
            throw new RuntimeException('Indica el motivo de la suspensión.');
        }
        $u = self::cuenta($id);
        if ($u['estado'] !== 'activo') {
            throw new RuntimeException('Solo se suspenden cuentas activas.');
        }
        db()->prepare("UPDATE usuarios SET estado = 'suspendido', activo = 0, revisado_por = ?, revisado_en = NOW(), comentario_revision = ? WHERE id = ?")
            ->execute([$gerenteId, mb_substr(trim($comentario), 0, 300), $id]);
        Repo::auditar($gerenteId, 'acceso_suspendido', 'usuario', $id, trim($comentario));
    }

    public static function reactivar(int $id, int $gerenteId): void
    {
        self::exigirGerencia($gerenteId);
        $u = self::cuenta($id);
        if ($u['estado'] !== 'suspendido') {
            throw new RuntimeException('Solo se reactivan cuentas suspendidas.');
        }
        db()->prepare("UPDATE usuarios SET estado = 'activo', activo = 1, intentos_fallidos = 0, bloqueado_hasta = NULL, revisado_por = ?, revisado_en = NOW() WHERE id = ?")
            ->execute([$gerenteId, $id]);
        Repo::auditar($gerenteId, 'acceso_reactivado', 'usuario', $id, null);
    }
}
