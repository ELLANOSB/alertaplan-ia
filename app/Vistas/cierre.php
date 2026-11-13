<?php
use App\IA\ServicioIA;
use App\Web;
use function App\{e, fd, fdc, num, chip};

$titulo = 'Cierre semanal';
if (!$corte): ?><div class="panel vacio">No hay cortes para esta obra.</div><?php return; endif;
$abierto = $corte['estado'] === 'abierto';
$comp = array_filter($acts, fn($a) => $a['comprometida_efectiva']);
$pendIA = count(array_filter($inc, fn($a) => !$a['causa_id'] || $a['causa_estado'] === 'propuesta'));
?>
<div class="cabecera">
  <div>
    <div class="miga"><?= e($obra['nombre']) ?> · Semana <?= (int)$corte['semana'] ?> · <?= fd($corte['fecha_corte']) ?> → <?= fd($corte['fecha_cierre']) ?></div>
    <h1>Cierre semanal, causas y aprobación</h1>
    <p>La IA propone la causa de cada compromiso no cumplido con su cita; Planeamiento la acepta, la corrige o la marca “sin dato suficiente”. El informe no llega a Gerencia hasta que todo esté resuelto.</p>
  </div>
</div>

<section class="kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <div class="kpi destacado"><div class="eti">PPC</div><div class="val"><?= $k['ppc'] === null ? '—' : num($k['ppc']) . '<small>%</small>' ?></div><div class="det"><?= (int)$k['cumplidas'] ?> cumplidos / <?= (int)$k['comprometidas'] ?> comprometidos</div></div>
  <div class="kpi"><div class="eti">No cumplidos</div><div class="val" style="color:var(--rojo)"><?= count($inc) ?></div><div class="det">requieren causa y nueva fecha</div></div>
  <div class="kpi"><div class="eti">Causas sin decisión</div><div class="val"><?= $pendIA ?></div><div class="det">propuesta IA pendiente o sin solicitar</div></div>
  <div class="kpi"><div class="eti">Estado del corte</div><div class="val" style="font-size:22px;margin-top:10px"><?= $abierto ? 'Abierto' : 'Aprobado' ?></div><div class="det"><?= $abierto ? count($bloqueos) . ' bloqueo(s) para aprobar' : 'por ' . e($corte['aprobador']) . ' · ' . e(substr((string)$corte['aprobado_en'], 0, 16)) ?></div></div>
</section>

