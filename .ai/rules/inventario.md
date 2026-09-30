---
paths:
  - 'app/Acciones/RedistribuirCustodia.php,app/Enums/TipoMovimiento.php,app/Enums/DireccionMovimiento.php,app/Servicios/ServicioInventario.php,app/Http/Controllers/MovimientoInventarioController.php,resources/js/pages/Inventario/Movimientos.vue'
---

# Inventario

## Redistribución de custodia en Movimientos: tipo RedistribucionCustodia, dirección SinEfecto (delta 0)
Cada renglón creado por `RedistribuirCustodia` registra `ServicioInventario::registrarMovimientoCustodia()`: `tipo=redistribucion_custodia`, `direccion=sin_efecto` (signo 0), `almacen_id` NULL, existencia 0→0, referencia = `DetalleEntrega` nuevo (fuente de verdad de origen/destino/folio/finalidades; no se duplican columnas). Nunca toca `saldos_inventario`; `registrarMovimiento`/`registrarMovimientoUnidad` rechazan tipos sin efecto. Listado/detalle exponen `afecta_stock` + `custodia{origen,destino,folio,finalidad_origen,finalidad_destino}` (lote, sin N+1); UI oculta Antes/Después. La ficha de unidad EXCLUYE este tipo porque ya reconstruye redistribuciones desde `DetalleEntrega` (no duplicar). Agregados de stock (dashboard) sólo leen entrada/salida. Redistribuciones previas a este cambio no tienen evento (sin backfill).
