---
paths:
    - resources/js/components/almacenes/FormularioAlmacen.vue
---

# Almacenes

## Orden del formulario de Almacén y guardia del watch de Responsable

Orden fijo del formulario (alta/edición): Empresas abastecidas (primero, ≥1 obligatorio) → Nombre → Código (preview de sólo lectura) → Responsable del almacén → Teléfono → Correo → Dirección → Descripción. El responsable se busca dentro de `form.empresa_ids[0]` (la primera empresa abastecida marcada), por eso va después de "Empresas abastecidas" y el combobox se deshabilita si `empresa_ids` está vacío.

Bug corregido (ronda 2026-09-15): el `watch(empresaResponsableId, …)` limpiaba `responsable` en CUALQUIER cambio de `form.empresa_ids[0]`, incluso si al final volvía a apuntar a la misma empresa (p. ej. destildar y volver a marcar la primera empresa reordena el array y puede regresar al mismo id tras un estado intermedio). Fix: un ref `responsableEmpresaId` guarda a qué empresa pertenece el responsable actualmente elegido (se fija en `alElegirResponsable` y al montar en edición); el watch sólo limpia `responsable` cuando `empresaResponsableId` pasa a un valor REALMENTE distinto de `responsableEmpresaId`. No hay test automatizado (el proyecto no tiene runner de JS) — verificado por QA manual del owner.
