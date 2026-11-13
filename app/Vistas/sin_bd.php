<!doctype html><html lang="es"><head><meta charset="utf-8"><title>AlertaPlan IA · base de datos</title><link rel="stylesheet" href="assets/app.css"></head>
<body><div class="contenido" style="max-width:720px;margin:60px auto"><img src="assets/img/aecode_dark.png" alt="AECODE" style="height:34px">
<div class="panel mt"><h1>No se pudo conectar a MySQL</h1>
<p>Revisa en FlyEnv que el servicio <b>MySQL</b> esté encendido y que el archivo <code>.env</code> tenga los datos correctos. Luego ejecuta <code>./instalar.sh</code> si aún no creaste la base.</p>
<pre class="formula"><?= htmlspecialchars($error ?? '', ENT_QUOTES) ?></pre></div></div></body></html>
