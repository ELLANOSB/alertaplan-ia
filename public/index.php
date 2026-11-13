<?php
declare(strict_types=1);

/**
 * C1 · Interfaz web — controlador frontal.
 * Rutas: index.php?p=<pagina>
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Importador;
use App\Motor;
use App\Repo;
use App\Web;

Web::iniciarSesion();
$p = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'tablero')) ?: 'tablero';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    db();
} catch (Throwable $ex) {
    http_response_code(500);
    $error = $ex->getMessage();
    require __DIR__ . '/../app/Vistas/sin_bd.php';
    exit;
}

/** Obra y corte activos (se recuerdan en sesión). */
function contexto(): array
{
    $obras = Repo::obras();
    if (!$obras) {
        return [[], null, null, []];
    }
    $oid = (int)($_GET['obra'] ?? $_SESSION['obra'] ?? $obras[0]['id']);
    $ids = array_column($obras, 'id');
    if (!in_array($oid, array_map('intval', $ids), true)) {
        $oid = (int)$obras[0]['id'];
    }
    $_SESSION['obra'] = $oid;
    $obra = Repo::obra($oid);
    $cortes = Repo::cortes($oid);
    $gerencia = (Web::usuario()['rol'] ?? '') === 'gerencia';
    if ($gerencia) {
        // Gerencia solo ve semanas aprobadas por Planeamiento
        $cortes = array_values(array_filter($cortes, fn($c) => $c['estado'] === 'aprobado'));
    }
    $cid = (int)($_GET['corte'] ?? 0);
    $corte = null;
    foreach ($cortes as $c) {
        if ((int)$c['id'] === $cid) {
            $corte = Repo::corte($cid);
        }
    }
    if (!$corte) {
        $corte = $gerencia ? ($cortes ? Repo::corte((int)$cortes[0]['id']) : null) : Repo::corteVigente($oid);
    }
    return [$obras, $obra, $corte, $cortes];
}

function vista(string $nombre, array $datos = []): void
{
    extract($datos);
    $usuario = Web::usuario();
    $pagina = $nombre;
    ob_start();
    require __DIR__ . '/../app/Vistas/' . $nombre . '.php';
    $contenido = ob_get_clean();
    require __DIR__ . '/../app/Vistas/layout.php';
}

