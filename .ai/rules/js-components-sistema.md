---
paths:
    - 'resources/js/composables/useVistaPreferida.ts,resources/js/components/sistema/SelectorVista.vue'
---

# Js Components Sistema

## useVistaPreferida: localStorage se lee en onMounted, nunca en setup() síncrono

Causa raíz de un hydration mismatch SSR/cliente real (ronda 2026-09-15): `useVistaPreferida` leía `localStorage` de forma síncrona en el cuerpo de la función (tiempo de `setup()`), sólo protegido por try/catch, sin guardia de `window`. En SSR (Node) eso siempre caía al default; en el cliente devolvía la preferencia guardada — el HTML del servidor y el primer render del cliente podían pintar cards vs tabla distinto. Fix: el `ref` SIEMPRE arranca en `porDefecto` (igual en SSR y primer render cliente) y la lectura real de `localStorage` se mueve a `onMounted` (ya pasada la hidratación), así el cambio ocurre como una actualización reactiva normal. Mismo patrón aplicado a `SidebarMenuSkeleton.vue` (`Math.random()` en un `computed` de setup → ahora un `ref` fijo que se aleatoriza en `onMounted`). Regla para cualquier composable/componente nuevo: nunca leer `localStorage`/`matchMedia`/generar valores no deterministas directamente en `setup()`/`computed` si el resultado afecta el DOM renderizado — usar `onMounted`, con un valor inicial idéntico en servidor y cliente.
