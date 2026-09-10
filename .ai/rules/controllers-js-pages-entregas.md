---
paths:
  - 'app/Http/Controllers/EntregaController.php,resources/js/pages/Entregas/Crear.vue'
---

# Controllers Js Pages Entregas

## INE durante la entrega: el backend YA persiste bien; el fallo era de feedback
Causa raíz demostrada (test `IdentidadEntregaTest` con multipart real + verificación vía `ServicioExpediente::payload()`, la MISMA consulta de la pantalla de Expediente): el backend end-to-end (request/auth/validación/storage/transacción/categoría/relación/query) es CORRECTO. Lo que se veía como "guardó pero no aparece" era doble: (a) un archivo > 10 MB se rechazaba con 422 en un texto diminuto mientras el archivo seguía "seleccionado"; (b) un guardado correcto no daba confirmación ni forzaba el cambio del bloque de identidad. Fix: `guardarDocumentoIdentidad` verifica persistencia (documento + versión + `Storage::exists`) y devuelve `{ok:true, documento:{…}}`; si no persistió → `Log::error` + `ExcepcionDeNegocioSimple`. `Entregas/Crear.vue::guardarIne` valida 10 MB en cliente ANTES de enviar, muestra error prominente (403 con mensaje específico, 422 = `errors.archivo[0]`) y en éxito muestra caja verde + pinta `j.documento` sin recargar el wizard. NO se creó ruta paralela ni migración.
