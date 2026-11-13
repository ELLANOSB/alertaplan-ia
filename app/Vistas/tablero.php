<?php
use App\Graficos;
use App\Motor;
use App\Web;
use function App\{e, fd, fdc, num, chip};

$titulo = 'Tablero';
if (!$corte): ?>
  <div class="panel vacio"><h2>Esta obra aún no tiene cortes</h2><p>Carga la planificación semanal para empezar.</p>
  <?php if (Web::puede('planeamiento')): ?><a class="boton primario" href="<?= Web::url('carga') ?>">Cargar planificación</a><?php endif; ?></div>
<?php return; endif;
$sectores = array_unique(array_filter(array_column($acts, 'sector')));
$resps = array_unique(array_filter(array_column($acts, 'responsable')));
$riesgo = $k['estados']['rojo'] + $k['estados']['naranja'];
$sinCausa = count(array_filter(App\Repo::incumplidas($acts), fn($a) => !$a['causa_id'] || $a['causa_estado'] === 'propuesta'));
?>
<div class="cabecera">
  <div>
    <div class="miga"><?= e($obra['codigo']) ?> · <?= e($obra['etapa'] ?? '') ?> · <?= e($obra['ubicacion'] ?? '') ?></div>
    <h1><?= e($obra['nombre']) ?></h1>
    <p>Semana <b><?= (int)$corte['semana'] ?></b> · corte <?= fd($corte['fecha_corte']) ?> · cierre <?= fd($corte['fecha_cierre']) ?> · <span title="Último día con parte diario: el restante se proyecta desde el día laborable siguiente">datos al <b><?= fd(App\Repo::fechaDato((int)$corte['id']) ?? $corte['fecha_corte']) ?></b></span> ·
      <?= $corte['estado'] === 'aprobado' ? '<span class="chip chip-azul"><i></i>Aprobado por ' . e($corte['aprobador']) . '</span>' : '<span class="chip chip-amarillo"><i></i>Abierto</span>' ?></p>
  </div>
  <div class="acciones no-imprimir">
    <a class="boton" href="<?= Web::url('exportar', ['corte' => $corte['id']]) ?>">Exportar CSV</a>
    <a class="boton" href="<?= Web::url('reporte', ['corte' => $corte['id']]) ?>" target="_blank">Informe semanal</a>
    <?php if ($corte['estado'] === 'abierto' && Web::puede('planeamiento', 'produccion')): ?>
      <a class="boton primario" href="<?= Web::url('cierre', ['corte' => $corte['id']]) ?>">Ir al cierre semanal</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($corte['estado'] === 'abierto'): ?>
<ol class="pasos" aria-label="Flujo del corte">
  <li class="hecho">Planificación cargada y validada</li>
  <li class="<?= $k['estados']['gris'] ? 'actual' : 'hecho' ?>">Corregir datos (<?= (int)$k['estados']['gris'] ?>)</li>
  <li class="hecho">Motor proyecta fechas y alertas</li>
  <li class="<?= $sinCausa ? 'actual' : 'hecho' ?>">IA propone causas · persona decide (<?= $sinCausa ?>)</li>
  <li>Aprobar informe → Gerencia</li>
</ol>
<?php endif; ?>

<section class="kpis">
  <div class="kpi destacado">
    <div class="eti">PPC de la semana</div>
    <div class="val"><?= $k['ppc'] === null ? '—' : num($k['ppc']) . '<small>%</small>' ?></div>
    <div class="det"><?= (int)$k['cumplidas'] ?> de <?= (int)$k['comprometidas'] ?> compromisos cumplidos</div>
    <div class="barra-mini"><i style="width:<?= (float)$k['ppc'] ?>%"></i></div>
  </div>
  <div class="kpi">
    <div class="eti">Avance ejecutado vs programado</div>
    <div class="val"><?= num($k['ejecutado'], 1) ?><small>% / <?= num($k['programado'], 1) ?>%</small></div>
    <div class="det">Desviación <b style="color:<?= ($k['desviacion'] ?? 0) < 0 ? 'var(--rojo)' : 'var(--verde)' ?>"><?= ($k['desviacion'] >= 0 ? '+' : '') . num($k['desviacion'], 1) ?> pp</b> · promedio simple</div>
  </div>
  <div class="kpi">
    <div class="eti">En riesgo</div>
    <div class="val" style="color:var(--rojo)"><?= $riesgo ?></div>
    <div class="det"><?= (int)$k['estados']['rojo'] ?> atraso probable · <?= (int)$k['estados']['naranja'] ?> bloqueadas</div>
  </div>
  <div class="kpi">
    <div class="eti">Continúan tras el cierre</div>
    <div class="val"><?= (int)$k['continuan'] ?></div>
    <div class="det">Pasan de semana ≠ atraso: se evalúa contra su fin</div>
  </div>
  <div class="kpi">
    <div class="eti">Revisar datos</div>
    <div class="val" style="color:var(--gris)"><?= (int)$k['estados']['gris'] ?></div>
    <div class="det">Sin dato no se proyecta ni se inventa fecha</div>
  </div>
</section>

