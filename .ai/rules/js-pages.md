---
paths:
  - 'app/Servicios/ServicioDashboard.php,app/Http/Controllers/PanelController.php,app/Enums/SeccionDashboard.php,resources/js/pages/Panel.vue'
---

# Js Pages

## Dashboard por permisos efectivos (SeccionDashboard)
El Dashboard se arma por PERMISOS EFECTIVOS, nunca por rol. `App\Enums\SeccionDashboard::autorizada()` es la ÚNICA fuente: cada sección usa exactamente la ability del `index()` de su módulo destino (Policy `viewAny`, o `inventario.ver` directo), así una card visible nunca da 403. No inferir entre permisos relacionados (`inventario.ajustar`/`activos.ver`/`inventario-fisico.ver`/`almacenes.ver` NO implican `inventario.ver`). `ServicioDashboard::resumen($secciones, …)` sólo CONSULTA y devuelve las claves de las secciones recibidas (+ `resumen.secciones`); `Panel.vue` pinta por presencia del dato, sin mapa de permisos propio. Nuevo KPI/gráfica = agregarlo dentro del `if` de su sección + declarar sus filtros en `usaSucursal/usaAlmacen/usaRangoFechas`. Filtros sucursal/almacén sólo si una sección visible los usa Y el usuario puede `viewAny` Sucursal/Almacén (sus buscadores lo exigen); si no, el parámetro se ignora. Tests: `DashboardPermisosEfectivosTest.php`.
