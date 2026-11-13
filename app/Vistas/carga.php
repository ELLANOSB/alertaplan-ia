<?php
use App\Importador;
use App\Web;
use function App\{e, fd, fdc, num, chip};

$titulo = 'Cargar planificación';
$sig = $cortes ? max(array_column($cortes, 'semana')) + 1 : (int)date('W');
$lunes = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$viernes = (new DateTimeImmutable('friday this week'))->format('Y-m-d');
$nombres = ['codigo' => 'ID actividad', 'nombre' => 'Actividad', 'inicio' => 'Inicio plan', 'fin' => 'Fin plan', 'total' => 'Metrado total', 'ejecutado' => 'Ejecutado acumulado',
    'rendimiento' => 'Rendimiento/día', 'unidad' => 'Unidad', 'sector' => 'Sector / frente', 'responsable' => 'Responsable', 'predecesoras' => 'Predecesoras',
    'restriccion' => 'Restricción', 'liberacion' => 'Liberación', 'fin_real' => 'Fin real', 'observacion' => 'Observación'];
?>
<div class="cabecera">
  <div>
    <div class="miga">Paso 1 del flujo · Planeamiento</div>
    <h1>Cargar planificación semanal</h1>
    <p>Excel (.xlsx) o CSV. El sistema propone el mapeo de columnas, valida cada fila y muestra el análisis del motor <b>antes</b> de guardar.</p>
  </div>
</div>

