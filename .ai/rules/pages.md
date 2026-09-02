---
paths:
    - 'resources/js/pages/**/Detalle.vue'
---

# Pages

## Detail pages must use fluid width (pending global sweep)

Las vistas de detalle deben aprovechar todo el ancho útil del contenido en lugar
de quedar centradas con `max-w-*`. Ya corregidas / creadas así:
`Sucursales/Detalle.vue`, `Areas/Detalle.vue`, `Almacenes/Detalle.vue`,
`Activos/Detalle.vue` (patrón `flex w-full flex-col gap-4 p-4`).
`Empresas/Detalle.vue` y `Colaboradores` siguen pendientes de la pasada global
dedicada — no tocar módulo por módulo.

## Módulos con listado en CARDS (no tabla)

`Empresas`, `Sucursales`, `Areas`, `Almacenes`, `Activos` presentan su listado
como tarjetas (grid responsive), con filtros de búsqueda + estado + orden y
"Limpiar filtros". Alta/edición en `Dialog` (modal) accesible salvo `Activos`,
que usa página de formulario propia por la subida de imagen.

## Consultas de listado reutilizables (export PDF/Excel futuro)

Requisito global: cada módulo tendrá `[Exportar Excel]` / `[Exportar PDF]`
respetando los filtros activos. Diseñar el query del `index` de forma que
pantalla, PDF y Excel usen la misma consulta (ver `ServicioReportes`). No
implementar la exportación por módulo todavía.
