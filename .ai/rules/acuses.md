---
paths:
  - 'app/Servicios/ServicioEvidencias.php,app/Servicios/ServicioAcusePdf.php,app/Servicios/ServicioAcuseDevolucionPdf.php,resources/views/acuses/comprobante.blade.php,resources/views/acuses/comprobante-devolucion.blade.php'
---

# Acuses

## Evidencias en el comprobante PDF: data URI por renglón, snapshot con hash+mime, históricos intactos
`ServicioEvidencias::dataUriParaPdf(Evidencia, maxLado=700): ?string` lee el archivo privado, lo reduce con GD (JPEG q70) y devuelve un `data:` URI; NUNCA lanza — si el archivo físico no existe devuelve null + Log::warning y el PDF muestra "Evidencia no disponible". `ServicioAcusePdf` / `ServicioAcuseDevolucionPdf` inyectan `ServicioEvidencias` y pasan `evidenciasPorItem` a la vista (indexado por posición del detalle ordenado por id). El snapshot (`construirSnapshot` en ambos `ConfirmarAcuse*`) guarda por item `evidencias => [{hash_sha256, mime}]` (referencia determinista, NO url/path); orden determinista `->sortBy('id')->values()`. La validez del `hash_documento` histórico NO depende de que el archivo exista. Acuses viejos sin la clave `evidencias` siguen generando PDF (todo tras `?? []` / `@if`). En los `->map()` sobre `->sortBy('id')->values()` hay que tipar el closure (`fn (DetalleEntrega $d)`, `fn (Evidencia $e)`) o PHPStan marca "unresolvable type".
