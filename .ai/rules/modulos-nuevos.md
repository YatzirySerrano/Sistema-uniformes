---
paths:
    - 'app/Http/Controllers/{Almacen,Area,Activo,TipoActivo,CategoriaActivo,CatalogoActivo,Talla,Inventario,MovimientoInventario,Conjunto}Controller.php'
    - 'app/Http/Requests/{Almacenes,Areas,Activos,Conjuntos}/**'
    - app/Http/Requests/Concerns/ResuelveEmpresa.php
    - app/Soporte/AccesoEmpresa.php
    - 'app/Servicios/{ServicioInventario,ResolverAlmacenOperativo}.php'
    - 'app/Acciones/{RegistrarEntradaInventario,AjustarInventario}.php'
    - 'app/Models/{Conjunto,ConjuntoComponente}.php'
    - app/Soporte/DescripcionAuditoria.php
    - 'app/Acciones/ConfirmarAcuse*.php'
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

## Catálogos GLOBALES: tipos, categorías y variantes (redefinición funcional)

- `tipos_activo`, `categorias_activo`, `tallas` son catálogos **de plataforma**:
  **sin `empresa_id`** y **sin habilitación por empresa**. Son visibles para
  TODAS las empresas por igual; el único estado es el global (`activo`/`activa`).
  Los pivotes `tipo_activo_empresa`, `categoria_activo_empresa`, `talla_empresa`
  **ya no existen** (eliminados por `2026_09_04_000022_eliminar_pivotes_catalogo_empresa`).
  Catálogo global ≠ inventario compartido: el activo pertenece a una empresa y el
  stock se llavea por `empresa + almacén + activo + talla`.
- Modelos: sin `empresas()`, `habilitado(a)Para()` ni `scopeParaEmpresa()`. Los
  tres usan el trait `NombreNormalizado` (columna configurable: `Talla`
  normaliza `valor`). `existeNombre($nombre, $ignorar)` — unicidad de
  plataforma, sin `empresa_id`.
- **Tipo y categoría siguen siendo OPCIONALES** e independientes. En el
  formulario de Activo son `BuscadorAsync` **sin dependencia de empresa**:
    - `GET tipos-activo/buscar?q=` — sólo `activo = true`, no depende de
      `empresa_id` (se ignora si llega).
    - `GET categorias-activo/buscar?q=&tipo_activo_id=` — con `tipo_activo_id`
      prioriza y acota a ese tipo + las sin tipo.
    - `GET tallas/buscar?q=` — mismo patrón.
- **Alta inline** (`crear` del combobox): `POST tipos-activo/rapido` /
  `categorias-activo/rapido` / `tallas/rapido` (JSON) crean el registro global,
  visible de inmediato para todas las empresas.
- **No existe habilitación por empresa**: no hay rutas `.../empresa` ni
  `.../empresas`, ni checkbox "Disponible en «Empresa»", ni diálogo "N
  empresas". `Activos/Catalogos.vue` y `Activos/Tallas.vue` son listas de
  cards responsive (sin tablas) con búsqueda + alta + edición +
  activar/desactivar **global** únicamente. `TipoActivoPolicy` /
  `CategoriaActivoPolicy::administrar` exige sólo el permiso correspondiente
  (`tipos-activo.administrar` / `categorias-activo.administrar`), sin
  revalidar empresa.
- **Reglas en `GuardarActivoRequest`**: `Rule::exists('tipos_activo', 'id')
->where('activo', true)`, `Rule::exists('categorias_activo', 'id')
->where('activa', true)`, `Rule::exists('tallas', 'id')->where('activa', true)`.
  Igual en `GuardarEntregaRequest` y `DevolucionController` para `talla_id`.
- **Variante elegible de un activo** = asociada al activo (`activo_talla`) **Y**
  `tallas.activa`. Fuente de verdad única: `Activo::tallasElegibles()` (sin
  parámetro de empresa). La usan:
    - `GET activos/buscar` → `tallas` = sólo elegibles; `usa_variantes` = el
      activo tiene alguna variante asociada (crudo). `usa_variantes && tallas
=== []` ⇒ todas las variantes asociadas están desactivadas globalmente.
    - `RegistrarEntradaInventarioRequest::withValidator` + `RegistrarEntrada`
      acción: exigen que `talla_id` esté en `tallasElegibles()`.
    - **Ajuste / mínimos** (`InventarioController::validarOperacion`,
      `AjustarInventario`) — corrigen filas de saldo que YA existen: `talla_id`
      sólo debe ser nula **o** estar asociada al activo (`activo_talla`). **No**
      se exige que siga activa: hay que poder corregir/poner a cero existencias
      históricas de variantes luego desactivadas.