<?php if (!$imp): ?>
<div class="rejilla g-73">
  <section class="panel">
    <form method="post" enctype="multipart/form-data">
      <?= Web::campoCsrf() ?><input type="hidden" name="paso" value="subir">
      <div class="campos">
        <div><label>Obra</label><select name="obra_id"><?php foreach ($obras as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$obra['id'] ? 'selected' : '' ?>><?= e($o['codigo'] . ' · ' . $o['nombre']) ?></option><?php endforeach; ?></select></div>
        <div><label>N.º de semana</label><input type="number" name="semana" min="1" max="999" required value="<?= (int)$sig ?>"></div>
        <div><label>Fecha de corte (fin del día)</label><input type="date" name="fecha_corte" required value="<?= e($lunes) ?>"></div>
        <div><label>Fecha de cierre semanal</label><input type="date" name="fecha_cierre" required value="<?= e($viernes) ?>"></div>
        <div><label>Fecha de datos (opcional)</label><input type="date" name="fecha_datos" title="Día al que corresponde el avance del archivo. Vacío = fecha de corte."></div>
      </div>
      <div class="campo mt"><label>Archivo de planificación</label><input type="file" name="archivo" accept=".csv,.xlsx" required style="width:100%;padding:18px;border-style:dashed"></div>
      <button class="primario">Leer y validar archivo</button>
    </form>
  </section>
  <section class="panel">
    <h2>Plantilla esperada</h2>
    <p class="suave">Primera fila con encabezados. Columnas obligatorias: <b>ID, Actividad, Inicio, Fin, Total, Ejecutado, Unidad</b>. Para actividades pendientes se necesita <b>Rendimiento</b> en unidad/día.</p>
    <p class="suave">Fechas AAAA-MM-DD, DD/MM/AAAA o fecha de Excel (sistema 1900). Predecesoras separadas por <code>|</code>. Máx. 5 MB y 5000 actividades.</p>
    <p>Datos de prueba incluidos en <code>datos/</code>:</p>
    <ul class="suave" style="font-size:13px"><li><code>ViaAndina_S47_plan.xlsx</code> — Vía Andina, semana 47 (demo de carga)</li><li><code>Claveles_S46_plan.xlsx</code> / <code>.csv</code> — plan de la semana 46 al corte</li><li><code>Prueba_errores.csv</code> (casos inválidos)</li></ul>
    <p class="suave" style="font-size:12.5px">Si la semana ya existe y está abierta, la carga la reemplaza. Una semana aprobada no se puede reemplazar.</p>
  </section>
</div>
<?php else: ?>
<form method="post">
  <?= Web::campoCsrf() ?><input type="hidden" name="paso" value="mapear">
  <section class="panel">
    <header><h2>Mapeo de columnas · <span class="mono"><?= e($imp['archivo']) ?></span></h2>
      <span class="suave">Obra <?= e(App\Repo::obra($imp['obra'])['nombre']) ?> · semana <?= (int)$imp['semana'] ?> · corte <?= fd($imp['corte']) ?> · cierre <?= fd($imp['cierre']) ?><?= !empty($imp['datos']) ? ' · datos al ' . fd($imp['datos']) : '' ?></span></header>
    <p class="suave" style="margin-top:-4px">Propuesto automáticamente por alias de encabezado. Confirma o corrige; los campos con * son obligatorios.</p>
    <div class="campos">
      <?php foreach ($nombres as $campo => $t): $sel = $imp['mapeo'][$campo] ?? null; ?>
        <div><label><?= e($t) ?><?= in_array($campo, Importador::OBLIGATORIOS, true) ? ' *' : '' ?></label>
          <select name="map[<?= $campo ?>]" style="<?= $sel === null && in_array($campo, Importador::OBLIGATORIOS, true) ? 'border-color:var(--rojo)' : '' ?>">
            <option value="">— no usar —</option>
            <?php foreach ($imp['filas'][0] as $i => $h): ?><option value="<?= $i ?>" <?= $sel === $i ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?>
          </select></div>
      <?php endforeach; ?>
    </div>
    <div class="acciones mt"><button>Aplicar mapeo y revalidar</button></div>
  </section>

  <section class="panel">
    <header><h2>Validación y análisis previo</h2><span class="suave"><?= count($imp['filas']) - 1 ?> filas leídas</span></header>
    <?php if ($previa['error']): ?>
      <div class="aviso error"><?= e($previa['error']) ?></div>
    <?php elseif ($previa['bloq']): ?>
      <div class="aviso error"><div><b>No se puede importar todavía (<?= count($previa['bloq']) ?> error(es) de formato).</b> Corrige el archivo y vuelve a subirlo:
        <ul class="lista-bloqueos"><?php foreach (array_slice($previa['bloq'], 0, 12) as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div></div>
    <?php else:
      $grises = array_filter($previa['motor'], fn($r) => $r['estado'] === 'gris'); ?>
      <div class="aviso <?= $grises ? 'alerta' : 'ok' ?>"><div>Formato correcto. <?= $grises ? count($grises) . ' fila(s) quedarán en “Revisar datos” (podrás corregirlas en la app).' : 'Todas las filas tienen datos suficientes.' ?></div></div>
      <div class="tabla-env"><table>
        <thead><tr><th>Fila</th><th>ID</th><th>Actividad</th><th>Fin plan</th><th class="num">Ejec./Total</th><th>Proyección</th><th>Estado</th><th>Motivo</th></tr></thead>
        <tbody><?php foreach ($previa['motor'] as $r): ?>
          <tr class="fila-<?= e($r['estado']) ?>"><td><?= (int)$r['fila_origen'] ?></td><td class="mono"><?= e($r['codigo']) ?></td><td><?= e($r['nombre']) ?></td><td><?= fd($r['fin']) ?></td>
          <td class="num"><?= e($r['ejecutado']) ?>/<?= e($r['total']) ?> <?= e($r['unidad']) ?></td><td><?= fdc($r['proyeccion']) ?></td><td><?= chip($r['estado']) ?></td><td><small><?= e($r['motivo']) ?></small></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <div class="acciones mt">
      <button class="primario" name="confirmar" value="1" <?= ($previa['error'] || $previa['bloq']) ? 'disabled' : '' ?>>Confirmar e importar corte</button>
      <button name="paso" value="cancelar" formnovalidate>Cancelar</button>
    </div>
  </section>
</form>
<?php endif; ?>
