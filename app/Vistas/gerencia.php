<?php
use App\Graficos;
use App\Web;
use function App\{e, fd, num};

$titulo = 'Gerencia';
$aprob = array_values(array_filter($cortes, fn($c) => $c['estado'] === 'aprobado'));
$causas = [];
$atrasos = [];
foreach ($aprob as $c) {
    $s = json_decode((string)$c['snapshot'], true);
    foreach ($s['actividades'] ?? [] as $a) {
        if (!empty($a['categoria_final'])) {
            $causas[$a['categoria_final']] = ($causas[$a['categoria_final']] ?? 0) + 1;
        }
    }
}
$ult = $aprob[0] ?? null;
$snap = $ult ? json_decode((string)$ult['snapshot'], true) : null;
?>
<div class="cabecera">
  <div>
    <div class="miga">Vista de solo lectura · solo informes aprobados</div>
    <h1>Gerencia · <?= e($obra['nombre']) ?></h1>
    <p>Lo que ve Gerencia ya pasó por la revisión de Planeamiento: ninguna causa propuesta por IA llega aquí sin decisión humana.</p>
  </div>
</div>

<?php if (!$aprob): ?>
  <div class="panel vacio">Todavía no hay informes aprobados para esta obra.</div>
<?php else: ?>
<section class="kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <div class="kpi destacado"><div class="eti">PPC último aprobado</div><div class="val"><?= num($ult['ppc']) ?><small>%</small></div><div class="det">Semana <?= (int)$ult['semana'] ?> · <?= (int)$ult['cumplidas'] ?>/<?= (int)$ult['comprometidas'] ?></div></div>
  <div class="kpi"><div class="eti">PPC promedio</div><div class="val"><?= num(array_sum(array_column($aprob, 'ppc')) / count($aprob), 1) ?><small>%</small></div><div class="det"><?= count($aprob) ?> semana(s) aprobada(s)</div></div>
  <div class="kpi"><div class="eti">Atraso probable</div><div class="val" style="color:var(--rojo)"><?= (int)($snap['kpis']['estados']['rojo'] ?? 0) ?></div><div class="det">actividades en la última semana</div></div>
  <div class="kpi"><div class="eti">Avance ejecutado / prog.</div><div class="val" style="font-size:24px"><?= num($snap['kpis']['ejecutado'] ?? null, 1) ?>% / <?= num($snap['kpis']['programado'] ?? null, 1) ?>%</div><div class="det">promedio simple por actividad</div></div>
</section>
<div class="rejilla g2">
  <section class="panel"><header><h2>Tendencia de PPC</h2></header><div class="grafico"><?= Graficos::ppc($cortes) ?></div></section>
  <section class="panel"><header><h2>Causas de incumplimiento (acumulado)</h2><span class="etq humano">decisión humana</span></header><div class="grafico"><?= Graficos::causas($causas) ?></div></section>
</div>
<section class="panel mt">
  <header><h2>Informes aprobados</h2></header>
  <div class="tabla-env"><table>
    <thead><tr><th>Semana</th><th>Periodo</th><th class="num">PPC</th><th class="num">Compromisos</th><th>Aprobado por</th><th>Fecha</th><th>Comentario</th><th></th></tr></thead>
    <tbody><?php foreach ($aprob as $c): ?>
      <tr><td><b>S<?= (int)$c['semana'] ?></b></td><td><?= fd($c['fecha_corte']) ?> – <?= fd($c['fecha_cierre']) ?></td><td class="num"><b><?= num($c['ppc']) ?>%</b></td><td class="num"><?= (int)$c['cumplidas'] ?>/<?= (int)$c['comprometidas'] ?></td>
        <td><?= e($c['aprobador']) ?></td><td><?= e(substr((string)$c['aprobado_en'], 0, 16)) ?></td><td><small><?= e($c['comentario_aprobacion']) ?></small></td>
        <td><a class="boton chico" target="_blank" href="<?= Web::url('reporte', ['corte' => $c['id']]) ?>">Ver informe</a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