- **Coherencia tipo ↔ categoría**: sin cambios (backend
  `GuardarActivoRequest::withValidator`).
- **Sin talla comodín**: "sin variante" = `talla_id = NULL` en `saldos_inventario`
  / `movimientos_inventario` / `detalles_entrega` / `detalles_devolucion` (todos
  nullable + `nullOnDelete`). `ServicioInventario::acotarTalla()` usa `whereNull`
  cuando `tallaId === null`. Unicidad real vía columna generada
  `saldos_inventario.talla_ref = COALESCE(talla_id, 0)` en el índice
  `saldos_inv_almacen_unico`. `MovimientoInventarioDatos::$tallaId` es `?int`.
- **Históricos**: desactivar un catálogo NO toca los activos que ya lo usan (FK
  no se pone a null). `edit` de Activo manda `seleccion.{tipo, categoria}` para
  mostrar el nombre asignado aunque esté inactivo/desactivado.
- `activos.categoria_id` es la fuente de verdad; `activos.categoria` (texto) es
  espejo temporal que sincroniza `ActivoController`.

## Activo como hub operativo + alta unificada (redefinición funcional)

- **Almacenes es sólo CRUD/configuración** (datos, empresas abastecidas,
  responsable, activar/desactivar + un resumen de 2 cifras). **Activos** es el
  hub operativo: catálogo + existencias por almacén + filtros + "Agregar
  existencias"/"Agregar unidades". No reintroducir el desglose de inventario en
  `AlmacenController::show()`/`Almacenes/Detalle.vue`.
- `ActivoController::store()` delega en `App\Acciones\CrearActivoConExistencias`
  (transacción única: crea el Activo, asocia variantes o genera unidades, y
  registra la existencia inicial) — nunca crear el Activo "pelón" y el stock
  aparte. El almacén elegido en el alta es sólo el de la ENTRADA INICIAL;
  `activos.almacen_id` no existe ni debe añadirse.
- `AlmacenController::update()` rechaza quitar una empresa de `empresa_ids[]`
  si esa empresa tiene `SaldoInventario.cantidad > 0` o unidades activas en ese
  almacén (`rechazarSiHayDependenciasOperativas()`); el mensaje nombra la
  empresa. Nunca bloquea por históricos.

## Identificación individual: `UnidadActivo`, códigos y QR

- Sólo para `Activo::tipo_control = individual` (`TipoControlActivo::SeguimientoIndividual`).
  Nunca mostrar "Serializado" ni pedir número de serie/IMEI/MAC/etiqueta: el
  código lo genera `App\Soporte\ServicioGeneradorCodigos::siguiente($empresa)`
  (transacción + `lockForUpdate()` sobre `secuencias_codigo`, formato
  `"{$empresa->codigo}-{consecutivo de 6 dígitos}"`), nunca lo captura el
  usuario ni se deriva de `MAX(id)+1`.
- Alta atómica: `App\Acciones\RegistrarUnidadesActivo::ejecutar()` valida
  invariantes (activo pertenece a la empresa, `tipo_control = individual`,
  almacén abastece la empresa y está activo) y crea N unidades, cada una con
  un movimiento propio vía `ServicioInventario::registrarMovimientoUnidad()`
  (NO toca `saldos_inventario`; `talla_id` siempre nulo). Baja no destructiva:
  `App\Acciones\DarDeBajaUnidadActivo` (rechaza baja de una unidad asignada o
  ya dada de baja).
- `UnidadActivoController` vive bajo `activos/unidades/*` (dentro del hub de
  Activos, no un módulo de navegación aparte) — sus rutas van **antes** de
  `activos/{activo}` en `routes/sistema.php` para que "unidades" no sea
  capturado por el binding implícito. `show`/`baja` resuelven por
  `{unidad:public_token}`, nunca por id incremental.
- QR: `App\Servicios\ServicioEtiquetasQr` usa `BaconQrCode\Writer` +
  `BaconQrCode\Renderer\GDLibRenderer` (PNG vía GD, ya vendored por Fortify
  para 2FA — no usar `SvgImageBackEnd`/Imagick, no están garantizados). La URL
  del QR es siempre `URL::to("/activos/unidades/{$unidad->public_token}")` —
  permanente, nunca firmada ni con expiración. `generarEtiquetas()` es una
  acción distinta de cualquier export de reporte.

## Conjuntos: plantilla sin stock propio

