---
paths:
  - 'app/Http/Requests/Entregas/GuardarEntregaRequest.php,app/Http/Controllers/EntregaController.php,resources/js/pages/Entregas/Crear.vue'
---

# Js Pages Entregas

## Entrega.servicio_id se deriva SIEMPRE del colaborador, nunca del frontend
El servicio de destino de una entrega (`entregas_uniformes.servicio_id`, snapshot histórico) NO lo elige el formulario. `GuardarEntregaRequest` ya no tiene regla `servicio_id`; `EntregaController::store` pasa `$colaborador->servicio_actual_id` a `RegistrarEntregaFirmada`. Cualquier `servicio_id` en el body se ignora (queda fuera de `validated()`). Si el colaborador tiene servicio vigente pero él o su contrato están inactivos, `GuardarEntregaRequest::withValidator` añade error humano en la clave `servicio_id` (no 500) y no se registra la entrega — hay que reasignar al colaborador. Colaborador sin servicio (RH/administrativo) => entrega válida con `servicio_id = NULL`; nunca se infiere por sucursal. `Entregas/Crear.vue` muestra el servicio como bloque de sólo lectura derivado de `colaborador.servicio_actual`.
