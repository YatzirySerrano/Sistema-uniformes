---
paths:
  - 'app/Acciones/RegistrarDevolucionFirmada.php,app/Acciones/ConfirmarAcuseDevolucion.php,app/Acciones/RegistrarDevolucion.php,app/Http/Controllers/DevolucionController.php,resources/js/pages/Devoluciones/Crear.vue'
---

# Devoluciones

## Devoluciones: flujo ÚNICO (wizard = registrar + confirmar en una sola transacción)
Espejo exacto del flujo de Entregas. `POST /devoluciones` → `DevolucionController::store` SIEMPRE trae `firma`, `firma_operador`, `aceptacion` (reglas obligatorias en `GuardarDevolucionRequest`) y delega en `App\Acciones\RegistrarDevolucionFirmada`, que en UNA `DB::transaction` coordinadora llama `RegistrarDevolucion::crearYRegistrar()` (sin transacción propia) + `ConfirmarAcuseDevolucion::confirmarEnTransaccion()`; el PDF/correo van después del commit (`finalizarAcuse()`). Redirige a `devoluciones.show` con toast que nombra el folio. Limpieza de archivos (un archivo → un dueño): evidencias las escribe/limpia el CONTROLLER en su `catch`; firmas las gestiona `confirmarEnTransaccion` en su propio try/catch; las Actions NO tocan archivos. Camino legado intacto: `RegistrarDevolucion::ejecutar()` (wrapper con transacción) + `devoluciones/{d}/firmar` para devoluciones `pendiente_firma` antiguas. Frontend `Devoluciones/Crear.vue` = wizard de 3 pasos (datos / artículos+condición+evidencias / revisión+2 firmas), sin saltar pasos; `textoConsentimiento` viene del controller (`ConfirmarAcuseDevolucion::TEXTO_CONSENTIMIENTO`).
