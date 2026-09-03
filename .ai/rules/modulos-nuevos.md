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

## Tipos, categorías y variantes de activo (Bloque B)

- `tipos_activo`, `categorias_activo`, `tallas`: catálogos **por empresa**. La
  pantalla de administración (`Activos/Catalogos.vue`) lleva un selector de
  empresa que recarga con `?empresa_id=`. `store` / `rapido` / `reordenar` envían
  `empresa_id`.
- **Tipo y categoría son OPCIONALES** (nullable, `Rule::exists` sólo si vienen) e
  independientes entre sí. En el formulario de Activo son **combobox con
  búsqueda** (`BuscadorAsync`, `dependencia = empresa_id`):
    - `GET tipos-activo/buscar?empresa_id=&q=` → `{ tipos: [{id, nombre}] }`.
    - `GET categorias-activo/buscar?empresa_id=&q=&tipo_activo_id=` →
      `{ categorias: [{id, nombre, tipo_activo_id, tipo}] }`. Con `tipo_activo_id`
      **prioriza y acota** a ese tipo + las categorías sin tipo (una categoría de
      otro tipo no se ofrece; las sin tipo siempre sí).
    - Ambos: `empresa_id` presente e inválido/sin acceso → lista vacía (nunca cae
      a "todas mis empresas"); `empresa_id` ausente → todas las autorizadas.
      Sólo registros `activo`/`activa`. `authorize('viewAny', ...)`.
- **Alta inline**: el combobox emite `crear` (opción "+ Crear nuevo…" al final,
  sólo con `tipos-activo.administrar` / `categorias-activo.administrar`) → diálogo
  → `POST tipos-activo/rapido` / `categorias-activo/rapido` (JSON, devuelven el
  registro creado). No hay botones "Otro/Otra" ni `<select>` plano.
- **Coherencia tipo ↔ categoría** (validada en `GuardarActivoRequest` +
  `withValidator`): si el activo lleva tipo y categoría, y la categoría tiene
  `tipo_activo_id` no nulo, deben coincidir. Sin tipo, o categoría sin tipo → sin
  restricción.
- **Unicidad normalizada**: `tipos_activo` / `categorias_activo` tienen
  `nombre_normalizado` (índice único `(empresa_id, nombre_normalizado)`).
  `NombreNormalizado` (trait) lo sincroniza en `saving` y expone
  `existeNombreEnEmpresa()`, usado por los Form Requests y por `rapido`.
  `App\Soporte\NormalizadorNombre::catalogo()` es la única definición de "mismo
  nombre" (minúsculas + `preg_replace('/\s+/u', ' ', trim())`).
- **Desactivar** tipo/categoría (diálogo de confirmación en `Catalogos.vue`) sólo
  lo saca de nuevas selecciones; nunca borra ni pone la FK a null. `edit` de
  Activo manda `seleccion.{tipo,categoria}` para mostrar el nombre asignado
  aunque esté inactivo.
- `activos.categoria_id` es la fuente de verdad; `activos.categoria` (texto) es
  espejo temporal que sincroniza `ActivoController`.
- `Activo::tipo_control` distingue `cantidad` de `serializado`. El flujo de
  unidades serializadas (`UnidadActivo`, serie / IMEI) sigue **pendiente**
  (Bloque C).
