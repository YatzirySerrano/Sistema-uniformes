---
paths:
    - 'app/Models/{Empresa,Sucursal,Almacen,Area,Activo,TipoActivo,CategoriaActivo,SaldoInventario,MovimientoInventario}.php'
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
  El flujo de unidades serializadas (`UnidadActivo`) NO está implementado.
- `tallas()` es **opcional** según el activo (un uniforme las usa; una laptop
  no).
- **Talla comodín**: cada empresa tiene una fila `tallas` con
  `es_comodin = true` (`valor = 'Sin variante'`, `orden = 0`), creada por la
  migración `..._000015` y por `Empresa::booted()` (`static::created`). Es la
  talla que usa en inventario un activo por cantidad **sin** variantes propias.
  `Talla::scopeSeleccionables()` la excluye; úsalo en toda lista de
  administración / selección de tallas. `Empresa::tallaComodin()` la resuelve
  (con `firstOrCreate`). El usuario **no** captura `orden`: se asigna
  `max(orden)+1` al crear y se cambia con `TallaController@reordenar`.
- `tipo_activo_id` → `tipos_activo` (CRUD completo). **No** existe "Uniforme"
  como tipo: un uniforme es un conjunto de activos (módulo pendiente).
- `categoria_id` → `categorias_activo` (catálogo real, fuente de verdad).
  `activos.categoria` (texto) es espejo temporal sincronizado por
  `ActivoController`, igual que `colaboradores.area`. Relación:
  `Activo::categoriaActivo()` (no `categoria`, para no chocar con la columna).
- Desactivar un almacén NO afecta el catálogo de activos.

## Inventario por almacén (SaldoInventario / MovimientoInventario)

- La dimensión del saldo es `empresa + almacen_id + activo + talla` (único:
  `saldos_inv_almacen_unico`). `sucursal_id` es **nullable**: sólo filas legacy
  pendientes de migración y procedencia en el historial. Nunca reintroducir
  stock por sucursal como fuente de verdad.
- Toda escritura pasa por `ServicioInventario` (transacción + `lockForUpdate`,
  sin stock negativo). `MovimientoInventarioDatos` exige `almacenId`;
  `sucursalId` es opcional (procedencia).
- `TipoMovimiento::MigracionLegacy` marca los traslados sucursal → almacén.
- Scope `SaldoInventario::scopePendienteMigracion()` = `almacen_id IS NULL AND
sucursal_id IS NOT NULL`.

## Colaborador → Área

`colaboradores.area_id` (FK a `areas`) es la fuente de verdad. La columna de
texto `colaboradores.area` se conserva como **espejo temporal**
(importador/exportador/snapshot de acuse) hasta la reingeniería de Colaboradores;
`ColaboradorController` la sincroniza con el nombre del área. La relación se
llama `Colaborador::departamento()` (no `area()`, para no colisionar con la
columna).
