---
paths:
    - 'app/Models/Conjunto.php,app/Acciones/CrearEntregaUniforme.php,app/Http/Requests/Entregas/GuardarEntregaRequest.php'
---

# Entregas

## Disponibilidad de entrega debe excluir Activo inactivo, no sólo stock=0

Un `Activo` desactivado (`activos.activo = false`) NUNCA es entregable aunque tenga saldo>0 — ni suelto ni como componente de un Conjunto. `Conjunto::disponibilidad()` retorna 0 para cualquier componente cuyo `activo->activo` sea false (única fuente, reusada por `GuardarEntregaRequest` y `CrearEntregaUniforme::expandirConjunto`). Los activos sueltos se filtran con `->where('activo', true)` tanto en el `Rule::exists('activos', ...)` del Request como en la consulta de `CrearEntregaUniforme::registrarComponenteCantidad` (defensa en capas, igual criterio que el resto del dominio de inventario).
