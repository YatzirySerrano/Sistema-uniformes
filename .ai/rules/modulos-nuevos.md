---
paths:
    - 'app/Http/Controllers/{Almacen,Area,Activo,TipoActivo,CategoriaActivo,CatalogoActivo,Talla,Inventario,MovimientoInventario,MigracionInventario}Controller.php'
    - 'app/Http/Requests/{Almacenes,Areas,Activos}/**'
    - 'app/Servicios/{ServicioInventario,ResolverAlmacenOperativo}.php'
    - 'app/Acciones/{RegistrarEntradaInventario,AjustarInventario,MigrarSaldosLegacyAAlmacen}.php'
---

# Módulos Almacenes / Áreas / Activos

Patrón calcado de `SucursalController` / `EmpresaController` (módulos aprobados):

- `use ConEmpresaActiva;` + `ServicioAuditoria` inyectado en el constructor.
- La **empresa nunca llega del frontend**: `empresaActiva()` del contexto es la
  autoridad. En `store` se fuerza `empresa_id`; en `show`/`update`/`toggle` se
  hace `abort_unless($modelo->empresa_id === $this->empresaActiva()->id, 404)`.
- Autorización: `$this->authorize(...)` con la Policy correspondiente
  (`ActivoPolicy`, `AlmacenPolicy`, `AreaPolicy`), que combina el permiso
  granular (`activos.*`, `almacenes.*`, `areas.*`) con
  `User::puedeAccederEmpresa()`. Sin hardcodear nombres de rol (salvo el bypass
  de Superadministrador en `AppServiceProvider`).
- Form Requests con `NormalizaEntrada`, `authorize()` que delega en la Policy,
  reglas cruzadas contra la empresa activa (responsable, sucursales abastecidas,
  tipo de activo, tallas). No debe haber 500 con arrays/objetos donde se espera
  string/id.
- Códigos autogenerados y únicos por empresa: `ALM-0001`, `ARE-0001`,
  `ACT-0001` (helper `generarCodigo()` con `withTrashed()`).
- Auditar `crear` / `editar` / `activar` / `desactivar` y las relaciones
  críticas (`almacenes` ↔ `sucursales`) vía `ServicioAuditoria::registrar()`.
- El listado se sirve como cards (ver `.ai/rules/pages.md`) y su query debe ser
  reutilizable para la futura exportación PDF/Excel.

## Inventario por almacén (implementado)

El inventario vive en el **almacén**: `saldos_inventario` / `movimientos_inventario`
se llavean por `almacen_id` (índice único `saldos_inv_almacen_unico`);
`sucursal_id` es nullable (sólo filas legacy y procedencia del historial).
`ServicioInventario` opera sobre almacén. `InventarioController`,
`RegistrarEntradaInventario` y `AjustarInventario` reciben `almacen_id`. Un
almacén desactivado no admite entradas/ajustes.

Entregas / Devoluciones / Correcciones todavía preguntan la sucursal en la UI;
`ResolverAlmacenOperativo::paraSucursal()` obtiene el almacén de origen
(abastecedor **único** de la sucursal; cero o varios → `ExcepcionDeNegocioSimple`,
nunca 500). No reintroducir stock por sucursal como fuente de verdad.

Saldos legacy: migración automática `..._000014` sólo para sucursales con un
abastecedor único; el resto lo resuelve el asistente
(`MigracionInventarioController`, `MigrarSaldosLegacyAAlmacen`) — idempotente y
sin duplicar saldos.

### Registrar entrada de inventario

`RegistrarEntradaInventarioRequest` + `Inventario/Entrada.vue`:

- `items.*.talla_id` es **nullable**. Si el activo tiene variantes propias →
  `talla_id` obligatorio y debe ser una de ellas (error `items.N.talla_id`); si
  no tiene → no se debe enviar `talla_id` y la acción resuelve la talla comodín.
- Activos serializados: rechazados aquí (error `items.N.activo_id`); el
  formulario sólo lista `tipo_control = cantidad`.
- Fila duplicada (mismo `activo_id` + `talla_id`) → error en la segunda fila.
- Errores por fila con clave `items.N.<campo>`; combobox `BuscadorAsync` para
  almacén (`/almacenes/buscar`) y activo (`/activos/buscar?control=cantidad`).

## Tipos y categorías de activo

- `tipos_activo`: CRUD en `TipoActivoController` (permiso `tipos-activo.administrar`),
  pantalla `Activos/Catalogos.vue`. Tipos base sembrados por migración. **No**
  existe "Uniforme" como tipo. Alta rápida: `tipos-activo/rapido` (JSON).
- `categorias_activo`: catálogo real (`CategoriaActivoController`, permiso
  `categorias-activo.administrar`). `activos.categoria_id` es la fuente de
  verdad; `activos.categoria` (texto) es espejo temporal que sincroniza
  `ActivoController` (mismo patrón que `colaboradores.area`).

## Serializados y variantes

`Activo::tipo_control` distingue `cantidad` de `serializado`. El formulario de
Activo oculta variantes/tallas cuando es serializado. El flujo de unidades
serializadas (entidad `UnidadActivo`, serie / IMEI) sigue **pendiente**: no
implementarlo aquí.
