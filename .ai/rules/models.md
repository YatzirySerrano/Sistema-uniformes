---
paths:
    - 'app/Models/{Empresa,Sucursal}.php'
---

# Models

## Cascada de desactivación pendiente (bloqueada por Almacenes/Activos)

Regla de negocio solicitada pero NO implementada: al desactivar Empresa/Sucursal se deben deshabilitar operativamente sus entidades dependientes, y al reactivar sólo restaurar las que fueron inactivadas por esa cascada (no las que ya estaban inactivas antes). No implementar hasta integrar la nueva arquitectura de Almacenes/Activos y relaciones Almacén-Sucursal (evita reescribir la solución). Ver memoria de sesión "sucursales-branch-review" / "empresas-branch-review" para contexto de decisión (2026-09-02).
