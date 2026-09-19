---
paths:
  - 'app/Enums/CondicionDevolucion.php,app/Http/Controllers/DevolucionController.php,app/Http/Requests/Devoluciones/GuardarDevolucionRequest.php,app/Acciones/MarcarCondicionInventario.php'
---

# Devoluciones Acciones

## CondicionDevolucion::RoboExtravio sólo existe para condición de inventario, nunca para una devolución
`CondicionDevolucion` tiene 4 casos: `Reutilizable` (sólo devoluciones), `Danado`/`Baja` (compartidos entre devoluciones y `MarcarCondicionInventario`) y `RoboExtravio` (SÓLO `MarcarCondicionInventario`, marcado directo desde el stock disponible de un almacén — nunca se "devuelve" algo reportado como robado o extraviado).

Cualquier código nuevo que enumere `CondicionDevolucion::cases()` para mostrárselo al usuario en un contexto de DEVOLUCIÓN (selects, filtros, gráficas) debe excluir `RoboExtravio` explícitamente — no es un `Rule::enum()` genérico. Ya está resuelto así en `GuardarDevolucionRequest` (`->except([CondicionDevolucion::RoboExtravio])`) y en `DevolucionController::almacenar()` (prop `condiciones` filtrada antes de mapear). Si agregas otro lugar que liste todos los casos para un flujo de devolución, replica el mismo filtro — de lo contrario el usuario vería "Robo / extravío" como opción al registrar una devolución, algo que no tiene sentido de negocio.