<section class="panel">
  <header><h2>Compromisos de la semana</h2><span class="suave">Fin planificado entre el corte y el cierre, o marcados manualmente</span></header>
  <div class="tabla-env"><table>
    <thead><tr><th>ID</th><th>Actividad</th><th>Fin plan</th><th class="num">Ejecutado</th><th>Fin real</th><th>Resultado</th><th>Estado / proyección</th></tr></thead>
    <tbody>
    <?php foreach ($comp as $a): ?>
      <tr><td class="mono"><a href="<?= Web::url('actividad', ['id' => $a['id']]) ?>"><b><?= e($a['codigo']) ?></b></a></td><td><?= e($a['nombre']) ?></td><td><?= fd($a['fin']) ?></td>
        <td class="num"><?= num($a['ejecutado']) ?>/<?= num($a['total']) ?> <?= e($a['unidad']) ?></td><td><?= fd($a['fin_real']) ?></td>
        <td><?= $a['cumplida'] ? '<span class="chip chip-verde"><i></i>Cumplido</span>' : '<span class="chip chip-rojo"><i></i>No cumplido</span>' ?></td>
        <td><?= chip($a['estado']) ?> <small><?= fdc($a['proyeccion']) ?></small></td></tr>
    <?php endforeach; if (!$comp): ?><tr><td colspan="7" class="vacio">No hay compromisos en esta semana.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <header>
    <h2>Causas de incumplimiento (IA + revisión humana)</h2>
    <?php if ($abierto && $inc && Web::puede('planeamiento', 'produccion')): ?>
      <form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="corte" value="<?= (int)$corte['id'] ?>"><input type="hidden" name="accion" value="ia_todas">
        <button class="primario" <?= $pendIA ? '' : 'disabled' ?>>Proponer causas con IA (<?= $pendIA ?>)</button></form>
    <?php endif; ?>
  </header>
  <p class="suave" style="margin-top:-4px">Motor de IA: <?= e(ia()->descripcionModo()) ?>. La IA no cambia fechas, semáforos ni PPC.</p>
  <?php foreach ($inc as $a): ?>
    <div class="tarjeta-causa">
      <div class="acciones" style="justify-content:space-between">
        <div><a class="mono" href="<?= Web::url('actividad', ['id' => $a['id']]) ?>"><b><?= e($a['codigo']) ?></b></a> · <?= e($a['nombre']) ?> <?= chip($a['estado']) ?></div>
        <small class="suave">Observación: “<?= e(mb_strimwidth(str_replace("\n", ' ', (string)$a['observacion']), 0, 120, '…')) ?>”</small>
      </div>
      <div class="rejilla g2" style="margin-top:10px;gap:14px">
        <div>
          <?php if (!$a['causa_id']): ?>
            <p class="suave" style="margin:0">Sin propuesta todavía.</p>
          <?php else: ?>
            <div><span class="etq ia">IA</span> <b><?= e(ServicioIA::CATEGORIAS[$a['categoria_ia']]) ?></b> <small class="suave">· confianza <?= num((float)$a['confianza_ia'] * 100) ?>% · <?= e($a['modo_ia']) ?></small></div>
            <?php if ($a['cita_ia']): ?><div class="cita" style="margin-top:6px">“<?= e($a['cita_ia']) ?>”</div><?php else: ?><p class="suave" style="margin:6px 0 0"><?= e($a['justificacion_ia']) ?></p><?php endif; ?>
            <?php if ($a['causa_estado'] !== 'propuesta'): ?>
              <p style="margin:8px 0 0"><span class="etq humano"><?= e($a['causa_estado']) ?></span> → <b><?= e(ServicioIA::CATEGORIAS[$a['categoria_final']]) ?></b> · <?= e($a['revisor']) ?><?= $a['causa_comentario'] ? ' · “' . e($a['causa_comentario']) . '”' : '' ?></p>
            <?php elseif ($abierto && Web::puede('planeamiento')): ?>
              <form method="post" action="<?= Web::url('actividad') ?>" style="margin-top:8px">
                <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="revisar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="volver" value="cierre"><input type="hidden" name="corte" value="<?= (int)$corte['id'] ?>">
                <div class="acciones">
                  <select name="categoria" aria-label="Categoría"><?php foreach (ServicioIA::CATEGORIAS as $kk => $t): if ($kk === 'sin_dato') continue; ?><option value="<?= $kk ?>" <?= $kk === $a['categoria_ia'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                  <input name="comentario" placeholder="Comentario (obligatorio al editar o sin dato)" style="flex:1;min-width:200px">
                </div>
                <div class="acciones" style="margin-top:8px">
                  <button class="primario chico" name="decision" value="aceptar" <?= $a['categoria_ia'] === 'sin_dato' ? 'disabled' : '' ?>>Aceptar</button>
                  <button class="chico" name="decision" value="editar">Editar</button>
                  <button class="chico peligro" name="decision" value="rechazar">Sin dato suficiente</button>
                </div>
              </form>
            <?php else: ?><p class="suave" style="margin:8px 0 0">Pendiente de decisión de Planeamiento.</p><?php endif; ?>
          <?php endif; ?>
        </div>
        <div>
          <?php if ($a['nueva_fecha']): ?>
            <div><span class="etq humano">reprogramado</span> nueva fecha <b><?= fd($a['nueva_fecha']) ?></b> <small class="suave">(base <?= fd($a['fin_base']) ?>)</small><br><small><?= e($a['accion']) ?></small></div>
          <?php endif; ?>
          <?php if ($abierto && Web::puede('planeamiento')): ?>
            <form method="post" action="<?= Web::url('actividad') ?>" class="acciones" style="margin-top:8px">
              <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="reprogramar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="volver" value="cierre"><input type="hidden" name="corte" value="<?= (int)$corte['id'] ?>">
              <input type="date" name="nueva_fecha" required value="<?= e($a['nueva_fecha'] ?: $a['proyeccion']) ?>" aria-label="Nueva fecha">
              <input name="accion_txt" required value="<?= e($a['accion']) ?>" placeholder="Acción y responsable" style="flex:1;min-width:180px">
              <button class="chico"><?= $a['nueva_fecha'] ? 'Actualizar' : 'Reprogramar' ?></button>
            </form>
            <small class="suave">Sugerida: fecha probable del motor (<?= fd($a['proyeccion']) ?>).</small>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; if (!$inc): ?><p class="vacio">Todos los compromisos de la semana se cumplieron.</p><?php endif; ?>
</section>

<section class="panel">
  <header><h2>Aprobación del informe semanal</h2></header>
  <?php if ($abierto): ?>
    <?php if ($bloqueos): ?>
      <div class="aviso alerta"><div><b>Aprobación bloqueada (<?= count($bloqueos) ?>).</b> Resuelve primero:
        <ul class="lista-bloqueos"><?php foreach ($bloqueos as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div></div>
    <?php else: ?>
      <div class="aviso ok"><div>Todo resuelto. Al aprobar, el corte se congela y el informe queda visible para Gerencia.</div></div>
    <?php endif; ?>
    <?php if (Web::puede('planeamiento')): ?>
    <form method="post" class="acciones">
      <?= Web::campoCsrf() ?><input type="hidden" name="corte" value="<?= (int)$corte['id'] ?>"><input type="hidden" name="accion" value="aprobar">
      <input name="comentario" placeholder="Comentario de aprobación (opcional)" style="flex:1;min-width:260px">
      <a class="boton" href="<?= Web::url('reporte', ['corte' => $corte['id']]) ?>" target="_blank">Vista previa del informe</a>
      <button class="primario" <?= $bloqueos ? 'disabled' : '' ?>>Aprobar y enviar a Gerencia</button>
    </form>
    <?php else: ?><p class="suave">Solo Planeamiento puede aprobar.</p><?php endif; ?>
  <?php else: ?>
    <div class="aviso ok"><div>Aprobado por <b><?= e($corte['aprobador']) ?></b> el <?= e(substr((string)$corte['aprobado_en'], 0, 16)) ?>. <?= $corte['comentario_aprobacion'] ? '“' . e($corte['comentario_aprobacion']) . '”' : '' ?></div></div>
    <div class="acciones">
      <a class="boton" href="<?= Web::url('reporte', ['corte' => $corte['id']]) ?>" target="_blank">Ver informe aprobado</a>
      <?php if (Web::puede('planeamiento')): ?>
      <form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="corte" value="<?= (int)$corte['id'] ?>"><input type="hidden" name="accion" value="siguiente"><button class="primario">Preparar semana <?= (int)$corte['semana'] + 1 ?> con pendientes</button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
