---
paths:
    - 'app/Acciones/AjustarMinimoInventario.php,app/Http/Controllers/InventarioController.php,app/Servicios/ServicioInventario.php'
---

# Controllers Servicios

## Cambiar mínimo audita en Auditoría, nunca genera MovimientoInventario

`POST /inventario/minimos` (mínimo individual) pasa por `App\Acciones\AjustarMinimoInventario::ejecutar()` (mismo patrón que `AjustarInventario`: `DB::transaction`, `ServicioAuditoria` inyectada). Lee el mínimo anterior con `ServicioInventario::minimoActual()` (nuevo, simétrico a `saldoActual()`) ANTES de llamar a `ServicioInventario::ajustarMinimo()`, y sólo registra en `ServicioAuditoria::registrar('inventario', 'actualizar_minimo', …)` si `$minimoAnterior !== $minimoNuevo` — nunca una entrada "sin cambio real". `tipo_entidad`/`entidad_id` apuntan al `SaldoInventario` (empresa+almacén+activo+talla ya quedan trazables por esa fila, no se duplican como columnas nuevas en la bitácora); `descripcion` es texto legible: `"Se actualizó el mínimo de {activo}[ · {talla}] en {almacén} de {anterior} a {nuevo}."`.

Mínimo NUNCA toca `cantidad`, nunca crea `MovimientoInventario` — es una regla operativa (alerta), no un movimiento de stock. `InventarioController::aplicarMinimoMasivo()` (mínimo masivo, "aplicar a todas las variantes/almacén") queda **fuera de este patrón** a propósito — no se auditó en esta ronda porque el pedido y los tests eran específicos del flujo individual; si se pide extenderlo, aplicar la misma idea pero pensar el formato de auditoría para N filas a la vez (no hay "un" anterior/nuevo).
