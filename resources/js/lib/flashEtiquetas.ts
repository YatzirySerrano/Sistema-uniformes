import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * Abre el PDF de etiquetas QR que el backend deja en `flash.etiquetasUrl`
 * tras una acción Inertia exitosa (alta de activo / existencias con "Generar
 * etiquetas QR" marcado). El PDF NUNCA viaja en la respuesta de la petición
 * Inertia — se navega aparte a la URL, en una pestaña nueva, para que el
 * navegador la maneje de forma nativa en vez de que Inertia intente
 * renderizar los bytes del PDF como si fueran una página.
 *
 * Si el navegador bloquea la ventana emergente, se ofrece un toast con un
 * botón para abrirla manualmente en vez de depender de la apertura automática.
 */
export function initializeFlashEtiquetas(): void {
    router.on('success', (event) => {
        const props = (event as CustomEvent).detail?.page?.props as
            | { flash?: { etiquetasUrl?: string | null } }
            | undefined;

        const url = props?.flash?.etiquetasUrl;
        if (!url) return;

        const ventana = window.open(url, '_blank', 'noopener');

        if (!ventana) {
            toast('Etiquetas QR listas', {
                description:
                    'El navegador bloqueó la ventana emergente. Ábrela manualmente.',
                action: {
                    label: 'Abrir etiquetas',
                    onClick: () => window.open(url, '_blank', 'noopener'),
                },
            });
        }
    });
}
