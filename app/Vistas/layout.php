<?php
use App\Web;
use function App\e;

$ico = [
    'tablero' => '<path d="M3 13h8V3H3zm10 8h8V11h-8zM3 21h8v-6H3zm10-18v6h8V3z"/>',
    'carga' => '<path d="M12 3l5 5h-3v6h-4V8H7zm-7 14h14v4H5z"/>',
    'cierre' => '<path d="M9 16.2l-3.5-3.5L4 14.2l5 5 11-11-1.4-1.4z"/>',
    'reporte' => '<path d="M6 2h9l5 5v15H6zM14 3v5h5M9 13h8M9 17h8M9 9h3"/>',
    'gerencia' => '<path d="M12 4.5C7 4.5 2.7 7.6 1 12c1.7 4.4 6 7.5 11 7.5s9.3-3.1 11-7.5c-1.7-4.4-6-7.5-11-7.5zm0 12.5a5 5 0 110-10 5 5 0 010 10zm0-8a3 3 0 100 6 3 3 0 000-6z"/>',
    'evaluacion' => '<path d="M4 20h16v2H4zM6 10h3v8H6zm5-6h3v14h-3zm5 9h3v5h-3z"/>',
    'auditoria' => '<path d="M13 3a9 9 0 00-9 9H1l4 4 4-4H6a7 7 0 112.05 4.95l-1.42 1.42A9 9 0 1013 3zm-1 5v5l4.25 2.52.77-1.28-3.52-2.09V8z"/>',
    'telegram' => '<path d="M21.9 4.3L18.7 19.4c-.2 1-.9 1.3-1.7.8l-4.8-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.5 13.2 1.8 11.7c-1-.3-1-1 .2-1.5L20.6 3c.9-.3 1.6.2 1.3 1.3z"/>',
    'acerca' => '<path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9a10 10 0 100 20 10 10 0 000-20z"/>',
];
$menu = [
    ['tablero', 'Tablero de obra', ['planeamiento', 'produccion', 'gerencia']],
    ['carga', 'Cargar planificación', ['planeamiento']],
    ['cierre', 'Cierre y causas IA', ['planeamiento', 'produccion']],
    ['reporte', 'Informe semanal', ['planeamiento', 'produccion', 'gerencia']],
    ['gerencia', 'Vista de gerencia', ['planeamiento', 'produccion', 'gerencia']],
    ['sep'],
    ['evaluacion', 'Pruebas y resultados', ['planeamiento', 'produccion', 'gerencia']],
    ['auditoria', 'Auditoría', ['planeamiento', 'gerencia']],
    ['telegram', 'Bot de Telegram', ['planeamiento', 'produccion', 'gerencia']],
    ['acerca', 'Arquitectura e IA', ['planeamiento', 'produccion', 'gerencia']],
];
$ini = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(explode(' ', $usuario['nombre']), 0, 2)));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($titulo ?? 'AlertaPlan IA') ?> · AlertaPlan IA</title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body>
<div class="app">
  <aside class="lateral">
    <img class="logo-aecode" src="assets/img/aecode_light.png" alt="AECODE">
    <div class="producto">
      <b>AlertaPlan <span>IA</span></b>
      <small>Seguimiento de obra · Diplomado IA en Ingeniería y Construcción · Ed. 03</small>
    </div>
    <nav class="menu">
      <?php foreach ($menu as $m): if ($m[0] === 'sep') { echo '<div class="sep"></div>'; continue; }
          if (!in_array($usuario['rol'], $m[2], true)) continue; ?>
        <a href="<?= Web::url($m[0]) ?>" class="<?= $pagina === $m[0] ? 'activo' : '' ?>">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><?= $ico[$m[0]] ?></svg><?= e($m[1]) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="pie">
      <b>Eje Desarrollo</b> · Hito 3<br>
      IA: <?= e(ia()->descripcionModo()) ?><br>
      Datos ficticios con fines académicos.
    </div>
  </aside>
  <div class="principal">
    <header class="barra">
      <?php if (!empty($obras)): ?>
      <form method="get">
        <input type="hidden" name="p" value="<?= e($pagina === 'actividad' ? 'tablero' : $pagina) ?>">
        <label for="obra" class="suave" style="margin:0">Obra</label>
        <select id="obra" name="obra" onchange="this.form.submit()">
          <?php foreach ($obras as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)($obra['id'] ?? 0) ? 'selected' : '' ?>><?= e($o['codigo'] . ' · ' . $o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($cortes) && in_array($pagina, ['tablero', 'cierre', 'reporte'], true)): ?>
          <label for="corte" class="suave" style="margin:0">Semana</label>
          <select id="corte" name="corte" onchange="this.form.submit()">
            <?php foreach ($cortes as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)($corte['id'] ?? 0) ? 'selected' : '' ?>>
                S<?= (int)$c['semana'] ?> · <?= App\fd($c['fecha_corte'], 'd/m') ?>–<?= App\fd($c['fecha_cierre'], 'd/m') ?> · <?= $c['estado'] === 'aprobado' ? 'aprobado' : 'abierto' ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <noscript><button>Ver</button></noscript>
      </form>
      <?php endif; ?>
      <div class="usuario">
        <div class="avatar"><?= e($ini) ?></div>
        <div><b><?= e($usuario['nombre']) ?></b><small><?= e(Web::ROLES[$usuario['rol']]) ?></small></div>
        <a class="boton chico" href="<?= Web::url('salir') ?>">Salir</a>
      </div>
    </header>
    <main class="contenido">
      <?php foreach (Web::mensajes() as [$t, $m]): ?>
        <div class="aviso <?= $t === 'ok' ? 'ok' : 'error' ?>" role="status"><?= e($m) ?></div>
      <?php endforeach; ?>
      <?= $contenido ?>
    </main>
  </div>
</div>
<script src="assets/app.js?v=3"></script>
</body>
</html>
