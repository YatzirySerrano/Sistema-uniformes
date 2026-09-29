---
paths:
  - 'app/{Acciones/RedistribuirCustodia.php,Acciones/RegistrarEntregaFirmada.php,Servicios/ServicioCustodiaColaborador.php,Http/Requests/Entregas/GuardarEntregaRequest.php,Http/Controllers/EntregaController.php,Acciones/CambiarServicioColaborador.php}'
---

# Entregas Controllers

## Redistribución de custodia: entrega sin salida de almacén
Dos vías por permiso efectivo (nunca por rol): `entregas.crear` = salida de almacén (`CrearEntregaUniforme`, descuenta stock) y `entregas.redistribuir` = `RedistribuirCustodia` (origen = `Colaborador` ligado al usuario vía `colaboradores.usuario_id` en la empresa del destinatario; NO toca inventario). Se distingue por `entregas_uniformes.colaborador_origen_id` (almacen_id NULL); cada renglón hijo apunta a `detalles_entrega.detalle_origen_id` (FIFO). Custodia por cantidad = entregado − devuelto confirmado − incidencias − redistribuido (hijos no anulados), SIEMPRE vía `ServicioCustodiaColaborador` (también el tope de `RegistrarDevolucion`). Unidades: sólo cambia `colaborador_id`. Locks: ambos colaboradores por id asc + renglones origen + unidad. `CambiarServicioColaborador` rechaza SALIR de un servicio con custodia pendiente (null→X sí se permite). Conjuntos sólo desde almacén.
