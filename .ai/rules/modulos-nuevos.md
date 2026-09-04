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
  obligatorio (validado contra `talla_empresa`); si no, `talla_id = NULL` ("sin
  variante", ya no hay talla comodín).
- Serializados rechazados aquí (`items.N.activo_id`).
- Fila duplicada (`activo_id` + `talla_id`) → error en la segunda fila.

## Puente Entregas / Devoluciones / Correcciones

Todavía no rehechas (Bloque E/F). La empresa se **deriva del colaborador**; la
sucursal es contexto/histórico, no dimensión de stock.
`ResolverAlmacenOperativo::paraEmpresa(Empresa $empresa, ?int $almacenPreferidoId)`
obtiene el almacén de origen (activo **único** que abastece a la empresa; cero o
varios → `ExcepcionDeNegocioSimple`, nunca 500). `EntregaController` /
`DevolucionController` reciben `colaborador_id` y derivan todo.

## Catálogos COMPARTIDOS: tipos, categorías y variantes (Bloque C · Etapa 1)

- `tipos_activo`, `categorias_activo`, `tallas` son catálogos **de plataforma**:
  **sin `empresa_id`**. Se **habilitan por empresa** con pivotes N:M
  (`tipo_activo_empresa`, `categoria_activo_empresa`, `talla_empresa`). Catálogo
  compartido ≠ inventario compartido: el activo pertenece a una empresa y el
  stock se llavea por `empresa + almacén + activo + talla`.
- Modelos: `empresas()` (BelongsToMany), `scopeParaEmpresa($q, int $empresaId)`
  (`whereHas('empresas', whereKey)`), `habilitado/aPara(int)`. Los tres usan el
  trait `NombreNormalizado` (columna configurable: `Talla` normaliza `valor`).
  `existeNombre($nombre, $ignorar)` — **unicidad de plataforma**, sin `empresa_id`.
- **Tipo y categoría siguen siendo OPCIONALES** e independientes. En el formulario
  de Activo son `BuscadorAsync` (`dependencia = empresa_id`):
    - `GET tipos-activo/buscar?empresa_id=&q=` — filtra por pivote
      (`scopeParaEmpresa`). Sin `empresa_id` → habilitados para alguna empresa
      autorizada. `empresa_id` inválido/sin acceso → `[]`. Sólo `activo = true`.
    - `GET categorias-activo/buscar?empresa_id=&q=&tipo_activo_id=` — con
      `tipo_activo_id` prioriza y acota a ese tipo + las sin tipo.
    - `GET tallas/buscar?empresa_id=&q=` — mismo patrón.
- **Alta inline** (`crear` del combobox): `POST tipos-activo/rapido` /
  `categorias-activo/rapido` / `tallas/rapido` (JSON) crean el registro y lo
  **habilitan SÓLO para la empresa del formulario** (`resolverEmpresa`).
- **Habilitación por empresa** — dos vías, todas con mensaje que **nombra la
  empresa afectada** (`«X» habilitado/deshabilitado para «Empresa»`):
    - Toggle de una empresa: `POST tipos-activo/{tipo}/empresa` /
      `POST categorias-activo/{categoria}/empresa` / `POST tallas/{talla}/empresa`
      (`{empresa_id}`). Attach/detach; valida `puedeAccederEmpresa`.
    - Bulk (diálogo "Empresas"): `PUT tipos-activo/{tipo}/empresas` /
      `PUT categorias-activo/{categoria}/empresas` (`empresa_ids[]`,
      `Rule::in(idsAutorizados)`; conserva las empresas fuera del alcance del
      usuario).
- **Alta desde `Activos/Catalogos.vue`**: `POST tipos-activo` / `categorias-activo`
  con `empresa_ids[]` (`Rule::in(idsAutorizados)`). `POST tallas` con
  `empresa_ids[]`.
- **Reglas cross-company en `GuardarActivoRequest`**:
  `Rule::exists('tipo_activo_empresa', 'tipo_activo_id')->where('empresa_id', $e)`,
  `categoria_activo_empresa`, `talla_empresa`. Igual en `GuardarEntregaRequest` y
  `DevolucionController`.
- **Variante elegible de un activo en una empresa** = asociada al activo
  (`activo_talla`) **Y** habilitada para esa empresa (`talla_empresa`) **Y**
  `tallas.activa`. Fuente de verdad única: `Activo::tallasHabilitadas(int
$empresaId)`. La usan:
    - `GET activos/buscar` → `tallas` = sólo elegibles; `usa_variantes` = el
      activo tiene alguna variante asociada (crudo). `usa_variantes && tallas ===
[]` ⇒ el activo tiene variantes pero ninguna habilitada para esa empresa
      (mal configurado): el frontend lo marca y bloquea, el backend lo rechaza.
    - `RegistrarEntradaInventarioRequest::withValidator` + `RegistrarEntrada`
      acción: exigen que `talla_id` esté en `tallasHabilitadas`; si el activo usa
      variantes pero no hay ninguna elegible → error en `items.N.activo_id`.
    - **Ajuste / mínimos** (`InventarioController::validarOperacion`,
      `AjustarInventario`) — corrigen filas de saldo que YA existen: `talla_id`
      sólo debe ser nula **o** estar asociada al activo (`activo_talla`). **No**
      se exige que siga habilitada para la empresa: hay que poder corregir/poner
      a cero existencias históricas de variantes luego deshabilitadas.
- **Coherencia tipo ↔ categoría**: sin cambios (backend
  `GuardarActivoRequest::withValidator`; la categoría ya no se acota por empresa
  ahí porque las reglas `exists` sobre pivotes ya lo hacen).
- **Estado global vs habilitación por empresa**: `activo`/`activa` = estado
  global (retira de nuevas selecciones en todas); quitar una empresa del pivote
  = deja de ofrecerse sólo ahí. `Activos/Catalogos.vue` y `Activos/Tallas.vue`
  son **listas de cards responsive** (sin tablas): selector de empresa searchable
  ("Administrar disponibilidad para …"), checkbox **"Disponible en «Empresa»"**
  por fila, botón/contador **"N empresas"** que abre el diálogo con la lista
  completa de empresas (habilitadas marcadas) + buscador para ver/gestionar, y
  diálogo de confirmación para el estado global. `TipoActivoPolicy` /
  `CategoriaActivoPolicy`: `administrar` exige el permiso y (rol restringido) que
  el catálogo esté habilitado para alguna empresa del usuario.
- **Sin talla comodín**: "sin variante" = `talla_id = NULL` en `saldos_inventario`
  / `movimientos_inventario` / `detalles_entrega` / `detalles_devolucion` (todos
  nullable + `nullOnDelete`). `ServicioInventario::acotarTalla()` usa `whereNull`
  cuando `tallaId === null`. Unicidad real vía columna generada
  `saldos_inventario.talla_ref = COALESCE(talla_id, 0)` en el índice
  `saldos_inv_almacen_unico`. `MovimientoInventarioDatos::$tallaId` es `?int`.
- **Históricos**: deshabilitar/desactivar un catálogo NO toca los activos que ya
  lo usan (FK no se pone a null). `edit` de Activo manda `seleccion.{tipo,
categoria}` para mostrar el nombre asignado aunque esté inactivo/deshabilitado.
- `activos.categoria_id` es la fuente de verdad; `activos.categoria` (texto) es
  espejo temporal que sincroniza `ActivoController`.
