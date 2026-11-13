<?php
declare(strict_types=1);

namespace App\Telegram;

use App\IA\ServicioIA;
use App\Importador;
use App\Motor;
use App\Repo;
use App\Web;
use RuntimeException;
use Throwable;

use function App\{fd, fdc, num};

/**
 * Bot de Telegram de AlertaPlan IA.
 * Reglas: solo atiende chats vinculados a un usuario del sistema; respeta su rol;
 * la carga de archivos pide confirmación; la aprobación del corte solo se hace en la web.
 */
final class Bot
{
    private const EMOJI = ['verde' => '🟢', 'amarillo' => '🟡', 'rojo' => '🔴', 'naranja' => '🟠', 'gris' => '⚪', 'azul' => '🔵'];

    public function __construct(private Cliente $tg, private string $dirArchivos)
    {
        if (!is_dir($dirArchivos)) {
            mkdir($dirArchivos, 0700, true);
        }
    }

    /* ======================= entrada ======================= */

    public function procesar(array $u): void
    {
        if (isset($u['callback_query'])) {
            $this->boton($u['callback_query']);
            return;
        }
        $m = $u['message'] ?? $u['edited_message'] ?? null;
        if (!$m || ($m['chat']['type'] ?? '') !== 'private') {
            return; // solo chats privados
        }
        $chat = (int)$m['chat']['id'];
        $texto = trim((string)($m['text'] ?? $m['caption'] ?? ''));
        try {
            [$cmd, $args] = $this->comando($texto);
            if ($cmd === '/start' || $cmd === '/vincular') {
                $cmd === '/vincular' || preg_match('/^[A-Z0-9]{6}$/', strtoupper($args)) ? $this->vincular($chat, $args, $m['from'] ?? []) : $this->inicio($chat);
                return;
            }
            $usr = $this->usuario($chat);
            if (!$usr) {
                $this->tg->enviar($chat, "🔒 Este chat no está vinculado a AlertaPlan IA.\n\nEn la web: <b>menú → Bot de Telegram</b>, genera un código y envíame:\n<code>/vincular CODIGO</code>\n\n¿Aún no tienes cuenta? En la pantalla de ingreso elige <b>Solicitar acceso</b> con el código de equipo que te da Gerencia.");
                return;
            }
            if (isset($m['document'])) {
                $this->recibirArchivo($chat, $usr, $m['document'], $args, $cmd);
                return;
            }
            match ($cmd) {
                '/ayuda', '/help' => $this->ayuda($chat, $usr),
                '/obras', '/obra' => $args === '' ? $this->obras($chat, $usr) : $this->elegirObra($chat, $usr, $args),
                '/estado' => $this->estado($chat, $usr, $args),
                '/atrasos' => $this->atrasos($chat, $usr, $args),
                '/actividad' => $this->actividad($chat, $usr, $args),
                '/causas' => $this->causas($chat, $usr, $args),
                '/informe' => $this->informe($chat, $usr, $args),
                '/parte' => $this->parte($chat, $usr, $args),
                '/cargar' => $this->tg->enviar($chat, "📎 Envíame el Excel o CSV como <b>archivo</b> con este texto en la descripción:\n<code>/cargar CLV 47 14/11/2026 21/11/2026 21/11/2026</code>\n(obra, semana, corte, cierre y, opcional, fecha de datos)"),
                '/desvincular' => $this->desvincular($chat, $usr),
                default => $this->preguntaLibre($chat, $usr, $texto),
            };
        } catch (Throwable $e) {
            $this->tg->enviar($chat, '⚠️ ' . $this->h($e->getMessage()));
        }
    }

    private function comando(string $t): array
    {
        if (!str_starts_with($t, '/')) {
            return ['', $t];
        }
        $p = preg_split('/\s+/', $t, 2);
        $cmd = strtolower(explode('@', $p[0])[0]);
        return [$cmd, trim($p[1] ?? '')];
    }

    /* ======================= vinculación ======================= */

    private function usuario(int $chat): ?array
    {
        $st = db()->prepare('SELECT u.id, u.nombre, u.cargo, u.rol, v.obra_id FROM telegram_vinculos v JOIN usuarios u ON u.id = v.usuario_id WHERE v.chat_id = ? AND u.activo = 1 AND u.estado = \'activo\'');
        $st->execute([$chat]);
        return $st->fetch() ?: null;
    }

