---
paths:
  - 'resources/js/pages/Inventario/Traspasos/Crear.vue,app/Http/Requests/Inventario/RegistrarTraspasoRequest.php,app/Acciones/RegistrarTraspasoInventario.php,app/Excepciones/ExistenciasInsuficientesException.php'
---

# Excepciones

## Traspasos: stock por VARIANTE en la UI + mensaje de concurrencia reusando la excepción
`GET /activos/buscar` ya devuelve `tallas[].disponible` (saldo por empresa+almacén+activo+talla) además de `disponible` (agregado del activo). Tras elegir talla, `Inventario/Traspasos/Crear.vue` debe pintar `tallas[].disponible` de la talla elegida, NUNCA el agregado (era el bug del "40" = XS 20 + S 20). Antes de elegir talla: "Selecciona una talla para consultar la existencia disponible." El wizard bloquea "Siguiente" con lista visible de problemas (`problemasPaso2`), no sólo botón disabled. Prevalidación de UX; la autoridad es siempre `RegistrarTraspasoRequest::withValidator` (pre-chequeo no bloqueante por variante) + `ServicioInventario` con `lockForUpdate`. `ExistenciasInsuficientesException` lleva props `public readonly` (activo/talla/almacen/disponible/solicitado); `RegistrarTraspasoInventario::traspasarCantidad` las reutiliza para el mensaje "El stock disponible cambió. Solicitaste N unidades de X (variante T), pero actualmente sólo hay M disponibles en el almacén A." — SIN segunda consulta a `saldoActual()`. Nunca "Error al registrar traspaso.".
