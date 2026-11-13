// Filtros del tablero (cliente) — no envían datos a ningún servidor externo.
document.querySelectorAll('[data-filtros]').forEach(function (caja) {
  var tabla = document.querySelector(caja.dataset.filtros);
  if (!tabla) return;
  var filas = Array.prototype.slice.call(tabla.tBodies[0].rows);
  var contador = caja.querySelector('[data-contador]');
  var clave = 'alertaplan-filtros';
  function valores() {
    var v = {};
    caja.querySelectorAll('[data-f]').forEach(function (el) { v[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value; });
    return v;
  }
  function aplicar() {
    var v = valores(), n = 0, t = (v.texto || '').toLowerCase().trim();
    filas.forEach(function (tr) {
      var ok = (!v.estado || tr.dataset.estado === v.estado) && (!v.sector || tr.dataset.sector === v.sector) &&
        (!v.resp || tr.dataset.resp === v.resp) && (!v.comp || tr.dataset.comp === '1') && (!t || tr.dataset.texto.indexOf(t) >= 0);
      tr.hidden = !ok; if (ok) n++;
    });
    if (contador) contador.textContent = n + ' de ' + filas.length + ' actividades';
    try { sessionStorage.setItem(clave, JSON.stringify(v)); } catch (e) {}
  }
  try {
    var g = JSON.parse(sessionStorage.getItem(clave) || '{}');
    caja.querySelectorAll('[data-f]').forEach(function (el) { if (g[el.dataset.f] !== undefined) { if (el.type === 'checkbox') el.checked = g[el.dataset.f]; else el.value = g[el.dataset.f]; } });
  } catch (e) {}
  caja.addEventListener('input', aplicar);
  caja.addEventListener('change', aplicar);
  aplicar();
});
// Confirmación antes de aprobar
document.querySelectorAll('input[name=accion][value=aprobar]').forEach(function (i) {
  i.form.addEventListener('submit', function (ev) { if (!confirm('¿Aprobar el corte? Quedará congelado y visible para Gerencia.')) ev.preventDefault(); });
});
