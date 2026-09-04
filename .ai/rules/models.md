---
paths:
    - 'app/Models/{Empresa,Sucursal,Almacen,Area,Activo,TipoActivo,CategoriaActivo,SaldoInventario,MovimientoInventario,UnidadActivo,SecuenciaCodigo,Conjunto,ConjuntoComponente}.php'
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
- `tipo_activo_id` / `categoria_id` son **opcionales** e independientes.
- **Catálogos GLOBALES (redefinición funcional)**: `TipoActivo`,
  `CategoriaActivo` y `Talla` **no tienen `empresa_id`** ni usan
  `PerteneceAEmpresa`, y **no se habilitan por empresa** — son visibles para
  todas por igual. Los pivotes `tipo_activo_empresa` / `categoria_activo_empresa`
  / `talla_empresa` fueron eliminados; no hay `empresas()`, `habilitado(a)Para()`
  ni `scopeParaEmpresa()` en estos tres modelos. El trait `NombreNormalizado` es
  **de columna configurable** (`Talla::columnaNombre() = 'valor'`), con índice
  único **de plataforma** (`nombre_normalizado` / `valor_normalizado`); helper
  `existeNombre($n, $ig)`.
- **`Talla` ya no tiene `es_comodin` ni `scopeSeleccionables`.** `Empresa` ya no
  tiene `tallaComodin()`, `tallas()`, `tiposActivo()` ni `categoriasActivo()`
  (esos tres BelongsToMany se eliminaron junto con los pivotes — los catálogos
  son globales, no hay "habilitación por empresa" que rastrear desde `Empresa`).
  "Sin variante" = `talla_id = NULL`.
- Deshabilitar/desactivar un tipo/categoría/variante no toca los activos que ya
  lo usan.
- `tipo_control` (`cantidad` | `individual` — enum `TipoControlActivo::Cantidad`
  / `SeguimientoIndividual`; nunca "serializado" de cara al usuario).
  `Activo::tallas()` (pivote `activo_talla`) opcional y sólo aplica a
  `cantidad`; un activo por cantidad sin variantes usa `talla_id = NULL` en el
  inventario. `Activo::tallasElegibles()` (sin parámetro de empresa: los
  catálogos son globales) es la única fuente de verdad de variantes usables.
- `categoria_id` es la fuente de verdad; `activos.categoria` (texto) es espejo
  temporal sincronizado por `ActivoController`.
- **`UnidadActivo`** (sólo para `Activo::tipo_control = individual`): fuente de
  verdad física, sin saldo agregado. `codigo` (autogenerado, único de
  plataforma, estable de por vida — no depende del almacén) y `public_token`
  (UUID, usado en la URL del QR, nunca el id incremental) se generan en
  `App\Acciones\RegistrarUnidadesActivo`, nunca los captura el usuario.
  `estado` (`EstadoUnidadActivo`: en_almacen/asignada/baja) y `condicion`
  (`CondicionUnidadActivo`: funcionando/en_reparacion/inservible/perdido/robado)
  son ejes independientes; `esEntregable()` exige ambos en su valor "bueno".
  `colaborador_id` es sólo la referencia de ESTADO ACTUAL — el histórico real
  vive en `movimientos_inventario.unidad_activo_id` (movimiento por unidad,
  vía `ServicioInventario::registrarMovimientoUnidad()`, que NUNCA toca
  `saldos_inventario`). `SecuenciaCodigo` (`secuencias_codigo`, único por
  `empresa_id + ambito`) es el contador atómico detrás del generador de
  códigos (`lockForUpdate()`, nunca `MAX(id)+1`).
- **`Conjunto`** / **`ConjuntoComponente`**: plantilla de UNA empresa, sin
  stock propio — `Conjunto::disponibilidad(int $almacenId)` siempre se
  calcula en vivo (mínimo, por componente, de existencia real ÷
  `cantidad_requerida`), nunca se persiste un saldo del conjunto.
  `ConjuntoComponente` no tiene `empresa_id` propio: la empresa la fija el
  `Conjunto` padre, y cada componente debe apuntar a un `Activo` de esa misma
  empresa (validado en el Form Request, sin FK porque el catálogo de
  tipo/categoría/variante es de plataforma pero el Activo es por-empresa).

## Colaborador → Área / Empresa / Sucursal

`colaboradores.empresa_id` + `sucursal_id` + `area_id` son la fuente de verdad.
La columna espejo `colaboradores.area` se conserva (importador/exportador/
snapshot) hasta la reingeniería de Colaboradores. Relación:
`Colaborador::departamento()`. La empresa/sucursal de una entrega se **derivan**
del colaborador.
