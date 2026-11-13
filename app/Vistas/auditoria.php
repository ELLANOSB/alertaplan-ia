<?php
use App\Web;
use function App\e;

$titulo = 'Auditoría';
?>
<div class="cabecera"><div><div class="miga">Trazabilidad</div><h1>Registro de auditoría</h1>
<p>Quién importó, registró, propuso (IA), decidió, reprogramó o aprobó. Últimos 300 eventos.</p></div></div>
<section class="panel"><div class="tabla-env"><table>
<thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($filas as $f): $d = $f['detalle'] ? json_decode($f['detalle'], true) : null; ?>
<tr><td class="mono" style="white-space:nowrap"><?= e($f['creado_en']) ?></td><td><?= e($f['nombre'] ?? 'sistema') ?><br><small><?= e($f['rol'] ? Web::ROLES[$f['rol']] : '') ?></small></td>
<td><span class="etq <?= str_starts_with($f['accion'], 'ia_') ? 'ia' : (in_array($f['accion'], ['revision_causa', 'aprobar_corte', 'reprogramar'], true) ? 'humano' : '') ?>"><?= e(str_replace('_', ' ', $f['accion'])) ?></span></td>
<td><?= e($f['entidad']) ?> #<?= (int)$f['entidad_id'] ?></td>
<td><small class="mono"><?= e(is_array($d) ? json_encode($d, JSON_UNESCAPED_UNICODE) : $f['detalle']) ?></small></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
