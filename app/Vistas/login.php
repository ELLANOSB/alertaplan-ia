<?php
use App\Web;
use function App\e;
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $modo === 'login' ? 'Ingresar' : 'Solicitar acceso' ?> · AlertaPlan IA</title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=3">
<style>
.login{min-height:100vh;display:grid;grid-template-columns:1.1fr 1fr}
.login .arte{background:radial-gradient(1200px 600px at -10% -20%,#5b2bc4 0,#380489 35%,#120d22 75%);color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
.login .arte img{width:190px}
.login .arte h1{font-size:44px;letter-spacing:-.02em;margin-top:40px}
.login .arte h1 span{color:#c4adff}
.login .arte p{color:#ddd3fb;max-width:520px;font-size:16px}
.login .arte ul{list-style:none;padding:0;margin:26px 0 0;display:grid;gap:10px;color:#e8e1ff}
.login .arte li::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffb020;margin-right:10px}
.login .arte .rejilla-fondo{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.login .form{display:flex;align-items:center;justify-content:center;padding:40px}
.login form{width:100%;max-width:380px}
.login input{width:100%}
.login select{width:100%}
.dos{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.nota-acceso{margin-top:22px;border-top:1px solid var(--borde);padding-top:16px}
.nota-acceso p{margin:6px 0 10px;color:var(--texto-2,#555);font-size:14px}
@media(max-width:520px){.dos{grid-template-columns:1fr}}
@media(max-width:900px){.login{grid-template-columns:1fr}.login .arte{padding:32px 24px}.login .arte h1{font-size:32px}}
</style></head>
<body>
<div class="login">
  <section class="arte">
    <div class="rejilla-fondo"></div>
    <div style="position:relative">
      <img src="assets/img/aecode_light.png" alt="AECODE">
      <h1>AlertaPlan <span>IA</span></h1>
      <p>Seguimiento diario y semanal de obra: avance contra lo programado, PPC, alertas tempranas con fecha probable y causas de incumplimiento propuestas por IA, siempre aprobadas por una persona.</p>
      <ul>
        <li>Carga Excel/CSV con validación previa</li>
        <li>Motor de reglas explicable: cada rojo muestra su cálculo</li>
        <li>IA con cita literal, confianza y modo manual de respaldo</li>
        <li>Informe semanal aprobado y vista de Gerencia de solo lectura</li>
      </ul>
    </div>
    <small style="position:relative;color:#bfb3e6">Proyecto final · Diplomado Internacional de IA aplicada a Ingeniería y Construcción · Ed. 03 · Eje Desarrollo · Hito 3</small>
  </section>
  <section class="form">
    <?php if ($modo === 'login'): ?>
    <form method="post" action="<?= Web::url('login') ?>" autocomplete="on">
      <?= Web::campoCsrf() ?>
      <h2 style="font-size:24px">Ingresar</h2>
      <p class="suave">Acceso solo para el equipo autorizado de la obra.</p>
      <?php foreach ($mensajes as [$t, $m]): ?><div class="aviso <?= $t === 'ok' ? 'ok' : 'error' ?>"><?= e($m) ?></div><?php endforeach; ?>
      <div class="campo"><label for="email">Correo</label><input id="email" name="email" type="email" required maxlength="160" autocomplete="username" value="<?= e($previo['email'] ?? '') ?>"></div>
      <div class="campo"><label for="clave">Contraseña</label><input id="clave" name="clave" type="password" required maxlength="200" autocomplete="current-password"></div>
      <button class="primario" style="width:100%;justify-content:center;padding:11px">Ingresar</button>
      <div class="nota-acceso">
        <b>¿Eres parte del equipo y aún no tienes cuenta?</b>
        <p>Pide a Gerencia el <b>código de equipo</b> y envía tu solicitud. Tendrás acceso cuando Gerencia la apruebe.</p>
        <a class="boton" href="<?= Web::url('registro') ?>">Solicitar acceso</a>
      </div>
      <p class="suave" style="font-size:12px;margin-top:18px">Tras <?= App\Accesos::MAX_FALLOS_CUENTA ?> intentos fallidos la cuenta se bloquea <?= App\Accesos::MIN_BLOQUEO ?> minutos. Todos los ingresos quedan en la auditoría.</p>
    </form>
    <?php elseif ($modo === 'registro'): ?>
    <form method="post" action="<?= Web::url('registro') ?>" autocomplete="off" style="max-width:460px">
      <?= Web::campoCsrf() ?>
      <h2 style="font-size:24px">Solicitar acceso</h2>
      <p class="suave">Tu cuenta quedará <b>pendiente</b> hasta que Gerencia la apruebe y confirme tu rol.</p>
      <?php foreach ($mensajes as [$t, $m]): ?><div class="aviso error"><?= e($m) ?></div><?php endforeach; ?>
      <div class="campo"><label for="codigo">Código de equipo <small class="suave">(lo entrega Gerencia)</small></label><input id="codigo" name="codigo" required maxlength="20" placeholder="EQ-XXXX-XXXX" style="text-transform:uppercase;letter-spacing:.08em"></div>
      <div class="campo"><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" required maxlength="120" value="<?= e($previo['nombre'] ?? '') ?>"></div>
      <div class="dos">
        <div class="campo"><label for="email">Correo</label><input id="email" name="email" type="email" required maxlength="160" autocomplete="email" value="<?= e($previo['email'] ?? '') ?>"></div>
        <div class="campo"><label for="cargo">Cargo</label><input id="cargo" name="cargo" required maxlength="120" placeholder="Ej. Asistente de producción" value="<?= e($previo['cargo'] ?? '') ?>"></div>
      </div>
      <div class="campo"><label for="rol">Rol que solicitas</label><select id="rol" name="rol" required style="width:100%">
        <?php foreach ([['produccion', 'Producción · registra partes diarios'], ['planeamiento', 'Planeamiento · carga, revisa causas y aprueba'], ['gerencia', 'Gerencia · lectura de informes y aprobación de accesos']] as [$v, $t]): ?>
          <option value="<?= $v ?>" <?= ($previo['rol'] ?? 'produccion') === $v ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?></select></div>
      <div class="campo"><label for="motivo">¿Para qué necesitas el acceso? <small class="suave">(opcional)</small></label><input id="motivo" name="motivo" maxlength="300" value="<?= e($previo['motivo'] ?? '') ?>"></div>
      <div class="dos">
        <div class="campo"><label for="clave">Contraseña</label><input id="clave" name="clave" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></div>
        <div class="campo"><label for="clave2">Repite la contraseña</label><input id="clave2" name="clave2" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></div>
      </div>
      <p class="suave" style="font-size:12px;margin-top:-4px">Mínimo 10 caracteres, con letras y números. No uses tu correo.</p>
      <button class="primario" style="width:100%;justify-content:center;padding:11px">Enviar solicitud</button>
      <p style="margin-top:14px"><a href="<?= Web::url('login') ?>">← Volver a ingresar</a></p>
    </form>
    <?php else: ?>
    <div style="max-width:420px">
      <h2 style="font-size:24px">Solicitud enviada</h2>
      <div class="aviso ok">Si el código de equipo y los datos son válidos, tu solicitud quedó registrada.</div>
      <p>Gerencia revisará que pertenezcas al equipo y asignará tu rol. Podrás ingresar con tu correo y contraseña en cuanto sea aprobada.</p>
      <a class="boton primario" href="<?= Web::url('login') ?>">Ir a ingresar</a>
    </div>
    <?php endif; ?>
  </section>
</div>
</body></html>
