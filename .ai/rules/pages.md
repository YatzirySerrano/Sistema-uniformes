---
paths:
    - 'resources/js/pages/**/Detalle.vue'
---

# Pages

## Detail pages must use fluid width (pending global sweep)

Las vistas de detalle de Empresa, Sucursal, Almacén, Colaborador y otros módulos deben aprovechar todo el ancho útil del contenido en lugar de quedar centradas con `max-w-*`. Sucursales/Detalle.vue ya se corrigió (2026-09-02, rama mejora/sucursales) a `flex w-full flex-col gap-4 p-4`. Empresas/Detalle.vue y otras vistas de detalle siguen con `max-w-3xl`/`max-w-4xl` centrado — pendiente resolverlo globalmente en una pasada dedicada (no tocar módulo por módulo).
