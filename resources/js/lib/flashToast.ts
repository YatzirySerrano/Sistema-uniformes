import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Muestra los toast que el backend envía en `flash.toast` tras cada respuesta
 * de Inertia (`->with('toast', [...])`). Se escucha el evento `success` porque
 * es el que expone la página recién recibida; `navigate` incluiría también la
 * navegación con historial y duplicaría el mensaje.
 */
export function initializeFlashToast(): void {
    router.on('success', (event) => {
        const props = (event as CustomEvent).detail?.page?.props as
            | { flash?: { toast?: FlashToast | null } }
            | undefined;

        const data = props?.flash?.toast;

        if (data && data.message) {
            toast[data.type](data.message);
        }
    });
}