- `Conjunto` pertenece a UNA empresa; `conjunto_componentes` liga Activos de
  **esa misma empresa** — no hay FK que lo garantice (los catálogos de tipo/
  categoría/variante son de plataforma, pero el Activo sí es por-empresa), así
  que `GuardarConjuntoRequest` valida `Rule::exists('activos','id')->where('empresa_id', ...)`
  y además, en `withValidator`, la coherencia de variante por fila.
- **Nunca se persiste un saldo del conjunto.** `Conjunto::disponibilidad(int
$almacenId): int` se calcula siempre en vivo: por componente,
  `intdiv(existencia, cantidad_requerida)`, y el resultado del conjunto es el
  **mínimo** entre todos los componentes. Existencia real:
    - Control por cantidad: `SaldoInventario` de `empresa + almacén + activo`;
      si el componente tiene variante fija (`talla_id`) se filtra por ella, si
      es `talla_libre` se **suma** la existencia de todas las variantes.
    - Seguimiento individual: cuenta de `UnidadActivo` en ese almacén con
      `estado=en_almacen` **y** `condicion=funcionando` (mismo criterio que
      `esEntregable()`).
- Variante por componente: **fija** (`talla_id` no nulo, debe estar en
  `activo->tallasElegibles()`), **libre** (`talla_libre=true`, se elige hasta
  la Entrega) o **ninguna** si el activo no usa variantes — nunca `talla_id` y
  `talla_libre` a la vez, y si el activo usa variantes hay que elegir una de
  las dos opciones (no dejarlo ambiguo).
- `sincronizarComponentes()` (en `ConjuntoController::store/update`) borra y
  recrea todas las filas en cada guardado — no hace diff línea por línea; es
  intencional porque los componentes no tienen identidad propia fuera del
  conjunto (sin históricos que preservar en esa tabla).
- Agregar un Activo suelto a una Entrega que incluye un Conjunto (Fase 5) NO
  modifica la definición del Conjunto — son conceptos independientes.

## DescripcionAuditoria: diff humano best-effort, nunca resuelve FKs con una consulta aparte

Transforma `valores_anteriores`/`valores_nuevos` de `bitacora_auditoria` en `[{campo, antes, ahora}]` legible. Reglas fijas:

- Oculta `id` y cualquier `*_id` (ruido sin nombre resuelto) + `created_at/updated_at/deleted_at`. Nunca hace un JOIN/consulta extra para resolver el nombre de una FK (evita N+1 y evita inventar datos que no venían en el snapshot).
- Humaniza booleans: `activo`/`activa` → "Activo"/"Inactivo"; cualquier otro boolean → "Sí"/"No".
- Para `UnidadActivo`, humaniza `estado`/`condicion` vía `EstadoUnidadActivo`/`CondicionUnidadActivo` (nunca prueba enums a ciegas por valor: distintos enums del dominio comparten valores string y eso daría etiquetas equivocadas).
- Sólo funciona donde el `registrar()` de origen YA capturó `valores_anteriores`/`valores_nuevos`. La mayoría de acciones (crear/editar en catálogos) sólo registran `descripcion`; ahí `cambios` sale vacío y la card muestra "Sin cambios detallados" — es intencional, no un bug. Los toggles (`activar`/`desactivar`) en Empresa/Sucursal/Area/Activo/Almacen/Conjunto/Usuario/Colaborador y las 3 acciones de `UnidadActivo` (recuperar/incidencia/baja) sí capturan before/after mínimo.

## Correos de comprobante de Entrega/Devolución: encolados, post-commit, idempotentes

`ConfirmarAcuseRecepcion`/`ConfirmarAcuseDevolucion::ejecutar()` despachan `ComprobanteEntregaMail`/`ComprobanteDevolucionMail` (Mailables `ShouldQueue`, `$this->afterCommit()`) en un método privado `enviarComprobante()` que corre DESPUÉS de la transacción y de `materializarPdf()`, envuelto en try/catch(Throwable)+Log — un fallo de SMTP/cola nunca revierte la operación. Destinatarios: encargado (User) + colaborador (`correo`), deduplicados por email en minúsculas; lista vacía = no se envía. Todo el contenido sale del snapshot inmutable del acuse (`snapshot_entrega`/`snapshot_devolucion`), no de datos recalculados. Idempotencia: la transición de estado es irreversible (guardada con lockForUpdate) y ningún endpoint GET/regenerar-PDF llama a `ejecutar()`; además `Cache::add('acuse-recepcion:correo:{id}'...)` como segunda barrera. NO existe columna `correo_enviado` (no se puede tocar BD). VPS: requiere `php artisan queue:work` (QUEUE_CONNECTION=database, tabla `jobs` ya existe). Vistas: `resources/views/emails/{layout,comprobante}.blade.php`.
