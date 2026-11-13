<?php
use App\Web;
use function App\e;

$titulo = 'Accesos del equipo';
$estado = ['activo' => ['verde', 'Activo'], 'suspendido' => ['naranja', 'Suspendido'], 'rechazado' => ['gris', 'Rechazado']];
?>
<div class="cabecera"><div><div class="miga">Seguridad · solo Gerencia</div><h1>Accesos del equipo</h1>
<p>Nadie entra al sistema sin tu aprobación. Para registrarse, la persona necesita un <b>código de equipo</b> que generas aquí; su cuenta queda pendiente hasta que la apruebes y le asignes un rol. Cada decisión queda en la auditoría.</p></div></div>

<div class="rejilla g2">
  <section class="panel">
    <header><h2>Código de equipo</h2><span class="suave">se guarda cifrado (hash)</span></header>
    <?php if ($codigoNuevo): ?>
      <p>Comparte este código solo con integrantes del equipo (en persona o por un canal interno). <b>No se volverá a mostrar.</b></p>
      <div class="formula" style="font-size:24px;text-align:center;letter-spacing:.12em"><?= e($codigoNuevo) ?></div>
      <p class="suave">Con el código, la persona entra a <b>Solicitar acceso</b> desde la pantalla de ingreso.</p>
    <?php else: ?>
      <p>Genera un código para que nuevos integrantes puedan enviar su solicitud.</p>
    <?php endif; ?>
    <form method="post" class="campos" style="align-items:end"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="codigo">
      <div><label>Vigencia</label><select name="dias"><option value="1">1 día</option><option value="3">3 días</option><option value="7" selected>7 días</option><option value="14">14 días</option></select></div>
      <div><label>Usos máximos</label><select name="usos"><option>1</option><option>3</option><option>5</option><option selected>10</option><option>20</option></select></div>
      <div><button class="primario">Generar código</button></div>
    </form>
    <?php if ($invitaciones): ?>
    <div class="tabla-env mt"><table><thead><tr><th>Código</th><th>Creado</th><th>Vence</th><th class="num">Usos</th><th></th></tr></thead><tbody>
    <?php foreach ($invitaciones as $i): ?>
      <tr><td class="mono">EQ-····-<?= e($i['pista']) ?></td><td><small><?= e($i['creado_en']) ?><br><?= e($i['creador']) ?></small></td><td><small><?= e($i['vence_en']) ?></small></td>
        <td class="num"><?= (int)$i['usos'] ?>/<?= (int)$i['max_usos'] ?></td>
        <td><?php if ($i['vigente']): ?><form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="revocar"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="chico peligro">Revocar</button></form>
          <?php else: ?><span class="chip chip-gris"><i></i><?= $i['activo'] ? 'vencido' : 'revocado' ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <header><h2>Solicitudes pendientes</h2><span class="etq <?= $pendientes ? 'ia' : '' ?>"><?= count($pendientes) ?></span></header>
    <?php if (!$pendientes): ?><p class="suave">No hay solicitudes por revisar.</p><?php endif; ?>
    <?php foreach ($pendientes as $p): ?>
      <div class="tarjeta-causa">
        <div><b><?= e($p['nombre']) ?></b> · <?= e($p['cargo']) ?><br><small class="mono"><?= e($p['email']) ?></small></div>
        <p class="suave" style="margin:6px 0">Solicita: <b><?= e(Web::ROLES[$p['rol_solicitado']] ?? $p['rol_solicitado']) ?></b> · <?= e($p['creado_en']) ?> · código EQ-····-<?= e($p['pista'] ?? '—') ?>
          <?= $p['motivo_solicitud'] ? '<br>«' . e($p['motivo_solicitud']) . '»' : '' ?></p>
        <form method="post" class="campos" style="align-items:end"><?= Web::campoCsrf() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div><label>Rol asignado</label><select name="rol"><?php foreach (Web::ROLES as $k => $t): ?><option value="<?= $k ?>" <?= $k === $p['rol_solicitado'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
          <div><label>Comentario <small class="suave">(obligatorio si rechazas)</small></label><input name="comentario" maxlength="300"></div>
          <div style="display:flex;gap:8px"><button class="primario" name="accion" value="aprobar">Aprobar</button><button class="peligro" name="accion" value="rechazar">Rechazar</button></div>
        </form>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<section class="panel mt">
  <header><h2>Integrantes</h2><span class="suave">una cuenta suspendida pierde de inmediato el acceso web y el bot de Telegram</span></header>
  <div class="tabla-env"><table><thead><tr><th>Persona</th><th>Rol</th><th>Estado</th><th>Último ingreso</th><th class="num">Telegram</th><th>Revisión</th><th></th></tr></thead><tbody>
  <?php foreach ($equipo as $m): [$col, $txt] = $estado[$m['estado']] ?? ['gris', $m['estado']]; ?>
    <tr><td><b><?= e($m['nombre']) ?></b><br><small class="suave"><?= e($m['cargo']) ?> · <span class="mono"><?= e($m['email']) ?></span></small></td>
      <td><?= e(Web::ROLES[$m['rol']] ?? $m['rol']) ?></td>
      <td><span class="chip chip-<?= $col ?>"><i></i><?= e($txt) ?></span></td>
      <td><small><?= e($m['ultimo_ingreso'] ?? '—') ?></small></td>
      <td class="num"><?= (int)$m['chats'] ?></td>
      <td><small><?= $m['revisor'] ? e($m['revisor']) . ' · ' . e($m['revisado_en']) : '<span class="suave">cuenta inicial</span>' ?><?= $m['comentario_revision'] ? '<br>«' . e($m['comentario_revision']) . '»' : '' ?></small></td>
      <td><?php if ((int)$m['id'] === (int)$usuario['id']): ?><span class="suave">tú</span>
        <?php elseif ($m['estado'] === 'activo'): ?>
          <form method="post" style="display:flex;gap:6px"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="suspender"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input name="comentario" placeholder="Motivo" maxlength="300" required minlength="5" style="width:130px"><button class="chico peligro">Suspender</button></form>
        <?php elseif ($m['estado'] === 'suspendido'): ?>
          <form method="post"><?= Web::campoCsrf() ?><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="chico">Reactivar</button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
