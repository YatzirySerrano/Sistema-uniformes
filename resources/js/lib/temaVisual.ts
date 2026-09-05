import { router } from '@inertiajs/vue3';

/**
 * Personalización visual GLOBAL (nunca por empresa): aplica en el cliente
 * los custom properties CSS que ya vienen server-rendered en
 * `resources/views/app.blade.php` (para el primer pintado sin parpadeo), y
 * los reaplica tras cada navegación Inertia o guardado en `Configuracion`
 * sin necesitar un refresh completo del navegador.
 */
type VariablesTema = Record<string, string>;
type TemaVisual = { claro: VariablesTema; oscuro: VariablesTema };

// Custom properties que sólo tienen sentido en tema claro (fondos): en
// oscuro se retira el valor inline para que vuelva a mandar el bloque
// `.dark` de app.css y así no romper el modo oscuro.
const PROPIEDADES_SOLO_CLARO = [
    '--background',
    '--card',
    '--popover',
    '--sidebar',
    '--sidebar-background',
    '--secondary',
    '--secondary-foreground',
    '--accent',
    '--accent-foreground',
];

let temaActual: TemaVisual | null = null;

function esOscuroActivo(): boolean {
    return document.documentElement.classList.contains('dark');
}

function aplicar(): void {
    if (!temaActual) {
        return;
    }

    const raiz = document.documentElement.style;
    const oscuro = esOscuroActivo();

    for (const [propiedad, valor] of Object.entries(temaActual.claro)) {
        if (PROPIEDADES_SOLO_CLARO.includes(propiedad)) {
            continue;
        }

        raiz.setProperty(propiedad, valor);
    }

    for (const propiedad of PROPIEDADES_SOLO_CLARO) {
        if (oscuro || !(propiedad in temaActual.claro)) {
            raiz.removeProperty(propiedad);
        } else {
            raiz.setProperty(propiedad, temaActual.claro[propiedad]);
        }
    }
}

function leerPropsIniciales(): TemaVisual | null {
    const elementoApp = document.getElementById('app');
    const datosPagina = elementoApp?.dataset.page;

    if (!datosPagina) {
        return null;
    }

    try {
        const pagina = JSON.parse(datosPagina) as {
            props?: { temaVisual?: TemaVisual };
        };

        return pagina.props?.temaVisual ?? null;
    } catch {
        return null;
    }
}

export function initializeTemaVisual(): void {
    if (typeof window === 'undefined') {
        return;
    }

    temaActual = leerPropsIniciales();
    aplicar();

    router.on('success', (event) => {
        const props = event.detail.page.props as { temaVisual?: TemaVisual };

        if (props.temaVisual) {
            temaActual = props.temaVisual;
            aplicar();
        }
    });
}

/**
 * Se llama cada vez que cambia el modo claro/oscuro (`useAppearance`) para
 * que los fondos de marca se retiren/reaplique según corresponda.
 */
export function reaplicarTemaVisual(): void {
    aplicar();
}
