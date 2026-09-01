# Inventario

## Modelo de datos

Dos conceptos separados:

- **`saldos_inventario`** — existencia actual (`cantidad`) y `minimo`, único por
  `empresa_id + sucursal_id + prenda_id + talla_id`. Es una vista rápida.
- **`movimientos_inventario`** — historia *append-only*. Cada fila registra
  `existencia_anterior` y `existencia_resultante`, por lo que el porqué de cada
  cambio queda trazado. No se edita ni se borra en operación normal.

## Una sola puerta

Toda variación de existencias pasa por
`App\Servicios\ServicioInventario::registrarMovimiento(MovimientoInventarioDatos)`.
No hay `UPDATE` directos a `saldos_inventario` dispersos en controladores.

```
DB::transaction:
  SELECT ... FROM saldos_inventario ... FOR UPDATE   (lockForUpdate)
  resultante = anterior + signo(direccion) * cantidad
  if resultante < 0 y !permitirNegativo  -> ExistenciasInsuficientesException
  UPDATE saldos_inventario
  INSERT movimientos_inventario
```

## Tipos de movimiento (`App\Enums\TipoMovimiento`)

`inicial`, `entrada`, `entrega`, `devolucion`, `ajuste_entrada`, `ajuste_salida`,
`correccion`, `traspaso_entrada`, `traspaso_salida`. La `direccion`
(`entrada`/`salida`) se deriva del tipo.

> Traspasos entre sucursales: la arquitectura los soporta (tipos y `direccion`
> definidos) pero la fase actual no expone la pantalla; se añadirá generando los
> dos movimientos relacionados dentro de una transacción.

## Concurrencia

`lockForUpdate` sobre la fila de saldo garantiza que dos entregas simultáneas de
la última unidad no puedan completarse ambas. Probado en
`tests/Feature/InventarioTest.php` y `EntregaTest.php`.

## Stock negativo

Prohibido por defecto (`config('uniformes.permitir_stock_negativo')`). Mensaje:
«No hay existencias suficientes de {prenda} talla {talla} en la sucursal
{sucursal}…». En una entrega multi-renglón, si falta stock de cualquier renglón
no se registra nada (transacción atómica).

## Entradas y ajustes

- Entradas (`RegistrarEntradaInventario`): compras/recepción o carga inicial;
  motivo obligatorio; auditadas.
- Ajustes (`AjustarInventario`): se fija una existencia objetivo; **no** se
  sustituye el saldo directamente, se genera el movimiento de ajuste con la
  diferencia; motivo obligatorio; auditado.
- Mínimos (`ServicioInventario::ajustarMinimo`): por saldo; disparan alertas en
  el panel y en los listados.
