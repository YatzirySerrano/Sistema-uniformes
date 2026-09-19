---
paths:
  - 'app/Models/MovimientoInventario.php,app/Http/Controllers/MovimientoInventarioController.php,app/Acciones/{MarcarCondicionInventario,RestaurarCondicionInventario,MarcarUnidadIncidencia,DarDeBajaUnidadActivo,RecuperarUnidadActivo}.php'
---

# Controllers Acciones

## TipoMovimiento::{Incidencia,Baja,Recuperacion} son compartidos entre dominios — la etiqueta nunca es el enum directo
Bug real corregido (QA 2026-09): marcar un activo POR CANTIDAD como "Dañado" (`MarcarCondicionInventario`) reutiliza `TipoMovimiento::Incidencia` — el MISMO caso que usa `MarcarUnidadIncidencia` para una pérdida/robo REAL de una `UnidadActivo` — así que se mostraba como "Pérdida / robo". Mismo patrón para `Baja` (`DarDeBajaUnidadActivo` vs. baja de condición de inventario) y `Recuperacion` (`RecuperarUnidadActivo` vs. restaurar Dañado→Disponible).

Regla: nunca uses `$movimiento->tipo->etiqueta()` directamente para mostrar al usuario un movimiento con `tipo` en {Incidencia, Baja, Recuperacion}. Usa siempre `MovimientoInventario::etiquetaEfectiva()`, que distingue ambos dominios de forma ESTRUCTURAL comprobando si existe una fila en `condiciones_inventario` enlazada al movimiento (`condicionInventario()` hasOne) — nunca inspeccionando `motivo` (texto libre). Si añades un nuevo caso que reutilice uno de estos tres `TipoMovimiento` para un dominio distinto, actualiza `etiquetaEfectiva()` en vez de crear otro `TipoMovimiento` o parchear la etiqueta en el controller. Eager-load `condicionInventario` junto con el resto de relaciones del movimiento para evitar N+1 (ver `consultaMovimientos()`).
