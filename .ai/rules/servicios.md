---
paths:
    - 'resources/js/pages/Activos/Formulario.vue,app/Http/Requests/Activos/{GuardarActivoRequest,AgregarExistenciasRequest}.php,app/Servicios/ServicioEtiquetasQr.php'
---

# Servicios

## QR de UnidadActivo: siempre existe desde el alta, no hay "QR pendiente"

Toda `UnidadActivo` recibe `public_token` (UUID, columna NOT NULL UNIQUE) en `RegistrarUnidadesActivo` — su QR está disponible desde el instante del alta y se deriva SIEMPRE on-demand de `public_token` vía `ServicioEtiquetasQr` (sin PNG persistido, sin estado "generado/pendiente" en BD, sin segundo token/UUID/código). No existe ninguna ruta de "generar QR" ni "regenerar".

La casilla del alta/agregar-existencias se llama `abrir_etiquetas` (antes `generar_qr`) en request y form: NO controla la existencia del QR, sólo si al guardar se abre el PDF de etiquetas (`etiquetasUrl` en flash → `flashEtiquetas.ts`) para imprimir ahora. Imprimir/reimprimir usa siempre el mismo `public_token`. Las unidades históricas ya tienen token, no hay proceso de regularización.
