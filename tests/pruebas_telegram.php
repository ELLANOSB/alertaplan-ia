<?php
declare(strict_types=1);

/**
 * Pruebas del bot de Telegram con un cliente simulado (no envía nada a Telegram).
 * Uso: php tests/pruebas_telegram.php  → evidencias/resultados_telegram.json
 * Crea una obra "TGT" y usuarios temporales, y los elimina al terminar.
 */
if (PHP_SAPI !== 'cli') {
    exit("Ejecutar desde la terminal.\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Importador;
use App\Repo;
use App\Telegram\Bot;
use App\Telegram\Cliente;

final class TelegramFalso extends Cliente
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
        $this->salida[] = ['tipo' => 'texto', 'chat' => $chat, 'texto' => $html, 'teclado' => $teclado];
    }

    public function enviarDocumento(int|string $chat, string $ruta, string $nombre, string $caption = ''): void
    {
        $this->salida[] = ['tipo' => 'doc', 'chat' => $chat, 'nombre' => $nombre, 'bytes' => filesize($ruta), 'html' => (string)file_get_contents($ruta), 'texto' => $caption];
    }

    public function responderBoton(string $id, string $texto = ''): void
    {
    }

    public function descargar(string $fileId, string $destino): string
    {
        copy(RAIZ . '/datos/' . $fileId, $destino);
        return $destino;
    }

    public function ultimo(): string
    {
        $u = end($this->salida);
        return $u ? $u['texto'] : '';
    }
}

$casos = [];
function ok(string $id, string $nombre, bool $cond, string $obtenido): void
{
    global $casos;
    $casos[] = ['id' => $id, 'nombre' => $nombre, 'ok' => $cond, 'obtenido' => mb_substr(strip_tags($obtenido), 0, 160)];
    echo ($cond ? '  ✓ ' : '  ✗ ') . "{$id} {$nombre}" . ($cond ? '' : ' → ' . mb_substr(strip_tags($obtenido), 0, 200)) . "\n";
}

$pdo = db();
$tg = new TelegramFalso();
$bot = new Bot($tg, RAIZ . '/storage/telegram_pruebas');
$msg = function (int $chat, string $texto, array $extra = []) use ($bot) {
    $bot->procesar(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'Prueba'], 'text' => $texto] + $extra]);
};
$doc = function (int $chat, string $archivo, string $caption) use ($bot) {
    $bot->procesar(['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat],
        'caption' => $caption, 'document' => ['file_id' => $archivo, 'file_name' => $archivo, 'file_size' => filesize(RAIZ . '/datos/' . $archivo)]]]);
};
$boton = fn(int $chat, string $data) => $bot->procesar(['update_id' => 3, 'callback_query' => ['id' => 'q1', 'data' => $data, 'message' => ['chat' => ['id' => $chat]]]]);

