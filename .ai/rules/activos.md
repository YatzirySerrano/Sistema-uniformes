---
paths:
  - 'app/Servicios/ServicioDistribucionActivo.php,app/Servicios/ServicioEstadoInventario.php,resources/js/components/sistema/DistribucionActualActivo.vue,resources/js/pages/Activos/Detalle.vue'
---

# Activos

## Distribución actual del activo = custodia por renglón, sin duplicar redistribuciones
El detalle de Activo muestra "Distribución actual del activo" (`ServicioDistribucionActivo::paraActivo`): en almacén (SaldoInventario) + custodia por custodio+variante+finalidad, calculada con `ServicioCustodiaColaborador::pendientesPorDetalle()` (nunca re-sumes entregas a mano). Grupos: almacen / uso_personal / redistribucion / sin_clasificar, nunca mezclados. `ServicioEstadoInventario` "Asignado" excluye entregas con `colaborador_origen_id` (una redistribución sólo cambia de custodio; contarla duplicaba piezas en una fila de almacén "—"). La tarjeta Asignado ya no desglosa por almacén: remite a esa sección.
