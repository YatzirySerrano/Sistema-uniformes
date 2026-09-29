---
paths:
  - 'app/Models/BitacoraAuditoria.php,app/Servicios/ServicioAuditoria.php,app/Http/Controllers/BitacoraController.php'
---

# Servicios Http Controllers

## Privacidad histórica del Superadministrador en la bitácora
Toda lectura de `bitacora_auditoria` para un usuario pasa por `BitacoraAuditoria::scopeVisiblePara($observador)` (en `BitacoraController::consultaVisible()`: listado, conteos, búsqueda, filtros, catálogo `modulos`, categorías y export). La privacidad se decide por el snapshot `realizada_por_superadministrador` que congela `ServicioAuditoria::registrar()` — NUNCA por el rol actual del actor (degradarlo o eliminarlo, FK nullOnDelete, filtraría su histórico). Tri-estado: true/false en filas nuevas; `null` = fila previa sin evidencia (backfill de la migración `2026_09_28_235009` sólo marcó true con rol actual o cambio de roles auditado, y false sin actor alguno): `null` es visible sólo si el actor existe y hoy no es superadmin; con actor eliminado queda oculta. Cualquier consulta NUEVA a la bitácora (otro módulo, dashboard, API) debe usar el scope.
