---
paths:
    - 'app/Models/{Empresa,Sucursal,Almacen,Area,Activo}.php'
---

# Models

## Cascada de desactivación pendiente (diseñada, NO implementada)

Regla de negocio: al desactivar Empresa/Sucursal/Almacén se deben deshabilitar
operativamente sus entidades dependientes, y al reactivar sólo restaurar las que
fueron inactivadas por esa cascada (no las que ya estaban inactivas antes —
"reactivación selectiva"). Las cascadas **nunca** deben alterar históricos
(entregas, devoluciones, movimientos, acuses, firmas, auditoría).

Estado: los modelos ya están (Almacenes, Áreas, Activos, pivote
`almacen_sucursal`), pero la cascada **no** se implementó todavía; es el objeto
de un bloque posterior. No implementar aquí (2026-09-02).

## Relación Almacén ↔ Sucursal

Es **N:M** vía la tabla pivote `almacen_sucursal`. Nunca usar
`sucursales.almacen_id`. Un almacén abastece varias sucursales y una sucursal
puede recibir de varios almacenes.

## Activo (evolución de Prenda) — control del catálogo

- Tabla `activos` (antes `prendas`), pivote `activo_talla` (antes
  `prenda_talla`), FK `activo_id` en `saldos_inventario`,
  `movimientos_inventario`, `detalles_entrega`, `detalles_devolucion`.
  El snapshot inmutable del acuse guarda la clave `activo` (los acuses previos
  guardaron `prenda`; la plantilla lee ambas).
- `tipo_control` (`App\Enums\TipoControlActivo`: `cantidad` | `serializado`).
  El flujo de unidades serializadas NO está implementado.
- `tallas()` es **opcional** según el activo (un uniforme las usa; una laptop
  no).
- `tipo_activo_id` → catálogo `tipos_activo` por empresa. Desactivar un almacén
  NO afecta el catálogo de activos.

## Colaborador → Área

`colaboradores.area_id` (FK a `areas`) es la fuente de verdad. La columna de
texto `colaboradores.area` se conserva como **espejo temporal**
(importador/exportador/snapshot de acuse) hasta la reingeniería de Colaboradores;
`ColaboradorController` la sincroniza con el nombre del área. La relación se
llama `Colaborador::departamento()` (no `area()`, para no colisionar con la
columna).
