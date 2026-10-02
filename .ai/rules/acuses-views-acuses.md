---
paths:
  - 'resources/views/acuses/comprobante.blade.php,resources/views/acuses/comprobante-devolucion.blade.php'
---

# Acuses Views Acuses

## Comprobantes PDF (Dompdf): textos largos en el bloque de firmas
El bloque de firmas usa `table.firmas` con `table-layout: fixed` (dos columnas al 50 %), para que un texto largo nunca ensanche una columna sobre la otra. La firma por documento adjunto va en `.firma-documento`: caja que crece con su contenido, sin la altura fija de `.firma-img`. El nombre del archivo y el SHA-256 van en `.valor-largo` con `overflow-wrap: anywhere` (soportado por Dompdf 3.x), completos y nunca truncados. Verificar siempre sobre el PDF real (Dompdf), no sólo el HTML.
