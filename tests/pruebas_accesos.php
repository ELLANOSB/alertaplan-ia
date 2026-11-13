<?php
declare(strict_types=1);

/**
 * Pruebas del control de acceso: registro con código de equipo, aprobación de Gerencia,
 * política de contraseñas, bloqueo por intentos, suspensión (web y Telegram) y rotación de la clave publicada.
 * Uso: php tests/pruebas_accesos.php  → evidencias/resultados_accesos.json
 * Crea cuentas temporales (@accesos.test) y las elimina al terminar.
 */
if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Accesos;
use App\Telegram\Bot;
use App\Telegram\Cliente;

final class TelegramMudo extends Cliente
{
    public array $salida = [];

    public function __construct()
    {
    }

    public function llamar(string $metodo, array $params = [], int $timeout = 0): mixed
    {
        return true;
    }

    public function enviar(int|string $chat, string $html, ?array $teclado = null): void
    {
        $this->salida[] = $html;
    }

    public function ultimo(): string
    {
        return (string)end($this->salida);
    }
}

$casos = [];
function ok(string $id, string $nombre, bool $cond, string $obtenido = ''): void
{
    global $casos;
    $casos[] = ['id' => $id, 'nombre' => $nombre, 'ok' => $cond, 'obtenido' => mb_substr(strip_tags($obtenido), 0, 160)];
    echo ($cond ? '  ✓ ' : '  ✗ ') . "{$id} {$nombre}" . ($cond ? '' : ' → ' . mb_substr(strip_tags($obtenido), 0, 200)) . "\n";
}
function falla(callable $f): string
{
    try {
        $f();
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

$pdo = db();
$uids = [];
$ips = ['203.0.113.10', '203.0.113.11', '203.0.113.12', '203.0.113.13'];
[$ipA, $ipB, $ipC, $ipD] = $ips;
$CLAVE = 'Obra-Segura-2026';
$nuevo = function (string $email, string $rol, string $clave = 'Obra-Segura-2026') use ($pdo, &$uids): int {
    $pdo->prepare('INSERT INTO usuarios (nombre, cargo, email, rol, password_hash) VALUES (?,?,?,?,?)')
        ->execute(['Prueba ' . $rol, 'Cargo de prueba', $email, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
    return $uids[] = (int)$pdo->lastInsertId();
};
$fila = function (string $email) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
};
$solicitud = fn(string $codigo, string $email, array $extra = []) => $extra + [
    'codigo' => $codigo, 'nombre' => 'Lucía Torres', 'email' => $email, 'cargo' => 'Asistente de producción',
    'rol' => 'produccion', 'motivo' => 'Registrar partes diarios', 'clave' => $CLAVE, 'clave2' => $CLAVE];

echo "Control de acceso\n";
try {
    $gid = $nuevo('gerente@accesos.test', 'gerencia');
    $pid = $nuevo('planeamiento@accesos.test', 'planeamiento');

    ok('A01', 'Política: rechaza contraseñas de menos de 10 caracteres', (bool)Accesos::errorClave('Abc12345'));
    ok('A02', 'Política: exige letras y números', (bool)Accesos::errorClave('SoloLetrasLargas') && (bool)Accesos::errorClave('12345678901'));
    ok('A03', 'Política: rechaza la contraseña que estuvo publicada', (bool)Accesos::errorClave(Accesos::CLAVE_PUBLICADA), (string)Accesos::errorClave(Accesos::CLAVE_PUBLICADA));
    ok('A04', 'Política: la contraseña no puede contener el correo', (bool)Accesos::errorClave('lucia.torres2026', 'lucia.torres@obra.pe'));

    $e = falla(fn() => Accesos::crearInvitacion($pid));
    ok('A05', 'Solo Gerencia genera códigos de equipo', str_contains($e, 'Solo Gerencia'), $e);
    $codigo = Accesos::crearInvitacion($gid, 7, 10);
    $plano = (int)$pdo->query('SELECT COUNT(*) FROM invitaciones WHERE codigo_hash = ' . $pdo->quote($codigo) . ' OR pista = ' . $pdo->quote($codigo))->fetchColumn();
    ok('A06', 'El código se muestra una vez y en la base solo queda su hash', (bool)preg_match('/^EQ-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codigo) && $plano === 0, $codigo);

    $r = Accesos::registrar($solicitud('EQ-ZZZZ-ZZZZ', 'lucia@accesos.test'), $ipA);
    ok('A07', 'Registro con código inválido: rechazado y sin cuenta creada', !$r['ok'] && !$fila('lucia@accesos.test'), $r['error'] ?? '');

    $r = Accesos::registrar($solicitud(strtolower($codigo), 'Lucia@Accesos.test'), $ipA);
    $u = $fila('lucia@accesos.test');
    if ($u) {
        $uids[] = (int)$u['id'];
    }
    $usos = (int)$pdo->query('SELECT usos FROM invitaciones WHERE creado_por = ' . $gid . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    ok('A08', 'Registro válido: cuenta pendiente, sin acceso, y el código suma un uso', $r['ok'] && $u && $u['estado'] === 'pendiente' && (int)$u['activo'] === 0 && $usos === 1, json_encode([$u['estado'] ?? null, $usos]));

    $a = Accesos::autenticar('lucia@accesos.test', $CLAVE, $ipB);
    ok('A09', 'Cuenta pendiente no ingresa aunque la contraseña sea correcta', !$a['ok'] && str_contains($a['error'], 'pendiente'), $a['error'] ?? '');

    $r = Accesos::registrar($solicitud($codigo, 'lucia@accesos.test', ['nombre' => 'Otra persona']), $ipA);
    $n = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'lucia@accesos.test'")->fetchColumn();
    ok('A10', 'Correo ya registrado: misma respuesta (no revela cuentas) y no duplica', $r['ok'] && $n === 1, "cuentas={$n}");

    $e = falla(fn() => Accesos::aprobar((int)$u['id'], 'produccion', '', $pid));
    ok('A11', 'Solo Gerencia aprueba solicitudes', str_contains($e, 'Solo Gerencia'), $e);
    Accesos::aprobar((int)$u['id'], 'planeamiento', 'Pertenece a la oficina técnica', $gid);
    $u = $fila('lucia@accesos.test');
    ok('A12', 'Gerencia aprueba y decide el rol final (puede diferir del solicitado)', $u['estado'] === 'activo' && (int)$u['activo'] === 1 && $u['rol'] === 'planeamiento' && $u['rol_solicitado'] === 'produccion' && (int)$u['revisado_por'] === $gid, $u['rol']);

    $a = Accesos::autenticar('LUCIA@accesos.test ', $CLAVE, $ipB);
    $u = $fila('lucia@accesos.test');
    ok('A13', 'Cuenta aprobada ingresa y se registra el último ingreso', $a['ok'] && $a['usuario']['rol'] === 'planeamiento' && !isset($a['usuario']['password_hash']) && $u['ultimo_ingreso'] !== null);

    $c2 = Accesos::crearInvitacion($gid, 7, 10);
    Accesos::registrar($solicitud($c2, 'intruso@accesos.test', ['nombre' => 'Persona ajena']), $ipA);
    $intr = $fila('intruso@accesos.test');
    $uids[] = (int)$intr['id'];
    $e = falla(fn() => Accesos::rechazar((int)$intr['id'], '', $gid));
    ok('A14', 'Rechazar exige indicar el motivo', str_contains($e, 'motivo'), $e);
    Accesos::rechazar((int)$intr['id'], 'No pertenece al equipo de obra', $gid);
    $a = Accesos::autenticar('intruso@accesos.test', $CLAVE, $ipB);
    ok('A15', 'Solicitud rechazada no ingresa', !$a['ok'] && str_contains($a['error'], 'rechazada'), $a['error'] ?? '');

    $c3 = Accesos::crearInvitacion($gid, 7, 10);
    $pdo->exec('UPDATE invitaciones SET vence_en = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE creado_por = ' . $gid . ' ORDER BY id DESC LIMIT 1');
    $r = Accesos::registrar($solicitud($c3, 'tarde@accesos.test'), $ipC);
    ok('A16', 'Código vencido no sirve', !$r['ok'] && !$fila('tarde@accesos.test'), $r['error'] ?? '');

    $c4 = Accesos::crearInvitacion($gid, 7, 1);
    $r1 = Accesos::registrar($solicitud($c4, 'uno@accesos.test'), $ipC);
    $r2 = Accesos::registrar($solicitud($c4, 'dos@accesos.test'), $ipC);
    foreach (['uno@accesos.test', 'dos@accesos.test'] as $m) {
        if ($x = $fila($m)) {
            $uids[] = (int)$x['id'];
        }
    }
    ok('A17', 'Código de un solo uso no admite una segunda solicitud', $r1['ok'] && !$r2['ok'] && !$fila('dos@accesos.test'), $r2['error'] ?? '');

    $c5 = Accesos::crearInvitacion($gid, 7, 10);
    Accesos::revocarInvitacion((int)$pdo->query('SELECT MAX(id) FROM invitaciones WHERE creado_por = ' . $gid)->fetchColumn(), $gid);
    $r = Accesos::registrar($solicitud($c5, 'revocado@accesos.test'), $ipD);
    ok('A18', 'Código revocado no sirve', !$r['ok'] && !$fila('revocado@accesos.test'), $r['error'] ?? '');

    $luc = $fila('lucia@accesos.test');
    for ($i = 0; $i < Accesos::MAX_FALLOS_CUENTA; $i++) {
        Accesos::autenticar('lucia@accesos.test', 'clave-equivocada-' . $i, $ipD);
    }
    $a = Accesos::autenticar('lucia@accesos.test', $CLAVE, $ipD);
    ok('A19', Accesos::MAX_FALLOS_CUENTA . ' intentos fallidos bloquean la cuenta: luego ni la contraseña correcta entra', !$a['ok'] && str_contains($a['error'], 'Inténtalo nuevamente'), $a['error'] ?? '');
    $pdo->exec('UPDATE usuarios SET bloqueado_hasta = NULL WHERE id = ' . (int)$luc['id']);

    $x1 = Accesos::autenticar('no-existe@accesos.test', 'loquesea123', $ipB)['error'];
    $x2 = Accesos::autenticar('lucia@accesos.test', 'loquesea123', $ipB)['error'];
    ok('A20', 'Mismo mensaje para correo inexistente y contraseña errónea (no revela cuentas)', $x1 === $x2, $x1);

    for ($i = 0; $i < Accesos::MAX_CODIGOS_IP; $i++) {
        Accesos::registrar($solicitud('EQ-AAAA-AAA' . $i, "bot{$i}@accesos.test"), $ipC);
    }
    $r = Accesos::registrar($solicitud($c2, 'legitimo@accesos.test'), $ipC);
    ok('A21', 'Fuerza bruta de códigos: la conexión queda frenada tras ' . Accesos::MAX_CODIGOS_IP . ' intentos', !$r['ok'] && str_contains($r['error'], 'Demasiados') && !$fila('legitimo@accesos.test'), $r['error'] ?? '');

    // Suspensión: la sesión web y el bot dejan de reconocer a la persona
    $tg = new TelegramMudo();
    $bot = new Bot($tg, RAIZ . '/storage/telegram_pruebas');
    $chat = 990000777;
    $bot->procesar(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'Lucía'], 'text' => '/vincular ' . Bot::generarCodigo((int)$luc['id'])]]);
    $vinculado = str_contains($tg->ultimo(), 'Vinculado') || str_contains(implode("\n", $tg->salida), 'Vinculado');
    Accesos::suspender((int)$luc['id'], 'Dejó el proyecto', $gid);
    $bot->procesar(['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat], 'text' => '/estado']]);
    ok('A22', 'Al suspender: la sesión web deja de ser válida y el bot ya no responde datos', $vinculado && Accesos::vigente((int)$luc['id']) === null && str_contains($tg->ultimo(), 'no está vinculado'), $tg->ultimo());
    $bot->procesar(['update_id' => 3, 'message' => ['message_id' => 3, 'chat' => ['id' => $chat + 1, 'type' => 'private'], 'from' => ['id' => $chat + 1], 'text' => '/vincular ' . Bot::generarCodigo((int)$luc['id'])]]);
    ok('A23', 'Una cuenta suspendida no puede vincular nuevos chats', str_contains($tg->ultimo(), 'inválido'), $tg->ultimo());

    $e = falla(fn() => Accesos::suspender($gid, 'prueba', $gid));
    ok('A24', 'Gerencia no puede suspender su propia cuenta', str_contains($e, 'propia'), $e);
    Accesos::reactivar((int)$luc['id'], $gid);
    ok('A25', 'Reactivar devuelve el acceso', Accesos::autenticar('lucia@accesos.test', $CLAVE, $ipB)['ok']);

    $vieja = $nuevo('antigua@accesos.test', 'produccion', Accesos::CLAVE_PUBLICADA);
    [$rot] = Accesos::rotarClavePublicada('Nueva-Clave-2026', [$vieja]);
    $sigue = Accesos::autenticar('antigua@accesos.test', Accesos::CLAVE_PUBLICADA, $ipB)['ok'];
    ok('A26', 'La contraseña que estuvo publicada se rota automáticamente y deja de servir', $rot >= 1 && !$sigue && Accesos::autenticar('antigua@accesos.test', 'Nueva-Clave-2026', $ipB)['ok'], "rotadas={$rot}");

    $vista = (string)file_get_contents(RAIZ . '/app/Vistas/login.php');
    ok('A27', 'La pantalla de ingreso no muestra usuarios ni contraseñas', !str_contains($vista, '@claveles.demo') && !str_contains($vista, Accesos::CLAVE_PUBLICADA));

    $acc = $pdo->query("SELECT GROUP_CONCAT(DISTINCT accion) FROM auditoria WHERE (entidad = 'usuario' AND entidad_id IN (" . implode(',', $uids) . ")) OR usuario_id = {$gid}")->fetchColumn();
    $need = ['solicitud_acceso', 'acceso_aprobado', 'acceso_rechazado', 'cuenta_bloqueada', 'acceso_suspendido', 'acceso_reactivado', 'codigo_equipo_creado'];
    $faltan = array_diff($need, explode(',', (string)$acc));
    ok('A28', 'Cada solicitud, decisión y bloqueo queda en la auditoría', !$faltan, $faltan ? 'faltan: ' . implode(', ', $faltan) : (string)$acc);
} finally {
    if ($uids) {
        $in = implode(',', $uids);
        $pdo->exec("DELETE FROM telegram_vinculos WHERE usuario_id IN ({$in})");
        $pdo->exec("DELETE FROM telegram_codigos WHERE usuario_id IN ({$in})");
        $inv = $pdo->query("SELECT GROUP_CONCAT(id) FROM invitaciones WHERE creado_por IN ({$in})")->fetchColumn();
        $pdo->exec("DELETE FROM auditoria WHERE usuario_id IN ({$in}) OR (entidad = 'usuario' AND entidad_id IN ({$in}))" . ($inv ? " OR (entidad = 'invitacion' AND entidad_id IN ({$inv}))" : ''));
        $pdo->exec("DELETE FROM invitaciones WHERE creado_por IN ({$in})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$in})");
    }
    $pdo->exec('DELETE FROM intentos_acceso WHERE clave IN (' . implode(',', array_map(fn($i) => $pdo->quote(hash('sha256', $i)), $ips)) . ')');
}
$ap = count(array_filter($casos, fn($c) => $c['ok']));
@mkdir(RAIZ . '/evidencias');
file_put_contents(RAIZ . '/evidencias/resultados_accesos.json', json_encode(['generado' => date('Y-m-d H:i'), 'aprobados' => $ap, 'total' => count($casos), 'casos' => $casos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nResultado: {$ap}/" . count($casos) . " → evidencias/resultados_accesos.json\n";
exit($ap === count($casos) ? 0 : 1);
