<?php
use App\IA\ServicioIA;
use function App\{e, num};

$titulo = 'Pruebas y resultados';
if (!$res): ?>
  <div class="panel vacio"><h2>Aún no se ejecutaron las pruebas</h2><p>En la terminal, dentro de la carpeta del proyecto: <code>php tests/pruebas.php</code></p></div>
<?php return; endif;
$m = $res['motor']; $f = $res['flujo']; $ia = $res['ia']; $lb = $res['linea_base'];
$tarjeta = function (string $t, array $x) {
    return '<div class="kpi"><div class="eti">' . e($t) . '</div><div class="val">' . (int)$x['aprobados'] . '<small>/' . (int)$x['total'] . '</small></div><div class="det">' . ($x['aprobados'] === $x['total'] ? '<b style="color:var(--verde)">todos aprobados</b>' : '<b style="color:var(--rojo)">hay fallos</b>') . '</div></div>';
};
?>
<div class="cabecera">
  <div>
    <div class="miga">Evidencia del Hito 3 · generado <?= e($res['generado']) ?> · PHP <?= e($res['entorno']['php']) ?> · <?= e($res['entorno']['bd']) ?></div>
    <h1>Pruebas, métricas y línea base</h1>
    <p>Resultados producidos por <code>php tests/pruebas.php</code> sobre la base de datos real del sistema. Se regeneran en cada ejecución.</p>
  </div>
</div>

<?php
$suites = [$m, $f, $ia['validacion_llm']];
foreach (['telegram', 'accesos'] as $k) {
    if (!empty($extra[$k])) {
        $suites[] = $extra[$k];
    }
}
$totAp = array_sum(array_map(fn($x) => (int)$x['aprobados'], $suites));
$tot = array_sum(array_map(fn($x) => (int)$x['total'], $suites));
?>
<div class="aviso <?= $totAp === $tot ? 'ok' : 'error' ?>"><span><b><?= $totAp ?>/<?= $tot ?> pruebas automatizadas aprobadas</b> · sistema (<code>tests/pruebas.php</code>), bot de Telegram (<code>tests/pruebas_telegram.php</code>) y control de acceso (<code>tests/pruebas_accesos.php</code>).</span></div>
<section class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <?= $tarjeta('Casos del motor (T1–T8 y más)', $m) ?>
  <?= $tarjeta('Flujo extremo a extremo (BD)', $f) ?>
  <?= $tarjeta('Validación de salidas LLM', $ia['validacion_llm']) ?>
  <?= !empty($extra['telegram']) ? $tarjeta('Bot de Telegram (cliente simulado)', $extra['telegram']) : '' ?>
  <?= !empty($extra['accesos']) ? $tarjeta('Control de acceso y registro', $extra['accesos']) : '' ?>
  <div class="kpi"><div class="eti">Rendimiento del motor</div><div class="val"><?= num($res['rendimiento']['ms'], 0) ?><small> ms</small></div><div class="det"><?= num($res['rendimiento']['actividades']) ?> actividades encadenadas</div></div>
</section>