    public static function generarCodigo(int $usuarioId): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $c = '';
        for ($i = 0; $i < 6; $i++) {
            $c .= $abc[random_int(0, strlen($abc) - 1)]; // generador criptográfico
        }
        db()->prepare('DELETE FROM telegram_codigos WHERE usuario_id = ? OR expira_en < NOW()')->execute([$usuarioId]);
        db()->prepare('INSERT INTO telegram_codigos (codigo, usuario_id, expira_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$c, $usuarioId]);
        return $c;
    }

    private function vincular(int $chat, string $codigo, array $from): void
    {
        $codigo = strtoupper(trim($codigo));
        $st = db()->prepare("SELECT c.usuario_id FROM telegram_codigos c JOIN usuarios u ON u.id = c.usuario_id AND u.activo = 1 AND u.estado = 'activo' WHERE c.codigo = ? AND c.expira_en >= NOW()");
        $st->execute([$codigo]);
        $uid = $st->fetchColumn();
        if (!$uid) {
            $this->tg->enviar($chat, '❌ Código inválido o vencido (dura 10 minutos). Genera otro en la web: <b>menú → Telegram</b>.');
            return;
        }
        db()->prepare('DELETE FROM telegram_codigos WHERE codigo = ?')->execute([$codigo]);
        $nom = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) . (isset($from['username']) ? ' @' . $from['username'] : '');
        db()->prepare('REPLACE INTO telegram_vinculos (chat_id, usuario_id, nombre_telegram, obra_id) VALUES (?, ?, ?, NULL)')->execute([$chat, $uid, mb_substr($nom, 0, 120)]);
        Repo::auditar((int)$uid, 'telegram_vincular', 'usuario', (int)$uid, ['chat' => $chat, 'telegram' => $nom]);
        $u = $this->usuario($chat);
        $this->tg->enviar($chat, "✅ Vinculado como <b>{$this->h($u['nombre'])}</b> ({$this->h(Web::ROLES[$u['rol']])}).");
        $this->ayuda($chat, $u);
    }

    private function desvincular(int $chat, array $u): void
    {
        db()->prepare('DELETE FROM telegram_vinculos WHERE chat_id = ?')->execute([$chat]);
        Repo::auditar((int)$u['id'], 'telegram_desvincular', 'usuario', (int)$u['id'], ['chat' => $chat]);
        $this->tg->enviar($chat, '👋 Chat desvinculado. Ya no responderé consultas aquí.');
    }

    private function inicio(int $chat): void
    {
        $u = $this->usuario($chat);
        if ($u) {
            $this->ayuda($chat, $u);
            return;
        }
        $this->tg->enviar($chat, "👷 <b>AlertaPlan IA</b> · seguimiento de obra\n\nPara usarme, vincula este chat con tu usuario:\n1. Entra a la web y abre <b>Telegram</b> en el menú.\n2. Copia el código y envíame <code>/vincular CODIGO</code>.\n\n¿Aún no tienes cuenta? En la pantalla de ingreso elige <b>Solicitar acceso</b> con el código de equipo que te da Gerencia; podrás vincularte cuando te aprueben.");
    }

    private function ayuda(int $chat, array $u): void
    {
        $t = "👷 <b>AlertaPlan IA</b> · {$this->h($u['nombre'])} ({$this->h(Web::ROLES[$u['rol']])})\n\n"
            . "<b>Consultas</b>\n/estado [obra] – PPC, avance y riesgos\n/atrasos [obra] – actividades en rojo o bloqueadas\n/actividad C03 – cálculo y causa de una actividad\n/causas – causas propuestas por la IA y decisiones\n/informe – informe semanal (archivo)\n/obras – ver y elegir obra con botones\n";
        if (in_array($u['rol'], ['produccion', 'planeamiento'], true)) {
            $t .= "\n<b>Parte diario</b>\n<code>/parte C03 1.5 Llegó parte del acero</code>\n<code>/parte C03 1.5 20/11 nota</code> (con fecha)\n";
        }
        if ($u['rol'] === 'planeamiento') {
            $t .= "\n<b>Cargar planificación</b>\nEnvía el .xlsx o .csv como archivo con la descripción:\n<code>/cargar CLV 47 14/11/2026 21/11/2026 21/11/2026</code>\n";
        }
        $t .= "\nTambién puedes escribir preguntas como: <i>¿cómo va la torre 1?</i>\n🔒 La aprobación del corte se hace solo en la web.";
        $this->tg->enviar($chat, $t);
    }

    /* ======================= contexto de obra ======================= */

    private function obraDe(array $u, string $arg): array
    {
        $obras = Repo::obras();
        $arg = trim($arg);
        if ($arg !== '') {
            $palabras = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtoupper($arg)) ?: [];
            foreach ($obras as $o) {
                if (in_array(mb_strtoupper($o['codigo']), $palabras, true)) {
                    return $o;
                }
            }
            throw new RuntimeException("No existe la obra «{$arg}». Toca un botón en /obras.");
        }
        foreach ($obras as $o) {
            if ((int)$o['id'] === (int)$u['obra_id']) {
                return $o;
            }
        }
        return $obras[0] ?? throw new RuntimeException('No hay obras registradas.');
    }

    private function corteDe(array $u, array $obra): array
    {
        if ($u['rol'] === 'gerencia') {
            foreach (Repo::cortes((int)$obra['id']) as $c) {
                if ($c['estado'] === 'aprobado') {
                    return Repo::corte((int)$c['id']);
                }
            }
            throw new RuntimeException('Aún no hay informes aprobados para ' . $obra['nombre'] . '.');
        }
        return Repo::corteVigente((int)$obra['id']) ?? throw new RuntimeException('La obra no tiene cortes cargados.');
    }

    private function datos(array $u, string $arg): array
    {
        $obra = $this->obraDe($u, $arg);
        $corte = $this->corteDe($u, $obra);
        $acts = Repo::actividades((int)$corte['id']);
        return [$obra, $corte, $acts, Repo::kpis($acts)];
    }

    private function obras(int $chat, array $u): void
    {
        $t = "🏗 <b>Obras</b>\n";
        $botones = [];
        foreach (Repo::obras() as $o) {
            $sel = (int)$o['id'] === (int)$u['obra_id'] ? ' ✅' : '';
            $t .= "• <code>{$this->h($o['codigo'])}</code> {$this->h($o['nombre'])}{$sel}\n";
            $botones[] = [['text' => ($sel ? '✅ ' : '') . $o['codigo'] . ' · ' . mb_strimwidth($o['nombre'], 0, 30, '…'), 'callback_data' => 'obra:' . $o['id']]];
        }
        $this->tg->enviar($chat, $t . "\nToca una obra para elegirla (o escribe <code>/obra CLV</code>).", $botones);
    }

    private function elegirObra(int $chat, array $u, string $arg): void
    {
        $o = $this->obraDe($u, $arg ?: '?');
        db()->prepare('UPDATE telegram_vinculos SET obra_id = ? WHERE chat_id = ?')->execute([$o['id'], $chat]);
        $this->tg->enviar($chat, "✅ Obra elegida: <b>{$this->h($o['codigo'])} · {$this->h($o['nombre'])}</b>\nAhora /estado, /atrasos y /actividad usan esta obra.");
    }

    /* ======================= consultas ======================= */

    private function estado(int $chat, array $u, string $arg): void
    {
        [$obra, $c, $acts, $k] = $this->datos($u, $arg);
        $e = $k['estados'];
        $t = "🏗 <b>{$this->h($obra['nombre'])}</b>\nSemana {$c['semana']} · {$this->h(fd($c['fecha_corte']))} → {$this->h(fd($c['fecha_cierre']))} · "
            . ($c['estado'] === 'aprobado' ? '✅ aprobado' : '📝 abierto') . "\nDatos al " . fd(Repo::fechaDato((int)$c['id']) ?? $c['fecha_corte']) . "\n\n"
            . "📊 <b>PPC " . ($k['ppc'] === null ? '—' : num($k['ppc']) . '%') . "</b> ({$k['cumplidas']} de {$k['comprometidas']} compromisos)\n"
            . "📈 Avance " . num($k['ejecutado'], 1) . "% vs " . num($k['programado'], 1) . "% programado (" . ($k['desviacion'] >= 0 ? '+' : '') . num($k['desviacion'], 1) . " pp)\n\n"
            . "🔴 Atraso probable: {$e['rojo']}\n🟠 Bloqueadas: {$e['naranja']}\n🟡 Vigilar: {$e['amarillo']}\n🟢 En fecha: {$e['verde']}\n⚪ Revisar datos: {$e['gris']}\n🔵 Terminadas: {$e['azul']}\n";
        $top = array_filter($acts, fn($a) => in_array($a['estado'], ['rojo', 'naranja'], true));
        usort($top, fn($x, $y) => (int)$y['atraso'] <=> (int)$x['atraso']);
        if ($top) {
            $t .= "\n<b>Prioridad</b>\n";
            foreach (array_slice($top, 0, 5) as $a) {
                $t .= $this->linea($a) . "\n";
            }
            $t .= count($top) > 5 ? "… /atrasos para ver todas\n" : '';
        }
        if ($c['estado'] === 'abierto' && $u['rol'] !== 'gerencia') {
            $b = Repo::bloqueos((int)$c['id']);
            $t .= "\n" . ($b ? "⛔ Aprobación bloqueada: " . count($b) . " pendiente(s) (se resuelven en la web)." : "✅ Sin pendientes: lista para aprobar en la web.");
        }
        $this->tg->enviar($chat, $t);
    }

    private function linea(array $a): string
    {
        $x = self::EMOJI[$a['estado']] . " <code>{$this->h($a['codigo'])}</code> {$this->h($a['nombre'])}";
        if ($a['estado'] === 'rojo') {
            $x .= " → {$this->h(fdc($a['proyeccion']))} (+{$a['atraso']} d)";
        } elseif ($a['estado'] === 'naranja') {
            $x .= ' → bloqueada';
        }
        return $x;
    }

    private function atrasos(int $chat, array $u, string $arg): void
    {
        [$obra, $c, $acts] = $this->datos($u, $arg);
        $l = array_values(array_filter($acts, fn($a) => in_array($a['estado'], ['rojo', 'naranja'], true)));
        usort($l, fn($x, $y) => (int)$y['atraso'] <=> (int)$x['atraso']);
        if (!$l) {
            $this->tg->enviar($chat, "🟢 {$this->h($obra['nombre'])} · S{$c['semana']}: no hay actividades con atraso probable ni bloqueadas.");
            return;
        }
        $t = "🔴 <b>Atrasos y bloqueos</b> · {$this->h($obra['codigo'])} S{$c['semana']}\n";
        foreach ($l as $a) {
            $t .= "\n" . $this->linea($a) . "\n<i>{$this->h($a['motivo'])}</i>";
            if ($a['categoria_final'] || $a['categoria_ia']) {
                $t .= "\nCausa: " . $this->h(ServicioIA::CATEGORIAS[$a['categoria_final'] ?: $a['categoria_ia']]) . ($a['categoria_final'] ? ' ✔ revisada' : ' (propuesta IA)');
            }
            $t .= "\n";
        }
        $this->tg->enviar($chat, $t . "\nDetalle: <code>/actividad CODIGO</code>");
    }

    private function buscarActividad(array $u, string $cod): array
    {
        $cod = strtoupper(trim($cod));
        if ($cod === '') {
            throw new RuntimeException('Indica el código, por ejemplo: /actividad C03');
        }
        $hallados = [];
        foreach (Repo::obras() as $o) {
            if ($u['obra_id'] && (int)$o['id'] !== (int)$u['obra_id']) {
                continue; // con obra elegida, solo se busca en ella
            }
            try {
                $c = $this->corteDe($u, $o);
            } catch (RuntimeException) {
                continue;
            }
            foreach (Repo::actividades((int)$c['id']) as $a) {
                if (strtoupper($a['codigo']) === $cod) {
                    $hallados[] = [$o, $c, $a];
                }
            }
        }
        if (count($hallados) > 1) {
            throw new RuntimeException("{$cod} existe en varias obras (" . implode(', ', array_map(fn($h) => $h[0]['codigo'], $hallados)) . "). Elige una con /obra CODIGO.");
        }
        return $hallados[0] ?? throw new RuntimeException("No encontré la actividad {$cod} en el corte vigente" . ($u['obra_id'] ? ' de tu obra elegida (/obras para cambiar).' : '.'));
    }

    private function actividad(int $chat, array $u, string $arg): void
    {
        [$o, $c, $a] = $this->buscarActividad($u, $arg);
        $t = self::EMOJI[$a['estado']] . " <b>{$this->h($a['codigo'])} · {$this->h($a['nombre'])}</b>\n{$this->h($o['nombre'])} · S{$c['semana']} · {$this->h($a['sector'])}\n\n"
            . "Estado: <b>{$this->h(Motor::ETIQUETAS[$a['estado']])}</b>\n<i>{$this->h($a['motivo'])}</i>\n\n"
            . "Plan: {$this->h(fd($a['inicio']))} → {$this->h(fd($a['fin']))}" . ($a['fin'] !== $a['fin_base'] ? " (base {$this->h(fd($a['fin_base']))})" : '') . "\n"
            . "Metrado: " . num($a['ejecutado']) . " / " . num($a['total']) . " {$this->h($a['unidad'])}" . ($a['rendimiento'] !== null ? " · rend. " . num($a['rendimiento']) . "/día" : ' · <b>sin rendimiento</b>') . "\n";
        if ($a['duracion'] !== null) {
            $t .= "Restan " . num((float)$a['total'] - (float)$a['ejecutado']) . " → {$a['duracion']} día(s) laborables → <b>" . fd($a['proyeccion']) . "</b>\n"
                . "Atraso {$a['atraso']} d · margen {$a['holgura']} d laborables\n";
        }
        if ($a['predecesoras']) {
            $t .= "Predecesoras: <code>{$this->h($a['predecesoras'])}</code>\n";
        }
        if ($a['restriccion']) {
            $t .= "Restricción: {$this->h($a['restriccion'])}" . ($a['liberacion'] ? ' · liberación ' . fd($a['liberacion']) : ' · sin fecha de liberación') . "\n";
        }
        if ($a['causa_id']) {
            $t .= "\n🤖 IA: <b>{$this->h(ServicioIA::CATEGORIAS[$a['categoria_ia']])}</b> · " . num((float)$a['confianza_ia'] * 100) . "%"
                . ($a['cita_ia'] ? "\n«{$this->h($a['cita_ia'])}»" : '') . "\n"
                . ($a['causa_estado'] !== 'propuesta' ? "👤 Decisión: {$this->h($a['causa_estado'])} → <b>{$this->h(ServicioIA::CATEGORIAS[$a['categoria_final']])}</b> ({$this->h($a['revisor'])})\n" : "👤 Pendiente de revisión humana\n");
        }
        if ($a['nueva_fecha']) {
            $t .= "📅 Reprogramada al " . fd($a['nueva_fecha']) . ": {$this->h($a['accion'])}\n";
        }
        if ($a['observacion']) {
            $t .= "\n📝 " . $this->h(mb_strimwidth(str_replace("\n", ' · ', $a['observacion']), 0, 400, '…'));
        }
        $this->tg->enviar($chat, $t);
    }

    private function causas(int $chat, array $u, string $arg): void
    {
        [$obra, $c, $acts] = $this->datos($u, $arg);
        $inc = Repo::incumplidas($acts);
        if (!$inc) {
            $this->tg->enviar($chat, "✅ S{$c['semana']}: no hay compromisos incumplidos.");
            return;
        }
        $t = "🤖 <b>Causas de incumplimiento</b> · {$this->h($obra['codigo'])} S{$c['semana']}\n";
        foreach ($inc as $a) {
            $t .= "\n<code>{$this->h($a['codigo'])}</code> {$this->h($a['nombre'])}\n";
            if (!$a['causa_id']) {
                $t .= "   Sin propuesta todavía (se solicita en la web: Cierre y causas IA)\n";
                continue;
            }
            $t .= "   IA: {$this->h(ServicioIA::CATEGORIAS[$a['categoria_ia']])} · " . num((float)$a['confianza_ia'] * 100) . '%'
                . ($a['cita_ia'] ? " · «{$this->h(mb_strimwidth($a['cita_ia'], 0, 90, '…'))}»" : '') . "\n"
                . '   ' . ($a['causa_estado'] === 'propuesta' ? '⏳ pendiente de decisión' : "👤 {$this->h($a['causa_estado'])} → {$this->h(ServicioIA::CATEGORIAS[$a['categoria_final']])}") . "\n";
        }
        $this->tg->enviar($chat, $t);
    }

    private function informe(int $chat, array $u, string $arg): void
    {
        [$obra, $corte, $acts, $k] = $this->datos($u, $arg);
        if ($corte['estado'] === 'aprobado' && $corte['snapshot']) {
            $acts = json_decode($corte['snapshot'], true)['actividades'];
            $k = Repo::kpis($acts);
        }
        ob_start();
        require RAIZ . '/app/Vistas/reporte.php';
        $html = (string)ob_get_clean();
        $css = (string)file_get_contents(RAIZ . '/public/assets/app.css');
        $logo = 'data:image/png;base64,' . base64_encode((string)file_get_contents(RAIZ . '/public/assets/img/aecode_dark.png'));
        $html = preg_replace('#<link rel="stylesheet" href="assets/app.css[^"]*">#', '<style>' . $css . '</style>', $html);
        $html = preg_replace('#<link rel="icon"[^>]*>#', '', $html);
        $html = str_replace('src="assets/img/aecode_dark.png"', 'src="' . $logo . '"', $html);
        $html = preg_replace('#<div class="barra-acc">.*?</div>#s', '', $html, 1);
        $nombre = "Informe_{$obra['codigo']}_S{$corte['semana']}" . ($corte['estado'] === 'aprobado' ? '' : '_BORRADOR') . '.html';
        $ruta = $this->dirArchivos . '/' . $nombre;
        file_put_contents($ruta, $html);
        $this->tg->enviarDocumento($chat, $ruta, $nombre, "📄 Informe semanal S{$corte['semana']} · {$this->h($obra['nombre'])} · " . ($corte['estado'] === 'aprobado' ? 'aprobado' : '<b>borrador no aprobado</b>'));
        @unlink($ruta);
    }

    /* ======================= escrituras ======================= */

    private function parte(int $chat, array $u, string $arg): void
    {
        if (!in_array($u['rol'], ['produccion', 'planeamiento'], true)) {
            throw new RuntimeException('Tu rol no registra partes diarios.');
        }
        if (!preg_match('/^(\S+)\s+([0-9]+(?:[.,][0-9]+)?)(?:\s+(\d{1,2}\/\d{1,2}(?:\/\d{4})?))?\s*(.*)$/u', $arg, $m)) {
            throw new RuntimeException('Formato: /parte C03 1.5 [dd/mm] nota del día');
        }
        [$o, $c, $a] = $this->buscarActividad($u, $m[1]);
        $fecha = date('Y-m-d');
        if ($m[3] !== '') {
            $p = explode('/', $m[3]);
            $fecha = sprintf('%04d-%02d-%02d', $p[2] ?? substr($c['fecha_cierre'], 0, 4), $p[1], $p[0]);
        }
        $antes = $a;
        Repo::registrarAvance((int)$a['id'], $fecha, (float)str_replace(',', '.', $m[2]), trim($m[4]), (int)$u['id']);
        $d = Repo::actividad((int)$a['id']);
        $this->tg->enviar($chat, "✅ Parte registrado · <code>{$this->h($d['codigo'])}</code> {$this->h($d['nombre'])}\n{$this->h($o['nombre'])} · S{$c['semana']}\n"
            . fd($fecha) . ": +" . num((float)str_replace(',', '.', $m[2])) . " {$this->h($d['unidad'])} → " . num($d['ejecutado']) . "/" . num($d['total']) . "\n"
            . self::EMOJI[$antes['estado']] . " → " . self::EMOJI[$d['estado']] . " <b>{$this->h(Motor::ETIQUETAS[$d['estado']])}</b>"
            . ($d['proyeccion'] ? " · fecha probable " . fd($d['proyeccion']) : '') . "\n<i>{$this->h($d['motivo'])}</i>");
    }

    private function recibirArchivo(int $chat, array $u, array $doc, string $args, string $cmd): void
    {
        if ($u['rol'] !== 'planeamiento') {
            throw new RuntimeException('Solo Planeamiento puede cargar planificaciones.');
        }
        $nombre = basename((string)($doc['file_name'] ?? 'archivo'));
        if (!preg_match('/\.(xlsx|csv)$/i', $nombre)) {
            throw new RuntimeException('Envía un archivo .xlsx o .csv.');
        }
        if (($doc['file_size'] ?? 0) > Importador::MAX_BYTES) {
            throw new RuntimeException('El archivo supera 5 MB.');
        }
        if ($cmd !== '/cargar' || !preg_match('/^(\S+)\s+(\d{1,3})\s+(\S+)\s+(\S+)(?:\s+(\S+))?$/', $args, $m)) {
            throw new RuntimeException("Falta la descripción. Reenvía el archivo con:\n/cargar CLV 47 14/11/2026 21/11/2026 [fecha de datos]");
        }
        $obra = $this->obraDe($u, $m[1]);
        $f = fn($x) => Motor::iso(Motor::fecha($x) ?? throw new RuntimeException("Fecha no válida: {$x} (usa dd/mm/aaaa)"));
        $corte = $f($m[3]);
        $cierre = $f($m[4]);
        $datos = isset($m[5]) && $m[5] !== '' ? $f($m[5]) : null;
        $ruta = $this->dirArchivos . '/' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^\w.\-]/', '_', $nombre);
        $this->tg->descargar((string)$doc['file_id'], $ruta);
        // validación previa (mismas reglas que la web)
        $filas = Importador::leer($ruta, $nombre);
        $regs = Importador::aplicarMapeo($filas, Importador::proponerMapeo($filas[0]));
        if ($b = Repo::erroresBloqueantes($regs)) {
            @unlink($ruta);
            throw new RuntimeException("El archivo tiene errores y no se puede importar:\n• " . implode("\n• ", array_slice($b, 0, 8)));
        }
        $ana = Motor::desdeObra($obra)->analizar($regs, $corte, $cierre, (int)$obra['umbral_vigilar'], $datos);
        $cnt = array_count_values(array_column($ana, 'estado'));
        $p = Motor::ppc($ana);
        db()->prepare("UPDATE telegram_cargas SET estado = 'cancelada' WHERE chat_id = ? AND estado = 'pendiente'")->execute([$chat]);
        db()->prepare('INSERT INTO telegram_cargas (chat_id, usuario_id, obra_id, semana, fecha_corte, fecha_cierre, fecha_datos, archivo, ruta) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$chat, $u['id'], $obra['id'], (int)$m[2], $corte, $cierre, $datos, $nombre, $ruta]);
        $id = (int)db()->lastInsertId();
        $t = "📋 <b>Vista previa</b> · {$this->h($nombre)}\n{$this->h($obra['nombre'])} · semana {$m[2]} · corte " . fd($corte) . " · cierre " . fd($cierre) . ($datos ? ' · datos al ' . fd($datos) : '') . "\n\n"
            . count($regs) . " actividades válidas\n";
        foreach (['rojo', 'naranja', 'amarillo', 'verde', 'gris', 'azul'] as $s) {
            if (!empty($cnt[$s])) {
                $t .= self::EMOJI[$s] . ' ' . Motor::ETIQUETAS[$s] . ": {$cnt[$s]}\n";
            }
        }
        $t .= "\nPPC al cierre: " . ($p['ppc'] === null ? '—' : num($p['ppc']) . '%') . " ({$p['cumplidas']}/{$p['comprometidas']})\n";
        $sem = (int)$m[2];
        $st = db()->prepare('SELECT estado FROM cortes WHERE obra_id = ? AND semana = ?');
        $st->execute([$obra['id'], $sem]);
        $prev = $st->fetchColumn();
        if ($prev === 'aprobado') {
            @unlink($ruta);
            throw new RuntimeException("La semana {$sem} ya está aprobada y no se puede reemplazar.");
        }
        $t .= $prev ? "\n⚠️ La semana {$sem} ya existe y está abierta: se <b>reemplazará</b>." : '';
        $this->tg->enviar($chat, $t . "\n¿Importar?", [[['text' => '✅ Importar', 'callback_data' => "imp:{$id}"], ['text' => '✖️ Cancelar', 'callback_data' => "can:{$id}"]]]);
    }

    private function boton(array $q): void
    {
        $chat = (int)($q['message']['chat']['id'] ?? 0);
        $this->tg->responderBoton((string)$q['id']);
        try {
            $u = $this->usuario($chat) ?? throw new RuntimeException('Chat no vinculado.');
            if (preg_match('/^obra:(\d+)$/', (string)($q['data'] ?? ''), $m)) {
                $o = Repo::obra((int)$m[1]);
                $this->elegirObra($chat, $u, $o['codigo']);
                return;
            }
            if (!preg_match('/^(imp|can):(\d+)$/', (string)($q['data'] ?? ''), $m)) {
                return;
            }
            $st = db()->prepare("SELECT * FROM telegram_cargas WHERE id = ? AND chat_id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $st->execute([$m[2], $chat, $u['id']]);
            $c = $st->fetch() ?: throw new RuntimeException('Esa carga ya no está pendiente.');
            if ($m[1] === 'can') {
                db()->prepare("UPDATE telegram_cargas SET estado = 'cancelada' WHERE id = ?")->execute([$c['id']]);
                @unlink($c['ruta']);
                $this->tg->enviar($chat, '✖️ Carga cancelada. No se guardó nada.');
                return;
            }
            if ($u['rol'] !== 'planeamiento') {
                throw new RuntimeException('Solo Planeamiento puede importar.');
            }
            $filas = Importador::leer($c['ruta'], $c['archivo']);
            $regs = Importador::aplicarMapeo($filas, Importador::proponerMapeo($filas[0]));
            $cid = Repo::importar((int)$c['obra_id'], (int)$c['semana'], $c['fecha_corte'], $c['fecha_cierre'], $regs, $c['archivo'] . ' (Telegram)', (int)$u['id'], $c['fecha_datos']);
            db()->prepare("UPDATE telegram_cargas SET estado = 'importada' WHERE id = ?")->execute([$c['id']]);
            @unlink($c['ruta']);
            db()->prepare('UPDATE telegram_vinculos SET obra_id = ? WHERE chat_id = ?')->execute([$c['obra_id'], $chat]);
            $this->tg->enviar($chat, "✅ Corte importado: " . count($regs) . " actividades analizadas.");
            $this->estado($chat, $this->usuario($chat), '');
        } catch (Throwable $e) {
            $this->tg->enviar($chat, '⚠️ ' . $this->h($e->getMessage()));
        }
    }

    /* ======================= preguntas en lenguaje natural ======================= */

    private function preguntaLibre(int $chat, array $u, string $texto): void
    {
        if ($texto === '') {
            $this->tg->enviar($chat, 'Escribe /ayuda para ver lo que puedo hacer.');
            return;
        }
        if (preg_match('/\b([A-Z]{1,4}-?\d{1,3})\b/i', $texto, $m) && preg_match('/\d/', $m[1])) {
            try {
                $this->buscarActividad($u, $m[1]);
                $this->actividad($chat, $u, $m[1]);
                return;
            } catch (RuntimeException) {
            }
        }
        $ia = ia();
        if ($ia->modoEfectivo() === 'claude') {
            try {
                $this->tg->enviar($chat, '🤖 ' . $this->h($this->responderConIA($u, $texto)) . "\n\n<i>Respuesta generada con IA a partir de los datos del sistema. Verifica con /estado.</i>");
                return;
            } catch (Throwable) {
                // si falla la API se responde con reglas
            }
        }
        $n = mb_strtolower($texto);
        if (preg_match('/atras|retras|tarde|riesgo|bloque/u', $n)) {
            $this->atrasos($chat, $u, '');
        } elseif (preg_match('/causa|por qu|motivo/u', $n)) {
            $this->causas($chat, $u, '');
        } elseif (preg_match('/informe|reporte/u', $n)) {
            $this->informe($chat, $u, '');
        } else {
            $this->estado($chat, $u, '');
        }
    }

    private function responderConIA(array $u, string $pregunta): string
    {
        [$obra, $c, $acts, $k] = $this->datos($u, '');
        $ctx = ['obra' => $obra['nombre'], 'semana' => $c['semana'], 'estado_corte' => $c['estado'], 'corte' => $c['fecha_corte'], 'cierre' => $c['fecha_cierre'],
            'kpis' => $k, 'actividades' => array_map(fn($a) => ['id' => $a['codigo'], 'nombre' => $a['nombre'], 'sector' => $a['sector'], 'estado' => Motor::ETIQUETAS[$a['estado']],
                'fin_plan' => $a['fin'], 'fecha_probable' => $a['proyeccion'], 'atraso_dias' => $a['atraso'], 'avance_pct' => $a['ejecutado_pct'], 'motivo' => $a['motivo'],
                'causa' => $a['categoria_final'] ? ServicioIA::CATEGORIAS[$a['categoria_final']] : null], $acts)];
        $sis = 'Eres el asistente de control de obra de AlertaPlan IA. Respondes en español, en máximo 8 líneas, SOLO con los datos JSON entregados. '
            . 'Cita IDs de actividades y cifras exactas. Si el dato no está, dilo. No inventes ni recomiendes cambios de fechas; las decisiones las toma Planeamiento.';
        $llm = new \App\IA\ClienteClaude((string)env('ANTHROPIC_API_KEY', ''), (string)env('ANTHROPIC_MODEL', 'claude-haiku-4-5'), 25);
        return trim($llm->completar($sis, "Datos:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n\nPregunta: " . $pregunta, 600));
    }

    private function h(mixed $s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
