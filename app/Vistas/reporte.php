<?php
use App\IA\ServicioIA;
use App\Repo;
use App\Web;
use function App\{e, fd, fdc, num, chip};

$aprobado = $corte['estado'] === 'aprobado';
$resumen = Repo::resumen($corte, $acts, $k);
$inc = array_values(array_filter($acts, fn($a) => $a['comprometida_efectiva'] && !$a['cumplida']));
$restr = array_values(array_filter($acts, fn($a) => $a['restriccion'] || $a['estado'] === 'naranja'));
$sig = array_values(array_filter($acts, fn($a) => $a['estado'] !== 'azul'));
$falta = '<b style="color:#cf3f3f">[FALTA DATO]</b>';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Informe semanal S<?= (int)$corte['semana'] ?> · <?= e($obra['nombre']) ?></title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<style>
body{background:#e9e7f0}
.hoja{max-width:960px;margin:24px auto;background:#fff;padding:36px 44px;box-shadow:0 6px 30px rgba(0,0,0,.08);border-radius:6px}
.enc{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid var(--marca);padding-bottom:14px}
.enc img{height:30px}
.enc .tit{text-align:right}
.enc .tit b{font-size:20px;display:block}
.marca{display:inline-block;padding:3px 10px;border-radius:6px;font-weight:700;font-size:12px;letter-spacing:.05em}
.datos{display:grid;grid-template-columns:2fr 1fr 1fr;border:1px solid var(--borde);border-radius:8px;margin:16px 0;overflow:hidden}
.datos div{padding:8px 12px;border-right:1px solid var(--borde)}.datos div:last-child{border:0}
.datos small{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--tinta-3);font-weight:700}
.sec{margin-top:20px}
.sec h2{font-size:14px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--borde);padding-bottom:6px;margin-bottom:8px}
.sec h2 span{background:var(--marca);color:#fff;border-radius:50%;width:22px;height:22px;display:grid;place-items:center;font-size:12px}
.sec p.sub{margin:0 0 8px;color:var(--tinta-3);font-size:12.5px}
table{font-size:12.5px}td,th{padding:6px 8px}
.firmas{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:40px;text-align:center;font-size:12.5px}
.firmas div{border-top:1px solid var(--tinta);padding-top:6px}
.barra-acc{max-width:960px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end}
@media print{body{background:#fff}.hoja{box-shadow:none;margin:0;padding:0;max-width:none}.barra-acc{display:none}}
</style></head><body>
<div class="barra-acc">
  <a class="boton" href="<?= Web::url('tablero', ['corte' => $corte['id']]) ?>">← Volver</a>
  <button class="primario" onclick="window.print()">Imprimir / guardar PDF</button>
</div>
<article class="hoja">
  <header class="enc">
    <div><img src="assets/img/aecode_dark.png" alt="AECODE"><div class="suave" style="font-size:11.5px;margin-top:6px">Diplomado Internacional de IA aplicada a Ingeniería y Construcción · Ed. 03 · Eje Desarrollo</div></div>
    <div class="tit"><b>Informe semanal de obra</b>
      <?= $aprobado ? '<span class="marca" style="background:#e3f4ea;color:#1d8f52">APROBADO</span>' : '<span class="marca" style="background:#fbf2d6;color:#7a5b00">BORRADOR · NO ENVIADO A GERENCIA</span>' ?>
      <div class="suave" style="font-size:11.5px;margin-top:4px">Generado por AlertaPlan IA · datos ficticios con fines académicos</div></div>
  </header>

  <div class="datos">
    <div><small>Obra o proyecto</small><b><?= e($obra['nombre']) ?></b><br><span class="suave"><?= e($obra['etapa']) ?> · <?= e($obra['ubicacion']) ?></span></div>
    <div><small>Semana N.º</small><b><?= (int)$corte['semana'] ?></b><br><span class="suave"><?= fd($corte['fecha_corte']) ?> al <?= fd($corte['fecha_cierre']) ?></span></div>
    <div><small>PPC</small><b style="font-size:20px"><?= $k['ppc'] === null ? '—' : num($k['ppc']) . '%' ?></b><br><span class="suave"><?= (int)$k['cumplidas'] ?>/<?= (int)$k['comprometidas'] ?> compromisos</span></div>
  </div>

  <section class="sec"><h2><span>1</span>Resumen ejecutivo</h2>
    <p class="sub">Redactado por reglas a partir de los cálculos del motor (no por IA generativa).</p>
    <ul style="margin:0;padding-left:18px"><?php foreach ($resumen as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
  </section>

  <section class="sec"><h2><span>2</span>Avance físico por actividad</h2>
    <p class="sub">Toda cifra de avance va con su programado y la diferencia.</p>
    <table><thead><tr><th>ID</th><th>Actividad</th><th class="num">Programado</th><th class="num">Ejecutado</th><th class="num">Diferencia</th><th>Fin plan</th><th>Proyección</th><th>Estado</th></tr></thead><tbody>
    <?php foreach ($acts as $a): $d = ($a['ejecutado_pct'] !== null && $a['programado_pct'] !== null) ? (float)$a['ejecutado_pct'] - (float)$a['programado_pct'] : null; ?>
      <tr><td class="mono"><?= e($a['codigo']) ?></td><td><?= e($a['nombre']) ?></td><td class="num"><?= num($a['programado_pct'], 1) ?>%</td><td class="num"><?= num($a['ejecutado_pct'], 1) ?>%</td>
      <td class="num" style="color:<?= $d !== null && $d < 0 ? '#cf3f3f' : '#1d8f52' ?>"><?= $d === null ? $falta : (($d >= 0 ? '+' : '') . num($d, 1) . ' pp') ?></td>
      <td><?= fdc($a['fin']) ?></td><td><?= $a['estado'] === 'gris' ? $falta : fdc($a['proyeccion']) ?></td><td><?= chip($a['estado']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </section>

  <section class="sec"><h2><span>3</span>Compromisos no cumplidos y causas</h2>
    <p class="sub">La IA propone la causa con una cita de la observación; la decisión final es humana.</p>
    <?php if (!$inc): ?><p>Se cumplieron todos los compromisos de la semana.</p><?php else: ?>
    <table><thead><tr><th>ID</th><th>Actividad</th><th>Causa final</th><th>Propuesta IA (cita)</th><th>Decisión</th><th>Nueva fecha</th><th>Acción</th></tr></thead><tbody>
    <?php foreach ($inc as $a): ?>
      <tr><td class="mono"><?= e($a['codigo']) ?></td><td><?= e($a['nombre']) ?></td>
        <td><b><?= $a['categoria_final'] ? e(ServicioIA::CATEGORIAS[$a['categoria_final']]) : $falta ?></b></td>
        <td><small><?= $a['categoria_ia'] ? e(ServicioIA::CATEGORIAS[$a['categoria_ia']]) . ' · ' . num((float)$a['confianza_ia'] * 100) . '%' : '—' ?><?= $a['cita_ia'] ? '<br><i>“' . e($a['cita_ia']) . '”</i>' : '' ?></small></td>
        <td><small><?= $a['causa_estado'] && $a['causa_estado'] !== 'propuesta' ? e($a['causa_estado']) . ' · ' . e($a['revisor']) . ($a['causa_comentario'] ? '<br>“' . e($a['causa_comentario']) . '”' : '') : $falta ?></small></td>
        <td><?= $a['nueva_fecha'] ? fd($a['nueva_fecha']) : $falta ?></td><td><small><?= $a['accion'] ? e($a['accion']) : $falta ?></small></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </section>

  <section class="sec"><h2><span>4</span>Restricciones y bloqueos</h2>
    <?php if (!$restr): ?><p>Sin restricciones registradas.</p><?php else: ?>
    <table><thead><tr><th>ID</th><th>Qué traba</th><th>Liberación prevista</th><th>Efecto calculado</th></tr></thead><tbody>
    <?php foreach ($restr as $a): ?><tr><td class="mono"><?= e($a['codigo']) ?></td><td><?= e($a['restriccion'] ?: 'Predecesora') ?></td><td><?= $a['liberacion'] ? fd($a['liberacion']) : $falta ?></td><td><small><?= e($a['motivo']) ?></small></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </section>

  <section class="sec"><h2><span>5</span>Programación de la siguiente semana</h2>
    <p class="sub">Actividades pendientes con su fecha probable. Las reprogramaciones no cambian la línea base.</p>
    <table><thead><tr><th>ID</th><th>Actividad</th><th class="num">Restante</th><th>Fin vigente</th><th>Nueva fecha</th><th>Fecha probable</th></tr></thead><tbody>
    <?php foreach ($sig as $a): ?><tr><td class="mono"><?= e($a['codigo']) ?></td><td><?= e($a['nombre']) ?></td><td class="num"><?= num((float)$a['total'] - (float)$a['ejecutado']) ?> <?= e($a['unidad']) ?></td><td><?= fd($a['fin']) ?></td><td><?= fd($a['nueva_fecha']) ?></td><td><?= $a['estado'] === 'gris' ? $falta : fd($a['proyeccion']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>

  <div class="firmas">
    <div><b><?= e($corte['importador'] ?? '') ?: '—' ?></b><br>Elaborado por (carga del corte)</div>
    <div><b><?= $aprobado ? e($corte['aprobador']) : '—' ?></b><br>Revisado y aprobado por<?= $aprobado ? '<br><small>' . e(substr((string)$corte['aprobado_en'], 0, 16)) . '</small>' : '' ?></div>
    <div><b>Gerencia de proyecto</b><br>Recibido por (vista de solo lectura)</div>
  </div>
  <p class="suave" style="font-size:11px;margin-top:24px">Regla del documento: lo que falte se marca [FALTA DATO]; no se completa por criterio propio. Fuente: <?= e($corte['archivo_origen']) ?> y partes diarios registrados en AlertaPlan IA.</p>
</article>
</body></html>
