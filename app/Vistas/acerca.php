<?php
use function App\e;

$titulo = 'Arquitectura e IA';
$comp = [
    ['C1', 'Interfaz web por rol', 'PHP 8.3 + HTML/CSS/JS sin dependencias externas', 'Tablero, detalle, carga, cierre, informe y vista de Gerencia. Planeamiento / Producción / Gerencia (solo lectura).', 'public/index.php, app/Vistas'],
    ['C2', 'Carga e importación', 'Lector CSV y XLSX propio (ZipArchive + SimpleXML)', 'Propone mapeo por alias, valida tipos y muestra el análisis antes de guardar.', 'app/Importador.php'],
    ['C3', 'Base de datos', 'MySQL 8.4 en FlyEnv (PDO, transacciones)', 'Obras, cortes, actividades, partes diarios, causas, auditoría. Línea base separada del fin vigente.', 'database/schema.sql, app/Repo.php'],
    ['C4', 'Motor determinístico', 'Reglas en PHP con calendario laboral y feriados', 'Restante, duración, fecha probable, margen, atraso, dependencias FC, semáforo y PPC.', 'app/Motor.php'],
    ['C5', 'Servicio de IA', 'Claude API (opcional) + Naive Bayes local', 'Propone causa con cita literal y confianza; validación automática; se abstiene si no hay evidencia.', 'app/IA/*'],
    ['C6', 'Revisión humana', 'Flujo de decisión con bloqueo de aprobación', 'Aceptar / editar / sin dato suficiente; comentario obligatorio al corregir; reprogramación con acción y responsable.', 'app/Repo.php (revisarCausa, aprobar)'],
    ['C7', 'Informe, historial y auditoría', 'Informe imprimible, CSV, snapshot JSON por corte', 'Informe aprobado e inmutable para Gerencia; tendencia de PPC; registro de cada acción.', 'app/Vistas/reporte.php, gerencia.php'],
];
$flujo = ['Planeamiento sube Excel/CSV', 'C2 propone mapeo y valida', 'Persona confirma la carga', 'C3 guarda el corte y la línea base', 'C4 calcula fechas, alertas y PPC', 'Producción registra partes diarios', 'C4 recalcula con cada parte', 'C5 propone causas con cita', 'C6 valida (esquema, cita, categoría)', 'Planeamiento acepta / edita / sin dato', 'Planeamiento reprograma y aprueba', 'C7 publica el informe a Gerencia'];
?>
<div class="cabecera"><div><div class="miga">Hito 3 · arquitectura final implementada</div><h1>Cómo funciona AlertaPlan IA</h1>
<p>Proyecto unificado del grupo: el seguimiento diario con PPC y causas por IA (propuesta Claveles) y la proyección de fechas con alertas tempranas (propuesta AlertaPlan) en un solo sistema.</p></div></div>

<section class="panel">
  <header><h2>Componentes</h2></header>
  <div class="tabla-env"><table><thead><tr><th>ID</th><th>Componente</th><th>Herramienta</th><th>Responsabilidad</th><th>Código</th></tr></thead><tbody>
  <?php foreach ($comp as $c): ?><tr><td><b><?= $c[0] ?></b></td><td><b><?= e($c[1]) ?></b></td><td><?= e($c[2]) ?></td><td><?= e($c[3]) ?></td><td class="mono"><small><?= e($c[4]) ?></small></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel">
  <header><h2>Flujo de información (12 pasos)</h2><span class="leyenda"><span class="etq humano">persona</span><span class="etq calculo">proceso</span><span class="etq ia">IA</span></span></header>
  <div class="flujo" style="grid-template-columns:repeat(6,minmax(0,1fr))">
    <?php foreach ($flujo as $i => $t): $cls = in_array($i, [0, 2, 5, 9, 10], true) ? 'humano' : ($i === 7 ? 'ia' : 'calculo'); ?>
      <div style="border-top:3px solid var(--<?= $cls === 'humano' ? 'naranja' : ($cls === 'ia' ? 'marca' : 'verde') ?>)"><b>PASO <?= $i + 1 ?></b><?= e($t) ?></div>
    <?php endforeach; ?>
  </div>
</section>

<div class="rejilla g2 mt">
  <section class="panel">
    <header><h2>Por qué IA aquí y reglas allá</h2></header>
    <ul style="margin:0;padding-left:18px">
      <li><b>Reglas</b> para todo lo que es aritmética verificable: fechas, márgenes, PPC, semáforo. Mismo dato → mismo resultado.</li>
      <li><b>IA</b> solo para lo que llega en texto libre: la observación de campo. Clasifica la causa (materiales, mano de obra, ingeniería, calidad, clima, equipos, predecesora).</li>
      <li>La IA <b>no</b> cambia fechas, colores ni PPC, y su propuesta no sale a Gerencia sin decisión humana.</li>
    </ul>
  </section>
  <section class="panel">
    <header><h2>Controles de la IA</h2></header>
    <ul style="margin:0;padding-left:18px">
      <li>Salida JSON con categoría permitida, cita literal, confianza y justificación.</li>
      <li>Validación automática: esquema, categoría, rango de confianza y que la cita exista en la nota.</li>
      <li>Si la API falla o no valida → clasificador local. Si este no alcanza 0,50 de puntaje y 25 % de cobertura → “sin dato suficiente”.</li>
      <li>Modo manual (<code>IA_MODO=manual</code>) para operar sin IA. Todo queda en auditoría.</li>
    </ul>
  </section>
</div>

<section class="panel mt">
  <header><h2>Limitaciones conocidas</h2></header>
  <ul style="margin:0;padding-left:18px" class="suave">
    <li>Datos de prueba ficticios; falta validar con planificación real anonimizada y con usuarios de obra.</li>
    <li>Dependencias solo fin-a-inicio sin desfase; no calcula ruta crítica contractual ni nivelación de recursos.</li>
    <li>Productividad constante (rendimiento declarado); no estima probabilidades de atraso.</li>
    <li>El clasificador local prioriza no equivocarse: en la prueba ciega se abstiene en muchas notas y las deriva a la persona.</li>
    <li>Autenticación local básica pensada para la demo; no hay SSO, cifrado de respaldo ni auditoría de seguridad de producción.</li>
  </ul>
</section>
