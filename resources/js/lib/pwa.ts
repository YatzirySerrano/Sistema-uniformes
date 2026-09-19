/**
 * Registra el service worker mínimo que hace instalable el sitio como app
 * (ver `public/sw.js` — deliberadamente no cachea nada). Si el navegador no
 * soporta service workers, no hace nada: el sitio sigue funcionando igual,
 * simplemente no se podrá instalar.
 */
export function initializeServiceWorker(): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Silencioso a propósito: un fallo aquí nunca debe romper la
            // aplicación, sólo significa que no quedará instalable.
        });
    });
}
