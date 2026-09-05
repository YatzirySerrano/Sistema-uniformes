import { ref, watch } from 'vue';

export type VistaListado = 'cards' | 'tabla';

/**
 * Recuerda, por módulo, si el usuario prefiere ver un listado en cards o en
 * tabla (`localStorage`, clave `vista:<modulo>`). Es una conveniencia por
 * navegador/dispositivo — nunca se envía al servidor ni se comparte entre
 * usuarios. Cards y tabla deben leer siempre el mismo dataset (misma query,
 * paginación y filtros); este composable sólo decide cuál se pinta.
 */
export function useVistaPreferida(
    modulo: string,
    porDefecto: VistaListado = 'cards',
) {
    const clave = `vista:${modulo}`;

    let inicial: VistaListado = porDefecto;
    try {
        const guardada = localStorage.getItem(clave);
        if (guardada === 'cards' || guardada === 'tabla') {
            inicial = guardada;
        }
    } catch {
        // Almacenamiento no disponible (privado/bloqueado): se usa el valor por defecto.
    }

    const vista = ref<VistaListado>(inicial);

    watch(vista, (valor) => {
        try {
            localStorage.setItem(clave, valor);
        } catch {
            // Ignorar: preferencia de UI, no crítica.
        }
    });

    return vista;
}
