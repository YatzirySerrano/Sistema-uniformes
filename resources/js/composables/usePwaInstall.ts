import { computed, ref } from 'vue';

/**
 * Evento no estándar (Chrome/Edge/otros basados en Chromium) que el
 * navegador dispara cuando decide que el sitio es instalable. No existe un
 * tipo oficial en el DOM de TypeScript, por eso se declara aquí.
 */
type EventoAntesDeInstalar = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

// Estado a nivel de módulo (un solo listener para toda la sesión del
// navegador, compartido por cualquier componente que use este composable) —
// mismo patrón que `useAppearance.ts` para estado global fuera de un store.
const eventoDiferido = ref<EventoAntesDeInstalar | null>(null);
const yaInstalada = ref(false);

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (evento) => {
        // Evita el mini-banner nativo del navegador: el propio sistema
        // ofrece su botón "Instalar app" cuando tenga sentido.
        evento.preventDefault();
        eventoDiferido.value = evento as EventoAntesDeInstalar;
    });

    window.addEventListener('appinstalled', () => {
        eventoDiferido.value = null;
        yaInstalada.value = true;
    });
}

/**
 * Instalar el sistema como app de escritorio/móvil. `puedeInstalar` sólo es
 * verdadero cuando el navegador realmente lo soporta y lo permite en este
 * momento — si no, ningún componente debe mostrar el botón (nunca una
 * experiencia rota en navegadores que no lo soportan).
 */
export function usePwaInstall() {
    const puedeInstalar = computed(
        () => eventoDiferido.value !== null && !yaInstalada.value,
    );

    async function instalar(): Promise<void> {
        if (!eventoDiferido.value) {
            return;
        }

        await eventoDiferido.value.prompt();
        await eventoDiferido.value.userChoice;
        eventoDiferido.value = null;
    }

    return { puedeInstalar, instalar };
}
