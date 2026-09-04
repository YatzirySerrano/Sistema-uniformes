# Inventario (por almacén)

> **BLOQUE C · Etapa 1 — Catálogos compartidos.** `talla_id` en `saldos_inventario` / `movimientos_inventario` / `detalles_entrega` / `detalles_devolucion` pasa a **nullable** (`NULL` = "sin variante", ya no hay talla comodín). La unicidad de `saldos_inventario` usa la columna generada `talla_ref = COALESCE(talla_id, 0)` en el índice `saldos_inv_almacen_unico`. `ServicioInventario` usa `whereNull('talla_id')` para el caso sin variante.

> **BLOQUE A — actualización.** Llave del inventario actual:
> `EMPRESA + ALMACÉN + ACTIVO + VARIANTE`. `saldos_inventario` **ya no tiene
> `sucursal_id`** (`movimientos_inventario` sí lo conserva como procedencia
> histórica). Un mismo almacén abastece a varias empresas y guarda saldos
> independientes por empresa. No existe el asistente de migración legacy
> (`MigracionInventarioController`, permiso `inventario.migrar` — eliminados).
> `InventarioController` recibe `empresa_id` + `almacen_id`.

> **Arquitectura vigente.** El origen físico del stock es el **ALMACÉN**:
> `ALMACÉN + ACTIVO + VARIANTE = STOCK`. La sucursal es sólo el destino/contexto
> del colaborador y **dejó de ser fuente de existencias**. La arquitectura
> anterior (`empresa + sucursal + activo + talla`) queda como **legacy migrada**:
> se conserva para los saldos aún sin trasladar y para la procedencia del
> historial, nunca como fuente de verdad de nuevas operaciones.

## Modelo de datos

Dos conceptos separados:

- **`saldos_inventario`** — existencia actual (`cantidad`) y `minimo`, único por
  `empresa_id + almacen_id + activo_id + talla_id` (índice
  `saldos_inv_almacen_unico`). `sucursal_id` es **nullable**: sólo lo tienen las
  filas legacy pendientes de migración.
- **`movimientos_inventario`** — historia _append-only_. Cada fila registra
  `existencia_anterior` y `existencia_resultante`. Guarda `almacen_id` y,
  opcionalmente, `sucursal_id` como procedencia (p. ej. la sucursal del
  colaborador que recibió una entrega). No se edita ni se borra.

## Migración de existencias legacy (sucursal → almacén)

1. **Automática** (`migración ..._000014`): traslada los saldos de las sucursales
   con **exactamente un** almacén activo abastecedor. Registra un movimiento
   `migracion_legacy` y una entrada de auditoría. No adivina cuando hay cero o
   varios almacenes.
2. **Asistente** (`/inventario/migracion`, permiso `inventario.migrar`,
   `MigracionInventarioController` + `MigrarSaldosLegacyAAlmacen`): resuelve por
   lote los casos ambiguos. Nunca duplica saldos (si el almacén destino ya tiene
   existencias, suma y elimina la fila legacy). Idempotente.

## Una sola puerta

Toda variación de existencias pasa por
`App\Servicios\ServicioInventario::registrarMovimiento(MovimientoInventarioDatos)`,
cuya dimensión es el **almacén**. No hay `UPDATE` directos a `saldos_inventario`
dispersos en controladores.

```
DB::transaction:
  SELECT ... FROM saldos_inventario WHERE almacen_id = ? ... FOR UPDATE
  resultante = anterior + signo(direccion) * cantidad
  if resultante < 0 y !permitirNegativo  -> ExistenciasInsuficientesException
  UPDATE saldos_inventario
  INSERT movimientos_inventario   (almacen_id + sucursal_id de procedencia)
```

`RegistrarEntradaInventario` y `AjustarInventario` reciben `almacen_id` y
rechazan almacenes de otra empresa o desactivados.

### Entregas / Devoluciones / Correcciones

Su UI todavía pregunta la sucursal. `ResolverAlmacenOperativo::paraSucursal()`
obtiene el almacén de origen: el abastecedor **único** de esa sucursal. Si hay
cero o varios, la operación se detiene con un mensaje en español (nunca un 500).
`entregas_uniformes.almacen_id` / `devoluciones.almacen_id` guardan la
procedencia. La reingeniería de esas pantallas (selector de almacén, uniformes,
serializados, PDF) es fase posterior.

## Tipos de movimiento (`App\Enums\TipoMovimiento`)

`inicial`, `entrada`, `entrega`, `devolucion`, `ajuste_entrada`, `ajuste_salida`,
`correccion`, `traspaso_entrada`, `traspaso_salida`, `migracion_legacy`. La
`direccion` (`entrada`/`salida`) se deriva del tipo.

> Traspasos entre sucursales: la arquitectura los soporta (tipos y `direccion`
> definidos) pero la fase actual no expone la pantalla; se añadirá generando los
> dos movimientos relacionados dentro de una transacción.

## Concurrencia

`lockForUpdate` sobre la fila de saldo garantiza que dos entregas simultáneas de
la última unidad no puedan completarse ambas. Probado en
`tests/Feature/InventarioTest.php` y `EntregaTest.php`.

## Stock negativo

Prohibido por defecto. Mensaje:
«No hay existencias suficientes de {activo} (variante {talla}) en el almacén
{almacén}…». En una entrega multi-renglón, si falta stock de cualquier renglón
no se registra nada (transacción atómica).

## Entradas y ajustes

- Entradas (`RegistrarEntradaInventario` + `RegistrarEntradaInventarioRequest` +
  `Inventario/Entrada.vue`): compras/recepción o carga inicial; motivo
  obligatorio; auditadas.
    - Combobox con buscador para almacén y activo (endpoints JSON
      `/almacenes/buscar`, `/activos/buscar?control=cantidad`). Sólo activos por
      cantidad; los serializados se registrarán unidad por unidad en otra fase.
    - **Variante opcional**: si el activo tiene variantes propias (activas
      globalmente), `talla_id` es obligatorio y debe ser una de ellas; si no
      las tiene, `talla_id` va nulo y el saldo se guarda
      con `talla_id = NULL` ("sin variante"; ya no hay talla comodín). Así un
      mouse, un cable o una gorra unitalla se registran sin forzar una talla
      ficticia.
    - Errores por fila con la clave `items.N.<campo>` (visibles junto a la fila);
      fila duplicada (mismo activo + variante) bloqueada.
- Ajustes (`AjustarInventario`): se fija una existencia objetivo; **no** se
  sustituye el saldo directamente, se genera el movimiento de ajuste con la
  diferencia; motivo obligatorio; auditado.
- Mínimos (`ServicioInventario::ajustarMinimo`): por saldo; disparan alertas en
  el panel y en los listados.