[$cP, $cR, $cG, $cX] = [990000001, 990000002, 990000003, 990000004];
$pdo->exec("DELETE FROM obras WHERE codigo = 'TGT'");
$uids = [];
try {
    // preparación: obra temporal con el plan de Claveles S46 y usuarios temporales
    $ins = $pdo->prepare('INSERT INTO usuarios (nombre, cargo, email, rol, password_hash) VALUES (?,?,?,?,?)');
    foreach (['planeamiento', 'produccion', 'gerencia'] as $r) {
        $ins->execute(["Prueba {$r}", 'Prueba', "tg-{$r}-" . bin2hex(random_bytes(3)) . '@prueba.local', $r, 'x']);
        $uids[$r] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("INSERT INTO obras (codigo, nombre, dias_laborables, feriados, umbral_vigilar) VALUES ('TGT', 'Obra de prueba del bot', '1,2,3,4,5,6', '2026-12-08', 2)");
    $oid = (int)$pdo->lastInsertId();
    $f = Importador::leer(RAIZ . '/datos/Claveles_S46_plan.xlsx', 'x.xlsx');
    $cid = Repo::importar($oid, 46, '2026-11-07', '2026-11-14', Importador::aplicarMapeo($f, Importador::proponerMapeo($f[0])), 'Claveles_S46_plan.xlsx', $uids['planeamiento']);

    echo "Bot de Telegram (cliente simulado)\n";
    $msg($cX, '/estado');
    ok('B1', 'Chat no vinculado no recibe datos', str_contains($tg->ultimo(), 'no está vinculado'), $tg->ultimo());
    $msg($cX, '/vincular ZZZZZZ');
    ok('B2', 'Código inválido rechazado', str_contains($tg->ultimo(), 'inválido'), $tg->ultimo());
    $msg($cP, '/vincular ' . Bot::generarCodigo($uids['planeamiento']));
    $msg($cR, '/start ' . Bot::generarCodigo($uids['produccion']));
    $msg($cG, '/vincular ' . Bot::generarCodigo($uids['gerencia']));
    $n = (int)$pdo->query("SELECT COUNT(*) FROM telegram_vinculos WHERE chat_id IN ({$cP},{$cR},{$cG})")->fetchColumn();
    ok('B3', 'Vinculación por código de un solo uso (también vía /start CODIGO)', $n === 3, "{$n} chats vinculados");
    $c = Bot::generarCodigo($uids['produccion']);
    $msg($cX, "/vincular {$c}");
    $msg($cX, "/vincular {$c}");
    ok('B4', 'El código no se puede reutilizar', str_contains($tg->ultimo(), 'inválido'), $tg->ultimo());
    $pdo->exec("DELETE FROM telegram_vinculos WHERE chat_id = {$cX}");

    $msg($cP, '/obra TGT');
    $msg($cP, '/estado');
    $k = Repo::kpis(Repo::actividades($cid));
    ok('B5', '/estado devuelve PPC y conteo por estado del corte vigente', str_contains($tg->ultimo(), 'PPC') && str_contains($tg->ultimo(), 'Atraso probable: ' . $k['estados']['rojo']), $tg->ultimo());
    $msg($cP, '/atrasos');
    ok('B6', '/atrasos lista actividades rojas con su motivo', str_contains($tg->ultimo(), 'CL-02') && str_contains($tg->ultimo(), 'Terminaría'), $tg->ultimo());
    $msg($cP, '/actividad cl-02');
    ok('B7', '/actividad muestra el cálculo explicable', str_contains($tg->ultimo(), 'Restan') && str_contains($tg->ultimo(), 'laborables'), $tg->ultimo());
    $msg($cP, '/informe');
    $d = end($tg->salida);
    ok('B8', '/informe envía el informe como archivo autocontenido', $d['tipo'] === 'doc' && str_contains($d['html'], 'Informe semanal de obra') && str_contains($d['html'], 'data:image/png;base64') && !str_contains($d['html'], 'href="assets/'), ($d['nombre'] ?? '') . ' · ' . ($d['bytes'] ?? 0) . ' B');

    $msg($cR, '/obra TGT');
    $msg($cR, '/parte CL-02 1 10/11 Llegó parte del acero');
    $a = Repo::actividad((int)$pdo->query("SELECT id FROM actividades WHERE corte_id = {$cid} AND codigo = 'CL-02'")->fetchColumn());
    ok('B9', '/parte de Producción registra el avance y recalcula', (float)$a['ejecutado'] === 4.0 && str_contains($tg->ultimo(), 'Parte registrado'), $tg->ultimo());
    $msg($cR, '/parte CL-02 99');
    ok('B10', '/parte rechaza un acumulado mayor al metrado', str_contains($tg->ultimo(), 'superaría'), $tg->ultimo());
    $doc($cR, 'Plantilla_Casuisticas_S47.xlsx', '/cargar TGT 47 14/11/2026 21/11/2026 21/11/2026');
    ok('B11', 'Producción no puede cargar planificaciones', str_contains($tg->ultimo(), 'Solo Planeamiento'), $tg->ultimo());

    $doc($cP, 'Prueba_errores.csv', '/cargar TGT 48 21/11/2026 28/11/2026');
    ok('B12', 'Archivo con errores se rechaza antes de guardar', str_contains($tg->ultimo(), 'errores') && str_contains($tg->ultimo(), 'repetido'), $tg->ultimo());
    $doc($cP, 'Plantilla_Casuisticas_S47.xlsx', '/cargar');
    ok('B13', 'Archivo sin descripción completa pide los datos', str_contains($tg->ultimo(), 'Falta la descripción'), $tg->ultimo());
    $doc($cP, 'Plantilla_Casuisticas_S47.xlsx', '/cargar TGT 47 14/11/2026 21/11/2026 21/11/2026');
    $prev = end($tg->salida);
    ok('B14', 'Excel por Telegram: vista previa con PPC y botones, sin guardar todavía', str_contains($prev['texto'], '23 actividades') && str_contains($prev['texto'], '31.3%') && !empty($prev['teclado'])
        && !$pdo->query("SELECT COUNT(*) FROM cortes WHERE obra_id = {$oid} AND semana = 47")->fetchColumn(), $prev['texto']);
    $data = $prev['teclado'][0][0]['callback_data'];
    $boton($cG, $data);
    ok('B15', 'Otro chat no puede confirmar la carga ajena', str_contains($tg->ultimo(), 'ya no está pendiente'), $tg->ultimo());
    $boton($cP, $data);
    $n = (int)$pdo->query("SELECT COUNT(*) FROM actividades a JOIN cortes c ON c.id = a.corte_id WHERE c.obra_id = {$oid} AND c.semana = 47")->fetchColumn();
    ok('B16', 'Al confirmar, se importa con la fecha de datos y responde el estado', $n === 23 && str_contains($tg->ultimo(), 'PPC 31.3%'), "{$n} actividades · " . $tg->ultimo());
    $doc($cP, 'Plantilla_Casuisticas_S47.xlsx', '/cargar TGT 49 21/11/2026 28/11/2026');
    $data = end($tg->salida)['teclado'][0][1]['callback_data'];
    $boton($cP, $data);
    ok('B17', 'Cancelar no guarda nada', str_contains($tg->ultimo(), 'cancelada') && !$pdo->query("SELECT COUNT(*) FROM cortes WHERE obra_id = {$oid} AND semana = 49")->fetchColumn(), $tg->ultimo());

    $msg($cP, '/obras');
    $bots = end($tg->salida)['teclado'] ?? [];
    ok('B25', '/obras muestra un botón por obra', count($bots) >= 2, count($bots) . ' botones');
    $msg($cP, '/obras • CLV Edificio Multifamiliar Claveles');
    ok('B26', '/obras con texto copiado del mensaje elige la obra', str_contains($tg->ultimo(), 'Obra elegida: <b>CLV'), $tg->ultimo());
    $boton($cP, 'obra:' . $oid);
    ok('B27', 'Tocar el botón de la obra la elige', str_contains($tg->ultimo(), 'Obra elegida: <b>TGT'), $tg->ultimo());
    $msg($cG, '/obra TGT');
    $msg($cG, '/estado');
    ok('B18', 'Gerencia no ve cortes sin aprobar', str_contains($tg->ultimo(), 'no hay informes aprobados'), $tg->ultimo());
    $msg($cG, '/obra CLV');
    $msg($cG, '/estado');
    ok('B19', 'Gerencia ve el último corte aprobado', str_contains($tg->ultimo(), 'aprobado'), $tg->ultimo());
    $msg($cG, '/parte CL-01 1');
    ok('B20', 'Gerencia no registra partes', str_contains($tg->ultimo(), 'no registra'), $tg->ultimo());

    $msg($cP, '/obra TGT');
    $msg($cP, '¿qué pasa con la C08?');
    ok('B21', 'Texto libre con código de actividad → detalle', str_contains($tg->ultimo(), 'C08') && str_contains($tg->ultimo(), 'Restricción'), $tg->ultimo());
    $msg($cP, '¿qué actividades vienen atrasadas?');
    ok('B22', 'Texto libre sin API de IA → se responde con reglas', str_contains($tg->ultimo(), 'Atrasos y bloqueos') || str_contains($tg->ultimo(), '🤖'), $tg->ultimo());
    $antes = count($tg->salida);
    $bot->procesar(['update_id' => 9, 'message' => ['chat' => ['id' => -100123, 'type' => 'group'], 'text' => '/estado']]);
    ok('B23', 'Mensajes de grupos se ignoran (solo chats privados)', count($tg->salida) === $antes, 'sin respuesta');
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'telegram_vincular' AND usuario_id IN (" . implode(',', $uids) . ')')->fetchColumn();
    ok('B24', 'Vinculaciones quedan en auditoría', $aud >= 3, "{$aud} eventos");
} finally {
    if ($uids) {
        $in = implode(',', $uids);
        $pdo->exec("DELETE FROM auditoria WHERE usuario_id IN ({$in})");
        $pdo->exec("DELETE FROM telegram_cargas WHERE usuario_id IN ({$in})");
        $pdo->exec("DELETE FROM obras WHERE codigo = 'TGT'");
        $pdo->exec("UPDATE telegram_vinculos SET obra_id = NULL WHERE obra_id NOT IN (SELECT id FROM obras)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$in})");
    }
    foreach (glob(RAIZ . '/storage/telegram_pruebas/*') ?: [] as $x) {
        @unlink($x);
    }
}
$ap = count(array_filter($casos, fn($c) => $c['ok']));
@mkdir(RAIZ . '/evidencias');
file_put_contents(RAIZ . '/evidencias/resultados_telegram.json', json_encode(['generado' => date('Y-m-d H:i'), 'aprobados' => $ap, 'total' => count($casos), 'casos' => $casos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nResultado: {$ap}/" . count($casos) . " → evidencias/resultados_telegram.json\n";
exit($ap === count($casos) ? 0 : 1);