<div class="rejilla g-73">
  <section class="panel">
    <header><h2>Avance por actividad al corte</h2><span class="suave"><?= (int)$k['total'] ?> actividades</span></header>
    <div class="grafico"><?= Graficos::avance($acts) ?></div>
  </section>
  <div>
    <section class="panel">
      <header><h2>Estado de las actividades</h2></header>
      <div class="grafico"><?= Graficos::distribucion($k['estados']) ?></div>
      <div class="leyenda" style="margin-top:10px">
        <?php foreach (Motor::ETIQUETAS as $s => $t): ?><span><i style="background:<?= Graficos::COLOR[$s] ?>"></i><?= e($t) ?> (<?= (int)$k['estados'][$s] ?>)</span><?php endforeach; ?>
      </div>
    </section>
    <section class="panel">
      <header><h2>PPC por semana</h2><span class="suave">semanas aprobadas</span></header>
      <div class="grafico"><?= Graficos::ppc($cortes) ?></div>
    </section>
  </div>
</div>

<section class="panel mt">
  <header>
    <h2>Actividades y alertas</h2>
    <span class="leyenda"><span class="etq dato">dato de origen</span><span class="etq calculo">cálculo del motor</span><span class="etq ia">propuesta IA</span></span>
  </header>
  <div class="filtros no-imprimir" data-filtros="#tabla-acts">
    <input type="search" placeholder="Buscar código, actividad u observación…" data-f="texto" aria-label="Buscar">
    <select data-f="estado" aria-label="Estado"><option value="">Todos los estados</option><?php foreach (Motor::ETIQUETAS as $s => $t): ?><option value="<?= $s ?>"><?= e($t) ?></option><?php endforeach; ?></select>
    <select data-f="sector" aria-label="Sector"><option value="">Todos los sectores</option><?php foreach ($sectores as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select>
    <select data-f="resp" aria-label="Responsable"><option value="">Todos los responsables</option><?php foreach ($resps as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select>
    <label style="margin:0;display:flex;gap:6px;align-items:center;font-weight:500"><input type="checkbox" data-f="comp"> Solo compromisos de la semana</label>
    <span class="suave" data-contador></span>
  </div>
  <div class="tabla-env">
  <table id="tabla-acts">
    <thead><tr>
      <th>ID</th><th>Actividad</th><th>Sector</th><th class="num">Ejec./Total</th><th>Fin plan</th><th>Proyección</th><th class="num">Atraso</th><th>Estado</th><th>Motivo (regla)</th><th>Causa</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($acts as $a): ?>
      <tr class="fila-<?= e($a['estado']) ?>" data-estado="<?= e($a['estado']) ?>" data-sector="<?= e($a['sector']) ?>" data-resp="<?= e($a['responsable']) ?>" data-comp="<?= $a['comprometida_efectiva'] ? 1 : 0 ?>"
          data-texto="<?= e(mb_strtolower($a['codigo'] . ' ' . $a['nombre'] . ' ' . $a['observacion'])) ?>">
        <td class="mono"><b><?= e($a['codigo']) ?></b><?= $a['comprometida_efectiva'] ? '<br><small title="Compromiso de la semana">compromiso</small>' : '' ?></td>
        <td><?= e($a['nombre']) ?><br><small><?= e($a['responsable']) ?></small></td>
        <td><?= e($a['sector']) ?></td>
        <td class="num"><?= num($a['ejecutado']) ?>/<?= num($a['total']) ?> <small><?= e($a['unidad']) ?></small></td>
        <td><?= fdc($a['fin']) ?><?= $a['fin'] !== $a['fin_base'] ? '<br><small>base ' . fdc($a['fin_base']) . '</small>' : '' ?></td>
        <td><b><?= fdc($a['proyeccion']) ?></b></td>
        <td class="num"><?= $a['atraso'] !== null && $a['estado'] !== 'azul' ? ((int)$a['atraso'] > 0 ? '<b style="color:var(--rojo)">+' . (int)$a['atraso'] . ' d</b>' : (int)$a['atraso'] . ' d') : '—' ?></td>
        <td><?= chip($a['estado']) ?></td>
        <td style="max-width:280px"><small><?= e($a['motivo']) ?></small></td>
        <td><?php if ($a['categoria_final']): ?><span class="etq humano"><?= e(App\IA\ServicioIA::CATEGORIAS[$a['categoria_final']]) ?></span>
            <?php elseif ($a['causa_id']): ?><span class="etq ia">IA: <?= e(App\IA\ServicioIA::CATEGORIAS[$a['categoria_ia']]) ?></span>
            <?php else: ?><small class="suave">—</small><?php endif; ?></td>
        <td><a class="boton chico" href="<?= Web::url('actividad', ['id' => $a['id']]) ?>">Detalle</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="suave" style="margin:10px 0 0;font-size:12.5px">Atraso = días calendario entre fin planificado vigente y fecha probable. Margen = días laborables. Calendario: <?= e(implode(', ', array_map(fn($d) => ['dom','lun','mar','mié','jue','vie','sáb'][(int)$d], explode(',', $obra['dias_laborables'])))) ?><?= $obra['feriados'] ? ' · feriados: ' . e($obra['feriados']) : '' ?> · umbral de vigilancia <?= (int)$obra['umbral_vigilar'] ?> días.</p>
</section>
