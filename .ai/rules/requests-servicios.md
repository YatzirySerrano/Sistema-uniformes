---
paths:
  - 'app/Http/Controllers/ServicioController.php,app/Acciones/AsignarColaboradoresServicio.php,app/Http/Requests/Servicios/AsignarColaboradoresServicioRequest.php'
---

# Requests Servicios

## Servicio → Asignar colaboradores: lote que reutiliza CambiarServicioColaborador
`POST servicios/{servicio}/colaboradores` (permiso: `colaboradores.editar` + `view` del servicio) asigna colaboradores en lote. Fuente de verdad única sigue siendo `colaboradores.servicio_actual_id` — NO hay pivot `colaborador_servicio`. `AsignarColaboradoresServicio::ejecutar()` corre UNA transacción y delega colaborador por colaborador en `CambiarServicioColaborador` (misma validación empresa/contrato + auditoría individual `colaboradores/cambiar_servicio`, nunca un registro agregado). El Form Request revalida cada id contra la empresa del servicio (derivada de `contrato->empresa_id`) y las sucursales autorizadas del usuario (`AccesoEmpresa`) — manipular `colaborador_ids[]` no permite cross-empresa. "Quitar del servicio" NO es endpoint nuevo: es `POST colaboradores/{c}/servicio` con `servicio_id=null`. El detalle del servicio lista los colaboradores asignados con paginación server-side (`colab_page`) + búsqueda (`colab_buscar`).