<div class="rejilla g2">
  <section class="panel">
    <header><h2>Línea base: ¿quién avisa antes?</h2><span class="suave">back-test semana 46</span></header>
    <?php if (!$lb): ?><p class="suave">Sin datos de la obra Claveles.</p><?php else: ?>
    <p class="suave" style="margin-top:-4px;font-size:13px"><?= e($lb['descripcion']) ?></p>
    <div class="tabla-env"><table>
      <thead><tr><th>Método (al corte 07/11)</th><th class="num">Marca</th><th class="num">Acierta</th><th class="num">Falsas alarmas</th><th class="num">Se le escapan</th><th class="num">Sensibilidad</th></tr></thead>
      <tbody><?php foreach ($lb['metodos'] as $i => $mm): $r = $mm['r']; ?>
        <tr><td><?= $i ? '<b>' . e($mm['nombre']) . '</b>' : e($mm['nombre']) ?><br><small class="suave mono"><?= e(implode(', ', $r['marcadas']) ?: '—') ?></small></td>
          <td class="num"><?= (int)$r['detecta'] ?></td><td class="num"><?= (int)$r['vp'] ?></td><td class="num"><?= (int)$r['fp'] ?></td><td class="num"><?= (int)$r['fn'] ?></td><td class="num"><b><?= num($r['recall'] ?? 0) ?>%</b></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <p class="suave" style="font-size:12.5px">Terminaron tarde o no terminaron (<?= count($lb['tarde_real']) ?> de <?= (int)$lb['evaluadas'] ?>): <span class="mono"><?= e(implode(', ', $lb['tarde_real'])) ?></span>. <?= e($lb['lectura']) ?></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <header><h2>IA de causas · clasificador local</h2><span class="etq ia">sin internet</span></header>
    <div class="tabla-env"><table>
      <thead><tr><th>Conjunto</th><th class="num">Notas</th><th class="num">Aceptadas</th><th class="num">Correctas</th><th class="num">Precisión</th><th class="num">Cobertura</th><th class="num">Abstenciones</th></tr></thead>
      <tbody><?php foreach (['eval' => 'Evaluación (usada para ajustar)', 'ciega' => 'Ciega (no usada para ajustar)'] as $kk => $t): $x = $ia['local'][$kk]; ?>
        <tr><td><?= e($t) ?></td><td class="num"><?= (int)$x['notas_con_causa'] ?></td><td class="num"><?= (int)$x['aceptadas'] ?></td><td class="num"><?= (int)$x['correctas'] ?></td>
          <td class="num"><b><?= num($x['precision'], 1) ?>%</b></td><td class="num"><?= num($x['cobertura'], 1) ?>%</td><td class="num"><?= (int)$x['abstenciones'] ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <p class="suave" style="font-size:12.5px"><?= e($ia['lectura']) ?></p>
  </section>
</div>

<section class="panel mt">
  <header><h2>Casos del motor</h2><span class="suave">criterios de aceptación del Hito 2 (T1–T8) y casos adicionales</span></header>
  <div class="tabla-env"><table><thead><tr><th>ID</th><th>Caso</th><th>Esperado</th><th>Obtenido</th><th>Resultado</th></tr></thead><tbody>
  <?php foreach ($m['casos'] as $c): ?><tr><td class="mono"><?= e($c['id']) ?></td><td><?= e($c['nombre']) ?></td><td><small><?= e($c['esperado']) ?></small></td><td><small><?= e($c['obtenido']) ?></small></td><td><?= $c['ok'] ? '<span class="chip chip-verde"><i></i>OK</span>' : '<span class="chip chip-rojo"><i></i>Falla</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel">
  <header><h2>Flujo extremo a extremo sobre la base de datos</h2></header>
  <div class="tabla-env"><table><thead><tr><th>Paso</th><th>Verificación</th><th>Resultado</th></tr></thead><tbody>
  <?php foreach ($f['casos'] as $c): ?><tr><td class="mono"><?= e($c['id']) ?></td><td><?= e($c['nombre']) ?><br><small class="suave"><?= e($c['obtenido']) ?></small></td><td><?= $c['ok'] ? '<span class="chip chip-verde"><i></i>OK</span>' : '<span class="chip chip-rojo"><i></i>Falla</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel">
  <header><h2>Detalle de la evaluación ciega</h2><span class="suave">cada fila es una nota de campo sintética</span></header>
  <div class="tabla-env"><table><thead><tr><th>Nota</th><th>Esperado</th><th>Modelo</th><th class="num">Puntaje</th><th>Resultado</th></tr></thead><tbody>
  <?php foreach ($ia['local']['ciega']['detalle'] as $d): ?>
    <tr><td><?= e($d['nota']) ?></td><td><?= e(ServicioIA::CATEGORIAS[$d['esperado']] ?? $d['esperado']) ?></td><td><?= e(ServicioIA::CATEGORIAS[$d['obtenido']] ?? $d['obtenido']) ?><?= $d['obtenido'] === 'sin_dato' && $d['candidata'] ? '<br><small class="suave">candidata: ' . e($d['candidata']) . '</small>' : '' ?></td>
      <td class="num"><?= num($d['puntaje'], 2) ?></td><td><?= $d['obtenido'] === $d['esperado'] ? '<span class="chip chip-verde"><i></i>correcta</span>' : ($d['obtenido'] === 'sin_dato' ? '<span class="chip chip-gris"><i></i>se abstuvo → humano</span>' : '<span class="chip chip-rojo"><i></i>error</span>') ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
