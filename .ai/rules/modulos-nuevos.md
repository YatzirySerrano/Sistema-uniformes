---
paths:
    - 'app/Http/Controllers/{Almacen,Area,Activo,TipoActivo,CategoriaActivo,CatalogoActivo,Talla,Inventario,MovimientoInventario}Controller.php'
    - 'app/Http/Requests/{Almacenes,Areas,Activos}/**'
    - 'app/Http/Requests/Concerns/ResuelveEmpresa.php'
    - 'app/Soporte/AccesoEmpresa.php'
    - 'app/Servicios/{ServicioInventario,ResolverAlmacenOperativo}.php'
    - 'app/Acciones/{RegistrarEntradaInventario,AjustarInventario}.php'
---

# Módulos Almacenes / Áreas / Activos / Inventario

## Contexto de empresa: SIN "empresa activa" (Bloque A)

**No existe empresa activa en sesión.** No hay `ContextoEmpresa`, ni
`empresaActiva()`, ni middleware `ResolverEmpresaActiva`, ni ruta
`empresa-activa`. El contexto de empresa se determina por **recurso / formulario
/ filtro** y SIEMPRE se valida el acceso; nunca se confía en el `empresa_id` que
llega del frontend.

- Controladores: `use ConEmpresa;` (concern con `porPagina()`,
  `empresasAutorizadas($request)`, `idsEmpresasAutorizadas($request)`,
  `resolverEmpresa($request)` → 403 si sin acceso, `empresaDelFiltro($request)`
  → `?Empresa` para filtros opcionales, `opcionesEmpresas($request)`).
- Servicio `App\Soporte\AccesoEmpresa` (stateless): `empresasAutorizadas(User)`,
  `sucursalesAutorizadas(User, Empresa)`, `almacenesAutorizados(User, Empresa)`,
  `puedeAcceder(User, Empresa)`.
- **Índice**: `whereIn('empresa_id', idsEmpresasAutorizadas)` + filtro opcional
  `empresa_id` (searchable en cliente). Props: `empresasAutorizadas` +
  `filtros.empresa_id`. Cada fila expone su `empresa`.
- **Alta** (`store`): `empresa_id` es campo del formulario. El Form Request usa
  `use ResuelveEmpresa;` y `$this->empresaResuelta('rutaParam')` (lee el input en
  alta, el modelo de ruta en edición; lanza `ValidationException` si sin acceso).
  El controlador: `$empresa = $request->empresaResuelta();`.
- **Edición / estado / detalle**: `$this->authorize(...)` con la Policy (revalida
  `puedeAccederEmpresa($modelo->empresa_id)`). NO se usa
  `abort_unless($modelo->empresa_id === ..., 404)`; un acceso ajeno da **403**
  (no 404).
- **Endpoints `buscar`** (`activos/buscar`, `almacenes/buscar`,
  `almacenes/colaboradores-buscar`, `empresas/buscar`): aceptan/exigen
  `empresa_id` y filtran por autorización (defensa IDOR). `activos/buscar` y
  `colaboradores-buscar` devuelven `[]` sin `empresa_id`.
- `HandleInertiaRequests` comparte `empresasAutorizadas` (no `contextoEmpresa`).
  `usePermisos()` expone `puede()` + `empresasAutorizadas`.
- `ServicioAuditoria::registrar()` recibe **siempre** `empresa_id` explícito en
  `$opciones`.

## Almacén ↔ Empresa: N:M (`almacen_empresa`)

- El almacén **NO pertenece** a una empresa y **NO** se relaciona con sucursales.
  `almacenes.empresa_id` y la tabla `almacen_sucursal` **no existen**.
- `Almacen::empresas()` (BelongsToMany vía `almacen_empresa`);
  `Almacen::abasteceEmpresa(int)`; `Almacen::scopeParaEmpresa($q, int)`.
