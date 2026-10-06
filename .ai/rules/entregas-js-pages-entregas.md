---
paths:
  - 'app/{Http/Requests/Entregas/GuardarEntregaRequest.php,Acciones/CrearEntregaUniforme.php,Acciones/RedistribuirCustodia.php,Models/EntregaUniforme.php,Servicios/ServicioDistribucionActivo.php},resources/js/pages/Entregas/Crear.vue'
---

# Entregas Js Pages Entregas

## Entrega: DESTINO ≠ empresa PROPIETARIA del inventario
`entregas_uniformes.empresa_id/sucursal_id` = DESTINO (empresa/sucursal laboral del colaborador que recibe). La empresa PROPIETARIA de los bienes nunca se guarda aparte: se deriva de `activos.empresa_id` (`EntregaUniforme::empresaInventarioId()`); una entrega lleva bienes de UNA sola propietaria. Salida de almacén: `empresa_inventario_id` (opcional; sin él = empresa del colaborador) acota almacén/activos/unidades/conjuntos/saldos (`GuardarEntregaRequest::empresaInventarioId()`, null si no autorizada → error) y `CrearEntregaUniforme` descuenta el stock de ESA empresa. Redistribución: destino = cualquier empresa/sucursal autorizada; origen = custodia real. Devolución reingresa a la propietaria. Nunca filtres custodia/distribución por `entrega.empresa_id = activo.empresa_id` (oculta piezas cross-company). En Vue, `empresaId` = destino y `empresaInventarioId` = propietaria: nunca reutilices una variable para ambos.
