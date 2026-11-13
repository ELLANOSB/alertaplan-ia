<?php
use App\Web;
use function App\e;
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingresar · AlertaPlan IA</title>
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
.demo{margin-top:22px;border-top:1px solid var(--borde);padding-top:16px}
.demo button{width:100%;justify-content:space-between;margin-bottom:8px;font-weight:500}
.demo button b{font-weight:700}
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
    <form method="post" action="<?= Web::url('login') ?>">
      <?= Web::campoCsrf() ?>
      <h2 style="font-size:24px">Ingresar</h2>
      <p class="suave">Obra piloto: Edificio Multifamiliar Claveles (datos ficticios).</p>
      <?php foreach ($mensajes as [$t, $m]): ?><div class="aviso error"><?= e($m) ?></div><?php endforeach; ?>
      <div class="campo"><label for="email">Correo</label><input id="email" name="email" type="email" required autocomplete="username"></div>
      <div class="campo"><label for="clave">Contraseña</label><input id="clave" name="clave" type="password" required autocomplete="current-password"></div>
      <button class="primario" style="width:100%;justify-content:center;padding:11px">Ingresar</button>
      <div class="demo">
        <small class="suave">Usuarios de demostración (contraseña <code>Aecode2026!</code>):</small>
        <?php foreach ([['planeamiento@claveles.demo', 'Planeamiento', 'carga, revisa y aprueba'], ['produccion@claveles.demo', 'Producción', 'registra partes diarios'], ['gerencia@claveles.demo', 'Gerencia', 'solo lectura']] as [$m, $r, $d]): ?>
          <button type="button" onclick="email.value='<?= $m ?>';clave.value='Aecode2026!';clave.focus()"><span><b><?= $r ?></b> · <?= $d ?></span><small class="suave"><?= $m ?></small></button>
        <?php endforeach; ?>
      </div>
    </form>
  </section>
</div>
</body></html>
