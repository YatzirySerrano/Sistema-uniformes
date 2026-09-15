import { onMounted, ref, watch } from 'vue';

export type VistaListado = 'cards' | 'tabla';

/**
 * Recuerda, por módulo, si el usuario prefiere ver un listado en cards o en
 * tabla (`localStorage`, clave `vista:<modulo>`). Es una conveniencia por
 * navegador/dispositivo — nunca se envía al servidor ni se comparte entre
 * usuarios. Cards y tabla deben leer siempre el mismo dataset (misma query,
 * paginación y filtros); este composable sólo decide cuál se pinta.
 *
 * El valor inicial del ref es SIEMPRE `porDefecto`, tanto en SSR como en el
 * primer render del cliente: `localStorage` no existe en Node y, si se leyera
 * de forma síncrona en `setup()`, el HTML del servidor y el del cliente
 * podrían pintar vistas distintas (mismatch de hidratación). La preferencia
 * guardada se aplica recién en `onMounted` — después de que la hidratación ya
 * coincidió con el HTML del servidor — así que el cambio, si lo hay, ocurre
 * como una actualización reactiva normal, no como parte del diff de
 * hidratación.
 */
export function useVistaPreferida(
    modulo: string,
    porDefecto: VistaListado = 'cards',
) {
    const clave = `vista:${modulo}`;

    const vista = ref<VistaListado>(porDefecto);

    onMounted(() => {
        try {
            const guardada = localStorage.getItem(clave);
            if (guardada === 'cards' || guardada === 'tabla') {
                vista.value = guardada;
            }
        } catch {
            // Almacenamiento no disponible (privado/bloqueado): se usa el valor por defecto.
        }
    });

    watch(vista, (valor) => {
        try {
            localStorage.setItem(clave, valor);
        } catch {
            // Ignorar: preferencia de UI, no crítica.
        }
    });

    return vista;
}
