---
paths:
    - 'app/Models/{Empresa,Sucursal,Almacen,Area,Activo,TipoActivo,CategoriaActivo,SaldoInventario,MovimientoInventario}.php'
---

# Models

## Sin "empresa activa" (Bloque A)

`App\Soporte\ContextoEmpresa` **fue eliminado**. El contexto de empresa se
resuelve por recurso / formulario / filtro con `App\Soporte\AccesoEmpresa`
(stateless) y `User::puedeAccederEmpresa()`. No hay `empresa_activa_id` en
sesión.

## Almacén ↔ Empresa: N:M (`almacen_empresa`)

- Un almacén abastece a **varias empresas / razones sociales**. Pivote
  `almacen_empresa` (`almacen_id`, `empresa_id`, timestamps, unique).
- `almacenes.empresa_id` **no existe**. `Almacen` ya NO usa `PerteneceAEmpresa`.
- `Almacen::empresas()` (BelongsToMany), `Almacen::abasteceEmpresa(int)`,
  `Almacen::scopeParaEmpresa($q, int)`. No hay `Almacen::sucursales()` ni
  `Sucursal::almacenes()` (tabla `almacen_sucursal` eliminada).
- `almacenes.codigo` es único a nivel plataforma.
- Desactivar un almacén lo saca de operaciones **para todas sus empresas**; no
  toca el catálogo de activos ni los históricos.

## Cascada de desactivación pendiente (diseñada, NO implementada)

Al desactivar Empresa/Almacén/Área/Activo se deben deshabilitar operativamente
sus dependientes; al reactivar, sólo restaurar los inactivados por esa cascada
("reactivación selectiva"). Nunca alterar históricos. Es el Bloque G — no
implementar aquí. Nota mínima ya activa: los listados excluyen almacenes
`activo = false` y sólo consideran una empresa si está `activa`.

## Inventario: EMPRESA + ALMACÉN + ACTIVO + VARIANTE

- `saldos_inventario` (estado actual): llave `empresa + almacen_id + activo +
talla` (único `saldos_inv_almacen_unico`). **`saldos_inventario.sucursal_id`
  fue eliminado.** `SaldoInventario` no tiene relación `sucursal()` ni
  `scopePendienteMigracion()`.
- `movimientos_inventario` (historia append-only): **conserva** `sucursal_id`
  nullable como procedencia. `MovimientoInventario::sucursal()` sigue.
- Toda escritura pasa por `ServicioInventario` (transacción + `lockForUpdate`,
  sin stock negativo). `MovimientoInventarioDatos` exige `almacenId`;
  `sucursalId` es opcional (sólo procedencia en el movimiento).
- `TipoMovimiento::MigracionLegacy` se conserva para filas históricas; ya no se
  produce fuera de las migraciones.

## Activo (evolución de Prenda) — catálogo por empresa

- Tabla `activos`, pivote `activo_talla`, FK `activo_id` en
  `saldos_inventario` / `movimientos_inventario` / `detalles_entrega` /
  `detalles_devolucion`. `Activo` conserva `empresa_id` y `PerteneceAEmpresa`.
- `tipo_activo_id` / `categoria_id` son **opcionales** e independientes (Bloque
  B). `TipoActivo` y `CategoriaActivo` usan el trait `NombreNormalizado`:
  columna `nombre_normalizado` + índice único `(empresa_id, nombre_normalizado)`,
  sincronizada en `saving`; helper `existeNombreEnEmpresa()`. Desactivar un
  tipo/categoría no toca los activos que ya lo usan.
- `tipo_control` (`cantidad` | `serializado`). `tallas()` opcional. Talla comodín
  por empresa (`es_comodin = true`, `Empresa::tallaComodin()`).
- `categoria_id` es la fuente de verdad; `activos.categoria` (texto) es espejo
  temporal sincronizado por `ActivoController`.

## Colaborador → Área / Empresa / Sucursal

`colaboradores.empresa_id` + `sucursal_id` + `area_id` son la fuente de verdad.
La columna espejo `colaboradores.area` se conserva (importador/exportador/
snapshot) hasta la reingeniería de Colaboradores. Relación:
`Colaborador::departamento()`. La empresa/sucursal de una entrega se **derivan**
del colaborador.