- El inventario se mantiene **separado por empresa dentro del almacén**
  (`saldos_inventario.empresa_id` + `almacen_id`). Un almacén compartido guarda
  saldos independientes para cada empresa.
- `codigo` de almacén: único **a nivel plataforma** (`ALM-0001`);
  `generarCodigo()` sin parámetro de empresa.
- Alta/edición: campo `empresa_ids` (array, ≥ 1), cada id validado contra
  `AccesoEmpresa::idsAutorizados`. El responsable debe pertenecer a alguna de
  esas empresas. `AlmacenPolicy` autoriza si el usuario accede a **alguna**
  empresa del almacén.
- Auditar `crear` / `editar` / `empresas` (cambio de N:M) / `activar` /
  `desactivar` — una entrada por empresa abastecida afectada.

## Inventario por EMPRESA + ALMACÉN

Llave de `saldos_inventario` (estado actual): `empresa + ALMACÉN + activo + talla`
(único `saldos_inv_almacen_unico`). **`saldos_inventario` ya no tiene
`sucursal_id`.** `movimientos_inventario` **sí** conserva `sucursal_id` nullable
como procedencia histórica.

- `ServicioInventario` opera sobre `(empresa, almacen, activo, talla)`.
- `InventarioController` (`entrada` / `ajuste` / `minimos`): reciben `empresa_id`
  **y** `almacen_id`; validan `puedeAccederEmpresa` + `Rule::exists('almacen_empresa','almacen_id')->where('empresa_id', ...)` + almacén activo.
- `RegistrarEntradaInventario` / `AjustarInventario`: resuelven el almacén con
  `Almacen::query()->paraEmpresa($empresaId)->findOr(...)`.
- Un almacén desactivado no admite entradas/ajustes (para ninguna empresa).
- **No hay migración legacy** (`MigracionInventarioController`,
  `MigrarSaldosLegacyAAlmacen`, permiso `inventario.migrar` — eliminados). El
  enum `TipoMovimiento::MigracionLegacy` se conserva sólo para filas históricas.

### Registrar entrada de inventario

`RegistrarEntradaInventarioRequest` + `Inventario/Entrada.vue`:

- `empresa_id` obligatorio (searchable/selector); el almacén y los activos del
  formulario se acotan a esa empresa (`/almacenes/buscar?empresa_id=`,
  `/activos/buscar?empresa_id=&control=cantidad`).
- `items.*.talla_id` **nullable**: si el activo tiene variantes propias es
  obligatorio; si no, la acción resuelve la talla comodín.
- Serializados rechazados aquí (`items.N.activo_id`).
- Fila duplicada (`activo_id` + `talla_id`) → error en la segunda fila.

## Puente Entregas / Devoluciones / Correcciones

Todavía no rehechas (Bloque E/F). La empresa se **deriva del colaborador**; la
sucursal es contexto/histórico, no dimensión de stock.
`ResolverAlmacenOperativo::paraEmpresa(Empresa $empresa, ?int $almacenPreferidoId)`
obtiene el almacén de origen (activo **único** que abastece a la empresa; cero o
varios → `ExcepcionDeNegocioSimple`, nunca 500). `EntregaController` /
`DevolucionController` reciben `colaborador_id` y derivan todo.

## Tipos, categorías y variantes de activo

- `tipos_activo`, `categorias_activo`, `tallas`: catálogos **por empresa**. Sus
  pantallas (`Activos/Catalogos.vue`, `Activos/Tallas.vue`) llevan un selector de
  empresa que recarga la página con `?empresa_id=`. `store` / `rapido` /
  `reordenar` envían `empresa_id`.
- `activos.categoria_id` es la fuente de verdad; `activos.categoria` (texto) es
  espejo temporal que sincroniza `ActivoController`.
- `Activo::tipo_control` distingue `cantidad` de `serializado`. El flujo de
  unidades serializadas (`UnidadActivo`, serie / IMEI) sigue **pendiente**.
