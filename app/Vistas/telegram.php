<?php
use App\Web;
use function App\e;

$titulo = 'Telegram';
?>
<div class="cabecera"><div><div class="miga">Canal móvil</div><h1>Bot de Telegram</h1>
<p>Consulta el estado de la obra, registra partes diarios y envía la planificación en Excel desde Telegram. La aprobación del corte sigue siendo solo en la web.</p></div></div>
<div class="rejilla g2">
  <section class="panel">
    <header><h2>Vincular mi cuenta</h2></header>
    <?php if ($codigo): ?>
      <p>Abre <b><?= $bot ? '<a href="https://t.me/' . e($bot) . '?start=' . e($codigo) . '" target="_blank">@' . e($bot) . '</a>' : 'el bot' ?></b> en Telegram y envía:</p>
      <div class="formula" style="font-size:22px;text-align:center;letter-spacing:.1em">/vincular <?= e($codigo) ?></div>
      <p class="suave">El código vence en 10 minutos y sirve una sola vez. El chat quedará con tu rol: <b><?= e(Web::ROLES[$usuario['rol']]) ?></b>.</p>
    <?php else: ?>
      <p>Genera un código de un solo uso y envíalo al bot<?= $bot ? ' <b>@' . e($bot) . '</b>' : '' ?>.</p>
    <?php endif; ?>
    <form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="codigo"><button class="primario">Generar código</button></form>
  </section>
  <section class="panel">
    <header><h2>Chats vinculados a mi usuario</h2></header>
    <?php if (!$chats): ?><p class="suave">Ninguno todavía.</p><?php else: ?>
    <div class="tabla-env"><table><thead><tr><th>Telegram</th><th>Vinculado</th><th></th></tr></thead><tbody>
    <?php foreach ($chats as $c): ?><tr><td><?= e($c['nombre_telegram']) ?><br><small class="mono"><?= e($c['chat_id']) ?></small></td><td><?= e($c['creado_en']) ?></td>
      <td><form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="chat" value="<?= e($c['chat_id']) ?>"><button class="chico peligro">Desvincular</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
</div>
<section class="panel mt">
  <header><h2>¿Cómo se suma otro integrante del equipo?</h2><span class="suave">cada persona vincula su propio Telegram</span></header>
  <ol class="pasos">
    <li><b>Tener cuenta aprobada.</b> Si aún no la tiene: en la pantalla de ingreso pulsa <i>Solicitar acceso</i> con el código de equipo que entrega Gerencia, y espera su aprobación.</li>
    <li><b>Ingresar a la web</b> con su propio correo y abrir <i>Bot de Telegram</i>.</li>
    <li><b>Generar código</b> (dura 10 minutos y sirve una sola vez).</li>
    <li>Abrir <?= $bot ? '<a href="https://t.me/' . e($bot) . '" target="_blank" rel="noopener"><b>@' . e($bot) . '</b></a>' : '<b>el bot</b>' ?> en Telegram y enviar <span class="mono">/vincular CÓDIGO</span>.</li>
  </ol>
  <p class="suave" style="font-size:13px">El bot responde con el rol de esa persona: Producción registra partes, Planeamiento además carga Excel, Gerencia solo ve informes aprobados. Los chats no vinculados no reciben datos, y si Gerencia suspende la cuenta el chat deja de funcionar de inmediato.</p>
</section>
<section class="panel mt">
  <header><h2>Comandos</h2></header>
  <div class="tabla-env"><table><thead><tr><th>Comando</th><th>Qué hace</th><th>Roles</th></tr></thead><tbody>
    <tr><td class="mono">/estado [obra]</td><td>PPC, avance programado vs ejecutado, conteo por estado y prioridades</td><td>todos</td></tr>
    <tr><td class="mono">/atrasos [obra]</td><td>Actividades en rojo o bloqueadas con motivo y causa</td><td>todos</td></tr>
    <tr><td class="mono">/actividad C03</td><td>Cálculo explicable, restricción, causa IA y decisión humana</td><td>todos</td></tr>
    <tr><td class="mono">/causas</td><td>Propuestas de la IA y su revisión</td><td>todos</td></tr>
    <tr><td class="mono">/informe</td><td>Informe semanal como archivo (Gerencia: solo aprobados)</td><td>todos</td></tr>
    <tr><td class="mono">/parte C03 1.5 [dd/mm] nota</td><td>Registra el avance del día y recalcula</td><td>Producción, Planeamiento</td></tr>
    <tr><td class="mono">archivo + /cargar CLV 47 14/11/2026 21/11/2026 [datos]</td><td>Valida el Excel/CSV, muestra vista previa y pide confirmar</td><td>Planeamiento</td></tr>
    <tr><td class="mono">texto libre</td><td>Pregunta abierta: con API de Claude responde con los datos del sistema; sin ella, deriva al comando adecuado</td><td>todos</td></tr>
  </tbody></table></div>
</section>
