---
paths:
  - 'bootstrap/app.php,app/Soporte/AccesoNoAutorizado.php,resources/js/lib/avisoSinPermiso.ts,resources/js/pages/Errores/SinPermiso.vue'
---

# Errores

## 403 amigable sin perder el 403 real
`withExceptions()->respond()` sólo cambia la PRESENTACIÓN del 403 (el código sigue 403): GET navegación → Inertia `Errores/SinPermiso`; JSON o cualquier método no-GET (acciones Inertia) → `{message}` en español; el cliente (`avisoSinPermiso.ts`) lo convierte en toast vía `router.on('httpException')` y un wrapper de `fetch`. Mensajes técnicos («This action is unauthorized.», claves de permiso, clases) se sustituyen en `AccesoNoAutorizado::mensajePara`. Trampa: antes de renderizar se hace `forgetInstance(SsrState::class)` — en tests (misma app, dev server Vite activo) el `<head>` SSR de la página anterior se reusaba y filtraba su `<title>`.
