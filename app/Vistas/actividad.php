<?php
use App\IA\ServicioIA;
use App\Web;
use function App\{e, fd, fdc, num, chip};

$titulo = $a['codigo'];
$abierto = $corte['estado'] === 'abierto';
$sup = $a['supuestos'] ? json_decode($a['supuestos'], true) : [];
$err = $a['errores'] ? json_decode($a['errores'], true) : [];
$val = $a['validacion_ia'] ? json_decode($a['validacion_ia'], true) : [];
$sucesoras = array_filter($todas, fn($x) => in_array($a['codigo'], array_map('trim', preg_split('/[|,;]/', (string)$x['predecesoras'])), true));
$requiereCausa = $a['comprometida_efectiva'] && !$a['cumplida'];
?>
<div class="cabecera">
  <div>
    <div class="miga"><a href="<?= Web::url('tablero', ['corte' => $corte['id']]) ?>">← Tablero</a> · <?= e($obra['nombre']) ?> · Semana <?= (int)$corte['semana'] ?></div>
    <h1><span class="mono"><?= e($a['codigo']) ?></span> · <?= e($a['nombre']) ?></h1>
    <p><?= chip($a['estado']) ?> &nbsp;<?= e($a['motivo']) ?></p>
  </div>
  <?php if (!$abierto): ?><span class="chip chip-azul"><i></i>Corte aprobado · solo lectura</span><?php endif; ?>
</div>

