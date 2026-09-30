---
paths:
  - 'app/Servicios/ServicioFirmaColaborador.php,app/Soporte/ValidadorFirma.php,app/Acciones/ConfirmarAcuse*.php,app/Http/Requests/Concerns/ValidaFirmaColaborador.php,resources/js/components/sistema/FirmaColaborador.vue,resources/js/components/sistema/PadFirma.vue'
---

# Sistema Js Components Sistema

## Firma de quien recibe/devuelve: dibujada o archivo (sin migración); pad oscuro exporta trazo oscuro
`firma_metodo` dibujada|archivo (default dibujada); `firma` y `firma_archivo` mutuamente prohibidas (trait `ValidaFirmaColaborador`, png/jpg/webp/pdf, 5 MB). `ServicioFirmaColaborador::preparar()` valida por contenido (`ValidadorFirma::validarArchivo`, finfo + magic): imagen → PNG normalizado con GD en `ruta_firma`; PDF → íntegro, `ruta_firma` apunta al PDF (no se rasteriza; el comprobante dice "documento adjunto"). El original queda como `Evidencia` del acuse (`origen=firma_archivo`, morphOne `firmaArchivo()`), que ES el registro del método (`metodoFirma()`). La firma del encargado no cambia. `PadFirma` guarda TRAZOS: se pintan claros en modo oscuro, pero `obtenerDataUrl()` re-dibuja con `COLOR_TRAZO_DOCUMENTO` sobre transparente (lib/trazosFirma.ts); ya no usa `respaldo` data URL.
