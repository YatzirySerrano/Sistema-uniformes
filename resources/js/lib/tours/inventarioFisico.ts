import type { Tour } from './tipos';

/**
 * Explica el ciclo de una ronda de inventario físico ya creada (escaneo,
 * captura manual, diferencias y cierre). La selección de empresa/almacén y el
 * snapshot esperado se fijan al CREAR la ronda (`InventarioFisico/Crear.vue`);
 * aquí sólo se documenta lo que existe en esta pantalla.
 */
export const tourInventarioFisicoDetalle: Tour = {
    id: 'inventario-fisico-detalle',
    titulo: 'Cómo funciona una ronda de inventario físico',
    pasos: [
        {
            selector: '[data-tour="resumen-conteo"]',
            titulo: 'Esperadas vs. encontradas',
            texto: 'El universo esperado se fijó al crear la ronda (unidades identificadas por QR de esa empresa/almacén). Aquí ves cuántas ya se encontraron, cuáles faltan y si apareció algo fuera de lo esperado.',
        },
        {
            selector: '[data-tour="cantidad-articulos"]',
            titulo: 'Activos por cantidad',
            texto: 'Para activos que se controlan por cantidad (no por unidad individual), captura manualmente lo que cuentes físicamente; el sistema calcula la diferencia contra lo esperado.',
        },
        {
            selector: '[data-tour="escaneo-qr"]',
            titulo: 'Escaneo por QR',
            texto: 'Activa la cámara para leer el código de cada unidad identificada, o escribe el código manualmente si no puedes escanear. Cada lectura la marca como encontrada al instante.',
        },
        {
            selector: '[data-tour="finalizar-ronda"]',
            titulo: 'Cerrar la ronda',
            texto: 'Al finalizar, firmas como responsable del conteo y la ronda queda cerrada de forma permanente: ya no se puede escanear ni capturar más en ella.',
        },
    ],
};
