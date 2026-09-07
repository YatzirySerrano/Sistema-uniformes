import type { Tour } from './tipos';

/**
 * Explica el POR QUÉ de la doble firma + consentimiento: ni la entrega ni la
 * devolución se consideran concretadas (ni se mueve inventario en el caso de
 * la devolución) hasta que ambas partes firman.
 */
export const tourFirmaEntrega: Tour = {
    id: 'firma-entrega',
    titulo: 'Cómo funciona la firma de una entrega',
    pasos: [
        {
            selector: '[data-tour="resumen-firma"]',
            titulo: 'Revisa antes de firmar',
            texto: 'Verifica que el colaborador y los activos listados sean correctos: este contenido queda congelado para siempre en el acuse en el momento de firmar.',
        },
        {
            selector: '[data-tour="firma-colaborador"]',
            titulo: 'Firma de quien recibe',
            texto: 'El colaborador debe marcar la casilla de responsabilidad y firmar en el recuadro. Sin esto, la entrega sigue "Pendiente de firma".',
        },
        {
            selector: '[data-tour="firma-operador"]',
            titulo: 'Firma de quien entrega',
            texto: 'El encargado que hace la entrega también firma, como evidencia de quién la realizó físicamente.',
        },
    ],
};

export const tourFirmaDevolucion: Tour = {
    id: 'firma-devolucion',
    titulo: 'Cómo funciona la firma de una devolución',
    pasos: [
        {
            selector: '[data-tour="resumen-firma"]',
            titulo: 'Revisa antes de firmar',
            texto: 'El inventario NO se restaura al registrar la devolución: se aplica hasta que ambas firmas y el consentimiento existen, aquí mismo.',
        },
        {
            selector: '[data-tour="firma-colaborador"]',
            titulo: 'Firma de quien devuelve',
            texto: 'El colaborador que devuelve los activos firma primero, confirmando qué está entregando de vuelta.',
        },
        {
            selector: '[data-tour="firma-operador"]',
            titulo: 'Firma de quien recibe',
            texto: 'Quien recibe la devolución marca la casilla de responsabilidad y firma. En ese momento —y no antes— el inventario se actualiza.',
        },
    ],
};
