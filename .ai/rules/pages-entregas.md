---
paths:
    - 'app/Acciones/RegistrarEntregaFirmada.php,app/Acciones/ConfirmarAcuseRecepcion.php,app/Http/Controllers/EntregaController.php,app/Http/Requests/Entregas/GuardarEntregaRequest.php,resources/js/pages/Entregas/Crear.vue'
---

# Pages Entregas

## Entregas: flujo ÚNICO (registrar + firmar en una sola operación)

El alta HTTP de una entrega (`POST /entregas` → `EntregaController::store`) SIEMPRE trae `firma`, `firma_operador` y `aceptacion` y las delega en `App\Acciones\RegistrarEntregaFirmada`, que dentro de UNA transacción llama `CrearEntregaUniforme::ejecutar()` + `ConfirmarAcuseRecepcion::confirmarEnTransaccion()`. Si algo falla (firma inválida, sin aceptación, sin existencias) se revierte TODO: no queda inventario descontado ni entrega huérfana. `ConfirmarAcuseRecepcion` está partido: `confirmarEnTransaccion()` (transaccional, sin PDF/correo) + `finalizarAcuse()` (post-commit: materializa PDF + encola correo) + `ejecutar()` = ambos (flujo histórico de `AcuseController::confirmar`). `EstadoEntrega::PendienteFirma` NO se elimina: es transitorio dentro de la transacción y sostiene las entregas históricas + su ruta de firma diferida (`entregas/{entrega}/firmar`, intacta). Idempotencia: `idempotency_key` (uuid del form) + `Cache::add("entregas:idempotencia:{key}")`; se libera con `Cache::forget` si la acción falla. Frontend `Entregas/Crear.vue` = wizard de 3 pasos (datos / elementos / revisión+firmas); el botón "Confirmar entrega" se bloquea sin las 2 firmas + aceptación. Paso de firma muestra la identidad del colaborador y "Ver INE" (ver regla de documento-identidad).