<div class="rejilla g2">
  <section class="panel">
    <header><h2>Cálculo explicable</h2><span class="etq calculo">motor determinístico</span></header>
    <dl class="def">
      <dt>Sector / responsable</dt><dd><?= e($a['sector']) ?> · <?= e($a['responsable']) ?></dd>
      <dt>Plan <span class="etq dato">dato</span></dt><dd><?= fd($a['inicio']) ?> → <?= fd($a['fin']) ?><?= $a['fin'] !== $a['fin_base'] ? ' <small>(línea base ' . fd($a['fin_base']) . ')</small>' : '' ?></dd>
      <dt>Metrado <span class="etq dato">dato</span></dt><dd><?= num($a['ejecutado']) ?> de <?= num($a['total']) ?> <?= e($a['unidad']) ?> · rendimiento <?= $a['rendimiento'] !== null ? num($a['rendimiento']) . ' ' . e($a['unidad']) . '/día' : '<b style="color:var(--rojo)">sin dato</b>' ?></dd>
      <dt>Avance al corte</dt><dd><?= num($a['ejecutado_pct'], 1) ?>% ejecutado vs <?= num($a['programado_pct'], 1) ?>% programado</dd>
      <dt>Compromiso semana</dt><dd><?= $a['comprometida_efectiva'] ? 'Sí' . ($a['comprometida'] === null ? ' (fin plan dentro de la semana)' : ' (marcado manualmente)') : 'No' ?><?= $a['comprometida_efectiva'] ? ' · ' . ($a['cumplida'] ? '<b style="color:var(--verde)">cumplido</b>' : '<b style="color:var(--rojo)">no cumplido</b>') : '' ?></dd>
      <?php if ($a['restriccion']): ?><dt>Restricción</dt><dd><?= e($a['restriccion']) ?> · liberación <?= fd($a['liberacion']) ?></dd><?php endif; ?>
      <?php if ($a['predecesoras']): ?><dt>Predecesoras (FC)</dt><dd class="mono"><?= e($a['predecesoras']) ?></dd><?php endif; ?>
      <?php if ($sucesoras): ?><dt>Sucesoras afectadas</dt><dd class="mono"><?= e(implode(', ', array_map(fn($s) => $s['codigo'] . ' (' . $s['estado'] . ')', $sucesoras))) ?></dd><?php endif; ?>
      <dt>Fuente</dt><dd><?= e($corte['archivo_origen']) ?><?= $a['fila_origen'] ? ', fila ' . (int)$a['fila_origen'] : '' ?></dd>
    </dl>
    <?php if ($err): ?>
      <div class="aviso alerta mt"><div><b>Datos por corregir:</b> <?= e(implode('; ', $err)) ?>. El motor no proyecta con datos incompletos.</div></div>
    <?php elseif ($a['duracion'] !== null): ?>
      <div class="formula mt">
        restante = <?= num($a['total']) ?> − <?= num($a['ejecutado']) ?> = <b><?= num((float)$a['total'] - (float)$a['ejecutado']) ?> <?= e($a['unidad']) ?></b><br>
        inicio del restante = día laborable siguiente a la fecha de datos (<?= fd(App\Repo::fechaDato((int)$corte['id']) ?? $corte['fecha_corte']) ?>)<?= $a['predecesoras'] || $a['restriccion'] ? ', predecesora o liberación' : '' ?><br>
        duración = techo(<?= num((float)$a['total'] - (float)$a['ejecutado']) ?> ÷ <?= num($a['rendimiento']) ?>) = <b><?= (int)$a['duracion'] ?> día(s) laborables</b><br>
        fecha probable = <b><?= fd($a['proyeccion']) ?></b> &nbsp;vs fin plan <?= fd($a['fin']) ?><br>
        atraso = <b><?= (int)$a['atraso'] ?> d calendario</b> · margen = <b><?= (int)$a['holgura'] ?> d laborables</b><?= (int)$a['continua'] ? ' · continúa tras el cierre ' . fd($corte['fecha_cierre']) : '' ?>
      </div>
    <?php endif; ?>
    <?php if ($sup): ?><ul class="suave" style="font-size:12.5px;margin:10px 0 0;padding-left:18px"><?php foreach ($sup as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section>

  <section class="panel">
    <header><h2>Seguimiento diario (parte de producción)</h2><span class="etq dato">registro de campo</span></header>
    <?php if ($abierto && Web::puede('produccion', 'planeamiento') && $a['estado'] !== 'azul'): ?>
    <form method="post" class="campos" style="margin-bottom:12px">
      <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="parte"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <div><label for="f">Fecha</label><input id="f" type="date" name="fecha" required value="<?= e(min(date('Y-m-d'), $corte['fecha_cierre'])) ?>" max="<?= e($corte['fecha_cierre']) ?>"></div>
      <div><label for="q">Cantidad del día (<?= e($a['unidad']) ?>)</label><input id="q" name="cantidad" inputmode="decimal" required placeholder="0"></div>
      <div style="grid-column:1/-1"><label for="n">Observación / eventualidad</label><textarea id="n" name="nota" placeholder="Ej.: Falta acero por entrega atrasada del proveedor"></textarea></div>
      <div><button class="primario">Registrar parte</button></div>
    </form>
    <?php endif; ?>
    <?php if ($avances): ?>
      <div class="tabla-env"><table><thead><tr><th>Fecha</th><th class="num">Cantidad</th><th>Nota</th><th>Registró</th></tr></thead><tbody>
      <?php foreach ($avances as $v): ?><tr><td><?= fd($v['fecha']) ?></td><td class="num"><?= num($v['cantidad']) ?></td><td><?= e($v['nota']) ?></td><td><small><?= e($v['nombre']) ?></small></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="suave">Sin partes diarios registrados en esta semana.</p><?php endif; ?>
    <h3 class="mt" style="margin-bottom:6px">Observación acumulada (fuente para la IA)</h3>
    <div style="white-space:pre-line;font-size:13.5px;background:#faf9fd;border:1px solid var(--borde);border-radius:8px;padding:10px"><?= e($a['observacion'] ?: 'Sin observaciones.') ?></div>
  </section>
</div>

<div class="rejilla g2 mt">
  <section class="panel">
    <header><h2>Causa de incumplimiento</h2><span class="etq ia">IA propone</span><span class="etq humano">persona decide</span></header>
    <?php if (!$requiereCausa && !$a['causa_id']): ?>
      <p class="suave">Solo los compromisos de la semana no cumplidos requieren causa. Esta actividad <?= $a['comprometida_efectiva'] ? 'está cumplida' : 'no es compromiso de esta semana' ?>.</p>
    <?php endif; ?>
    <?php if ($a['causa_id']): ?>
      <div class="tarjeta-causa">
        <div class="acciones" style="justify-content:space-between">
          <b><?= e(ServicioIA::CATEGORIAS[$a['categoria_ia']]) ?></b>
          <span class="suave">confianza <?= num((float)$a['confianza_ia'] * 100) ?>% · <?= e($a['modo_ia'] === 'claude' ? 'Claude (' . $a['modelo_ia'] . ')' : 'clasificador local') ?></span>
        </div>
        <div class="medidor" style="margin:8px 0"><i style="width:<?= (float)$a['confianza_ia'] * 100 ?>%"></i></div>
        <?php if ($a['cita_ia']): ?><div class="cita">“<?= e($a['cita_ia']) ?>”</div><small class="suave">Cita literal de la observación de campo.</small><?php endif; ?>
        <p style="margin:8px 0 0;font-size:13.5px"><?= e($a['justificacion_ia']) ?></p>
        <?php if ($val): ?><p class="suave" style="font-size:12px;margin:6px 0 0">Validación automática:
          <?php foreach ($val as $kk => $vv): ?><?= is_bool($vv) ? e($kk) . ($vv ? ' ✓' : ' ✗') . ' · ' : e($vv) . ' · ' ?><?php endforeach; ?></p><?php endif; ?>
        <hr style="border:0;border-top:1px solid var(--borde);margin:12px 0">
        <?php if ($a['causa_estado'] !== 'propuesta'): ?>
          <p style="margin:0"><span class="etq humano"><?= e($a['causa_estado']) ?></span> → <b><?= e(ServicioIA::CATEGORIAS[$a['categoria_final']]) ?></b> por <?= e($a['revisor']) ?> el <?= e(substr((string)$a['revisado_en'], 0, 16)) ?></p>
          <?php if ($a['causa_comentario']): ?><p class="suave" style="margin:4px 0 0">“<?= e($a['causa_comentario']) ?>”</p><?php endif; ?>
        <?php elseif ($abierto && Web::puede('planeamiento')): ?>
          <form method="post" class="campo">
            <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="revisar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <div class="campos">
              <div><label>Categoría (si editas)</label><select name="categoria"><?php foreach (ServicioIA::CATEGORIAS as $kk => $t): if ($kk === 'sin_dato') continue; ?><option value="<?= $kk ?>" <?= $kk === $a['categoria_ia'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            </div>
            <label class="mt">Comentario (obligatorio para editar o marcar sin dato)</label>
            <textarea name="comentario" placeholder="Ej.: Confirmado con el ingeniero de producción; OC 1234 llega el 16/11"></textarea>
            <div class="acciones mt">
              <button name="decision" value="aceptar" class="primario" <?= $a['categoria_ia'] === 'sin_dato' ? 'disabled' : '' ?>>Aceptar propuesta</button>
              <button name="decision" value="editar">Editar categoría</button>
              <button name="decision" value="rechazar" class="peligro">Sin dato suficiente</button>
            </div>
          </form>
        <?php else: ?><p class="suave">Pendiente de decisión de Planeamiento.</p><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($abierto && ($requiereCausa || $a['causa_id']) && (!$a['causa_id'] || $a['causa_estado'] === 'propuesta') && Web::puede('planeamiento', 'produccion')): ?>
      <form method="post" class="mt"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="ia"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="<?= $a['causa_id'] ? '' : 'primario' ?>"><?= $a['causa_id'] ? 'Volver a proponer con IA' : 'Proponer causa con IA' ?></button>
        <small class="suave">&nbsp;<?= e(ia()->descripcionModo()) ?></small></form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <header><h2>Reprogramación y corrección de datos</h2><span class="etq humano">decisión humana</span></header>
    <?php if ($a['nueva_fecha']): ?>
      <div class="aviso info"><div>Nueva fecha comprometida <b><?= fd($a['nueva_fecha']) ?></b> · acción: <?= e($a['accion']) ?><br><small>La línea base (<?= fd($a['fin_base']) ?>) no se modifica.</small></div></div>
    <?php endif; ?>
    <?php if ($abierto && Web::puede('planeamiento') && $requiereCausa): ?>
      <form method="post" class="campos" style="margin-bottom:16px">
        <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="reprogramar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <div><label>Nueva fecha</label><input type="date" name="nueva_fecha" required value="<?= e($a['nueva_fecha'] ?: $a['proyeccion']) ?>"></div>
        <div style="grid-column:span 2"><label>Acción y responsable</label><input name="accion_txt" required value="<?= e($a['accion']) ?>" placeholder="Ej.: Logística confirma acero el 16/11 (Ing. Producción)"></div>
        <div><button class="primario">Guardar reprogramación</button></div>
      </form>
    <?php endif; ?>
    <?php if ($abierto && Web::puede('planeamiento', 'produccion')): ?>
      <details <?= $err ? 'open' : '' ?>><summary>Corregir datos de la actividad</summary>
      <form method="post" class="campos mt">
        <?= Web::campoCsrf() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <div><label>Rendimiento (<?= e($a['unidad']) ?>/día)</label><input name="rendimiento" inputmode="decimal" value="<?= e($a['rendimiento'] !== null ? (float)$a['rendimiento'] : '') ?>"></div>
        <div><label>Fin real</label><input type="date" name="fin_real" value="<?= e($a['fin_real']) ?>"></div>
        <div><label>Restricción</label><input name="restriccion" value="<?= e($a['restriccion']) ?>"></div>
        <div><label>Liberación prevista</label><input type="date" name="liberacion" value="<?= e($a['liberacion']) ?>"></div>
        <div><label>¿Compromiso de la semana?</label><select name="comprometida"><option value="auto" <?= $a['comprometida'] === null ? 'selected' : '' ?>>Automático (según fin plan)</option><option value="1" <?= $a['comprometida'] === 1 || $a['comprometida'] === '1' ? 'selected' : '' ?>>Sí</option><option value="0" <?= $a['comprometida'] === 0 || $a['comprometida'] === '0' ? 'selected' : '' ?>>No</option></select></div>
        <div style="grid-column:1/-1"><label>Observación</label><textarea name="observacion"><?= e($a['observacion']) ?></textarea></div>
        <div><button>Guardar y recalcular</button></div>
      </form></details>
    <?php elseif (!$abierto): ?><p class="suave">Corte aprobado: los datos quedan congelados.</p><?php endif; ?>
  </section>
</div>
