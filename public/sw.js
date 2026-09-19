// Service worker mínimo: existe sólo para que el navegador considere el
// sitio "instalable" como app (PWA). Deliberadamente NO cachea nada: este es
// un sistema de inventario en tiempo real, y servir una página o una
// respuesta vieja desde caché podría mostrar existencias, entregas o
// condiciones desactualizadas — un riesgo real que no vale la pena correr
// por una mejora cosmética. Cada solicitud sigue yendo siempre a la red.
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Sin `event.respondWith(...)`: deja pasar la solicitud tal cual a la
    // red, como si este service worker no existiera. Sólo su presencia ya
    // satisface el requisito de instalabilidad de los navegadores.
});