try {
    switch ($p) {
        case 'login':
            if (Web::usuario() && !$post) {
                Web::ir('tablero');
            }
            if ($post) {
                Web::verificarCsrf();
                $err = Web::login((string)($_POST['email'] ?? ''), (string)($_POST['clave'] ?? ''));
                if ($err === null) {
                    Web::ir(Web::usuario()['rol'] === 'gerencia' ? 'gerencia' : 'tablero');
                }
                Web::flash('error', $err);
                $_SESSION['login_email'] = mb_substr((string)($_POST['email'] ?? ''), 0, 160);
                Web::ir('login');
            }
            $mensajes = Web::mensajes();
            $modo = 'login';
            $previo = ['email' => $_SESSION['login_email'] ?? ''];
            unset($_SESSION['login_email']);
            require __DIR__ . '/../app/Vistas/login.php';
            exit;

        case 'registro':
            if ($post) {
                Web::verificarCsrf();
                $r = App\Accesos::registrar($_POST, Web::ip());
                if ($r['ok']) {
                    unset($_SESSION['registro']);
                    Web::ir('registro', ['enviado' => 1]);
                }
                Web::flash('error', $r['error']);
                $_SESSION['registro'] = array_intersect_key($_POST, array_flip(['nombre', 'email', 'cargo', 'rol', 'motivo']));
                Web::ir('registro');
            }
            $mensajes = Web::mensajes();
            $modo = isset($_GET['enviado']) ? 'enviado' : 'registro';
            $previo = $_SESSION['registro'] ?? [];
            unset($_SESSION['registro']);
            require __DIR__ . '/../app/Vistas/login.php';
            exit;

        case 'accesos':
            $u = Web::requiere(['gerencia']);
            $codigoNuevo = null;
            if ($post) {
                Web::verificarCsrf();
                $id = (int)($_POST['id'] ?? 0);
                $gid = (int)$u['id'];
                switch ((string)($_POST['accion'] ?? '')) {
                    case 'codigo':
                        $codigoNuevo = App\Accesos::crearInvitacion($gid, (int)($_POST['dias'] ?? 7), (int)($_POST['usos'] ?? 10));
                        break;
                    case 'revocar':
                        App\Accesos::revocarInvitacion($id, $gid);
                        Web::flash('ok', 'Código de equipo revocado.');
                        Web::ir('accesos');
                    case 'aprobar':
                        App\Accesos::aprobar($id, (string)$_POST['rol'], (string)($_POST['comentario'] ?? ''), $gid);
                        Web::flash('ok', 'Acceso aprobado. La persona ya puede ingresar.');
                        Web::ir('accesos');
                    case 'rechazar':
                        App\Accesos::rechazar($id, (string)($_POST['comentario'] ?? ''), $gid);
                        Web::flash('ok', 'Solicitud rechazada.');
                        Web::ir('accesos');
                    case 'suspender':
                        App\Accesos::suspender($id, (string)($_POST['comentario'] ?? ''), $gid);
                        Web::flash('ok', 'Cuenta suspendida: pierde el acceso web y el bot de Telegram de inmediato.');
                        Web::ir('accesos');
                    case 'reactivar':
                        App\Accesos::reactivar($id, $gid);
                        Web::flash('ok', 'Cuenta reactivada.');
                        Web::ir('accesos');
                    default:
                        throw new RuntimeException('Acción no reconocida.');
                }
            }
            vista('accesos', ['codigoNuevo' => $codigoNuevo, 'pendientes' => App\Accesos::pendientes(), 'equipo' => App\Accesos::equipo(), 'invitaciones' => App\Accesos::invitaciones()]);
            break;

        case 'salir':
            if (Web::usuario()) {
                Repo::auditar((int)Web::usuario()['id'], 'cierre_sesion', 'usuario', (int)Web::usuario()['id'], null);
            }
            session_destroy();
            Web::ir('login');

        case 'tablero':
            Web::requiere();
            [$obras, $obra, $corte, $cortes] = contexto();
            $acts = $corte ? Repo::actividades((int)$corte['id']) : [];
            vista('tablero', compact('obras', 'obra', 'corte', 'cortes', 'acts') + ['k' => Repo::kpis($acts)]);
            break;

        case 'actividad':
            $u = Web::requiere();
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($post) {
                Web::verificarCsrf();
                $acc = (string)($_POST['accion'] ?? '');
                $uid = (int)$u['id'];
                match ($acc) {
                    'parte' => Web::puede('produccion', 'planeamiento') ? Repo::registrarAvance($id, (string)$_POST['fecha'], (float)str_replace(',', '.', (string)$_POST['cantidad']), (string)$_POST['nota'], $uid) : throw new RuntimeException('Sin permiso.'),
                    'editar' => Web::puede('planeamiento', 'produccion') ? Repo::editarActividad($id, array_intersect_key($_POST, array_flip(['rendimiento', 'restriccion', 'liberacion', 'fin_real', 'observacion', 'comprometida'])), $uid) : throw new RuntimeException('Sin permiso.'),
                    'ia' => Web::puede('planeamiento', 'produccion') ? Repo::proponerCausa($id, $uid) : throw new RuntimeException('Sin permiso.'),
                    'revisar' => Web::puede('planeamiento') ? Repo::revisarCausa($id, (string)$_POST['decision'], (string)($_POST['categoria'] ?? ''), (string)($_POST['comentario'] ?? ''), $uid) : throw new RuntimeException('Solo Planeamiento revisa causas.'),
                    'reprogramar' => Web::puede('planeamiento') ? Repo::reprogramar($id, (string)$_POST['nueva_fecha'], (string)$_POST['accion_txt'], $uid) : throw new RuntimeException('Solo Planeamiento reprograma.'),
                    default => throw new RuntimeException('Acción no reconocida.'),
                };
                Web::flash('ok', match ($acc) {
                    'parte' => 'Parte diario registrado y proyección recalculada.',
                    'editar' => 'Datos actualizados y proyección recalculada.',
                    'ia' => 'La IA propuso una causa. Revísala antes de aprobar.',
                    'revisar' => 'Decisión humana registrada.',
                    'reprogramar' => 'Reprogramación registrada (la línea base se conserva).',
                });
                Web::ir((string)($_POST['volver'] ?? 'actividad'), ($_POST['volver'] ?? '') === 'cierre' ? ['corte' => (int)$_POST['corte']] : ['id' => $id]);
            }
            $a = Repo::actividad($id);
            $corte = Repo::corte((int)$a['corte_id']);
            $obra = Repo::obra((int)$corte['obra_id']);
            vista('actividad', ['a' => $a, 'corte' => $corte, 'obra' => $obra, 'avances' => Repo::avances($id), 'todas' => Repo::actividades((int)$a['corte_id'])]);
            break;

        case 'carga':
            $u = Web::requiere(['planeamiento']);
            [$obras, $obra, $corte, $cortes] = contexto();
            $imp = $_SESSION['import'] ?? null;
            if ($post) {
                Web::verificarCsrf();
                $paso = (string)($_POST['paso'] ?? '');
                if ($paso === 'subir') {
                    $f = $_FILES['archivo'] ?? null;
                    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Selecciona un archivo .csv o .xlsx (máx. 5 MB).');
                    }
                    $filas = Importador::leer($f['tmp_name'], $f['name']);
                    $_SESSION['import'] = [
                        'obra' => (int)$_POST['obra_id'], 'semana' => (int)$_POST['semana'],
                        'corte' => (string)$_POST['fecha_corte'], 'cierre' => (string)$_POST['fecha_cierre'], 'datos' => (string)($_POST['fecha_datos'] ?? ''),
                        'archivo' => basename($f['name']), 'filas' => $filas,
                        'mapeo' => Importador::proponerMapeo($filas[0]),
                    ];
                    Web::ir('carga');
                }
                if ($paso === 'cancelar') {
                    unset($_SESSION['import']);
                    Web::ir('carga');
                }
                if ($paso === 'mapear' && $imp) {
                    $map = [];
                    foreach (array_keys(Importador::ALIAS) as $campo) {
                        $v = $_POST['map'][$campo] ?? '';
                        $map[$campo] = $v === '' ? null : (int)$v;
                    }
                    $_SESSION['import']['mapeo'] = $map;
                    if (isset($_POST['confirmar'])) {
                        $regs = Importador::aplicarMapeo($imp['filas'], $map);
                        $cid = Repo::importar($imp['obra'], $imp['semana'], $imp['corte'], $imp['cierre'], $regs, $imp['archivo'], (int)$u['id'], $imp['datos'] ?? null);
                        unset($_SESSION['import']);
                        Web::flash('ok', 'Corte importado: ' . count($regs) . ' actividades analizadas por el motor.');
                        Web::ir('tablero', ['obra' => $imp['obra'], 'corte' => $cid]);
                    }
                    Web::ir('carga');
                }
            }
            $previa = null;
            if ($imp) {
                $previa = ['regs' => [], 'bloq' => [], 'motor' => [], 'error' => null];
                try {
                    $previa['regs'] = Importador::aplicarMapeo($imp['filas'], $imp['mapeo']);
                    $previa['bloq'] = Repo::erroresBloqueantes($previa['regs']);
                    if (!$previa['bloq']) {
                        $previa['motor'] = Motor::desdeObra(Repo::obra($imp['obra']))->analizar($previa['regs'], $imp['corte'], $imp['cierre'], (int)Repo::obra($imp['obra'])['umbral_vigilar'], ($imp['datos'] ?? '') ?: null);
                    }
                } catch (Throwable $ex) {
                    $previa['error'] = $ex->getMessage();
                }
            }
            vista('carga', compact('obras', 'obra', 'corte', 'cortes', 'imp', 'previa'));
            break;

        case 'cierre':
            $u = Web::requiere(['planeamiento', 'produccion']);
            [$obras, $obra, $corte, $cortes] = contexto();
            if ($post) {
                Web::verificarCsrf();
                $cid = (int)$_POST['corte'];
                $acc = (string)$_POST['accion'];
                if ($acc === 'ia_todas') {
                    $n = Repo::proponerCausas($cid, (int)$u['id']);
                    Web::flash('ok', "La IA propuso {$n} causa(s). Cada una necesita decisión humana.");
                } elseif ($acc === 'aprobar') {
                    Web::requiere(['planeamiento']);
                    Repo::aprobar($cid, (string)$_POST['comentario'], (int)$u['id']);
                    Web::flash('ok', 'Corte aprobado. El informe ya está disponible para Gerencia.');
                } elseif ($acc === 'siguiente') {
                    Web::requiere(['planeamiento']);
                    $nid = Repo::siguienteCorte($cid, (int)$u['id']);
                    Web::flash('ok', 'Se preparó la semana siguiente con las actividades pendientes.');
                    Web::ir('tablero', ['corte' => $nid]);
                }
                Web::ir('cierre', ['corte' => $cid]);
            }
            $acts = $corte ? Repo::actividades((int)$corte['id']) : [];
            vista('cierre', compact('obras', 'obra', 'corte', 'cortes', 'acts') + [
                'k' => Repo::kpis($acts),
                'inc' => Repo::incumplidas($acts),
                'bloqueos' => $corte && $corte['estado'] === 'abierto' ? Repo::bloqueos((int)$corte['id']) : [],
            ]);
            break;

        case 'reporte':
            $u = Web::requiere();
            [$obras, $obra, $corte, $cortes] = contexto();
            if (!$corte) {
                throw new RuntimeException('No hay cortes para esta obra.');
            }
            if ($u['rol'] === 'gerencia' && $corte['estado'] !== 'aprobado') {
                throw new RuntimeException('Gerencia solo ve informes aprobados por Planeamiento.');
            }
            $acts = $corte['estado'] === 'aprobado' && $corte['snapshot'] ? json_decode($corte['snapshot'], true)['actividades'] : Repo::actividades((int)$corte['id']);
            $k = Repo::kpis($acts);
            require __DIR__ . '/../app/Vistas/reporte.php';
            exit;

        case 'gerencia':
            Web::requiere();
            [$obras, $obra, $corte, $cortes] = contexto();
            vista('gerencia', compact('obras', 'obra', 'cortes'));
            break;

        case 'exportar':
            Web::requiere();
            [$obras, $obra, $corte] = contexto();
            if (!$corte) {
                throw new RuntimeException('No hay corte para exportar.');
            }
            if (Web::usuario()['rol'] === 'gerencia' && $corte['estado'] !== 'aprobado') {
                throw new RuntimeException('Gerencia solo exporta cortes aprobados.');
            }
            $acts = Repo::actividades((int)$corte['id']);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="AlertaPlanIA_' . $obra['codigo'] . '_S' . $corte['semana'] . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $cols = ['codigo', 'nombre', 'sector', 'responsable', 'unidad', 'inicio', 'fin_base', 'fin', 'total', 'ejecutado', 'rendimiento', 'estado', 'motivo', 'proyeccion', 'atraso', 'holgura', 'comprometida_efectiva', 'cumplida', 'categoria_ia', 'confianza_ia', 'causa_estado', 'categoria_final', 'nueva_fecha', 'accion'];
            fputcsv($out, $cols, ';', '"', '');
            foreach ($acts as $a) {
                fputcsv($out, array_map(function ($c) use ($a) {
                    $v = is_bool($a[$c]) ? ($a[$c] ? 'si' : 'no') : (string)($a[$c] ?? '');
                    return preg_match('/^[=+@\-]/', $v) && !is_numeric($v) ? "'" . $v : $v; // evita inyección de fórmulas
                }, $cols), ';', '"', '');
            }
            Repo::auditar((int)Web::usuario()['id'], 'exportar_csv', 'corte', (int)$corte['id'], null);
            exit;

        case 'evaluacion':
            Web::requiere();
            $archivo = __DIR__ . '/../evidencias/resultados_pruebas.json';
            $res = is_file($archivo) ? json_decode((string)file_get_contents($archivo), true) : null;
            $extra = [];
            foreach (['telegram' => 'resultados_telegram.json', 'accesos' => 'resultados_accesos.json'] as $k => $f) {
                $ruta = __DIR__ . '/../evidencias/' . $f;
                $extra[$k] = is_file($ruta) ? json_decode((string)file_get_contents($ruta), true) : null;
            }
            vista('evaluacion', ['res' => $res, 'extra' => $extra]);
            break;

        case 'auditoria':
            Web::requiere(['planeamiento', 'gerencia']);
            vista('auditoria', ['filas' => Repo::auditoria(300)]);
            break;

        case 'telegram':
            $u = Web::requiere();
            $codigo = null;
            if ($post) {
                Web::verificarCsrf();
                if (($_POST['accion'] ?? '') === 'codigo') {
                    $codigo = App\Telegram\Bot::generarCodigo((int)$u['id']);
                } elseif (($_POST['accion'] ?? '') === 'quitar') {
                    db()->prepare('DELETE FROM telegram_vinculos WHERE chat_id = ? AND usuario_id = ?')->execute([(int)$_POST['chat'], $u['id']]);
                    Repo::auditar((int)$u['id'], 'telegram_desvincular', 'usuario', (int)$u['id'], ['chat' => (int)$_POST['chat']]);
                    Web::flash('ok', 'Chat desvinculado.');
                    Web::ir('telegram');
                }
            }
            if (!isset($_SESSION['tg_bot']) && env('TELEGRAM_BOT_TOKEN', '') !== '') {
                try {
                    $_SESSION['tg_bot'] = (new App\Telegram\Cliente((string)env('TELEGRAM_BOT_TOKEN'), 5))->llamar('getMe', [], 5)['username'] ?? '';
                } catch (Throwable) {
                    $_SESSION['tg_bot'] = '';
                }
            }
            $st = db()->prepare('SELECT * FROM telegram_vinculos WHERE usuario_id = ? ORDER BY creado_en DESC');
            $st->execute([$u['id']]);
            vista('telegram', ['codigo' => $codigo, 'chats' => $st->fetchAll(), 'bot' => ($_SESSION['tg_bot'] ?? '') ?: ltrim((string)env('TELEGRAM_BOT_USUARIO', ''), '@')]);
            break;

        case 'acerca':
            Web::requiere();
            vista('acerca');
            break;

        default:
            http_response_code(404);
            Web::ir('tablero');
    }
} catch (RuntimeException | InvalidArgumentException $ex) {
    Web::flash('error', $ex->getMessage());
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    header('Location: ' . ($ref && str_contains($ref, 'index.php') ? $ref : Web::url('tablero')));
    exit;
}
