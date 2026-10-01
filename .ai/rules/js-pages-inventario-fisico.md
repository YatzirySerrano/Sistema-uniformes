---
paths:
  - 'app/Acciones/CrearRondaInventarioFisico.php,app/Acciones/AplicarCorreccionesInventarioFisico.php,app/Servicios/ServicioResumenInventarioFisico.php,app/Models/InventarioFisicoExistencia.php,resources/js/pages/InventarioFisico/**'
---

# Js Pages Inventario Fisico

## Inventario físico: custodia por cantidad = renglón propio, nunca corrige stock
Reemplaza "No ampliar a custodios" de inventario-fisico.md. La ronda integral también congela la custodia POR CANTIDAD en `inventario_fisico_existencias` (`colaborador_id` + `finalidad`, `almacen_id` NULL; migración aditiva 2026_10_01_194759): un renglón por custodio+activo+talla+finalidad (NULL = Sin clasificar), de `ServicioCustodiaColaborador::bolsasCantidadDeEmpresa()` (mismo pendiente que el resto). Origen = `colaborador_id` (`esCustodia()`, `scopeDeOrigen`). Se cuenta con el mismo flujo/409 que almacén. `AplicarCorreccionesInventarioFisico` usa SÓLO origen almacén; la diferencia de custodia queda registrada (`correcciones.diferencias_custodia`) y nunca mueve stock, custodia ni finalidad. `resumenCorrecciones.estado/total_diferencias` = sólo almacén.
