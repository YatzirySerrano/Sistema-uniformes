---
paths:
    - 'app/Acciones/{DesmarcarUnidadPresente,MarcarUnidadPresente}.php,resources/js/pages/InventarioFisico/Detalle.vue,resources/js/composables/{useCamaraFoto,useEscanerQr}.ts'
---

# Composables

## Inventario físico Presente/Deshacer + cambiar cámara

El bug del contador "Presente" era 100% frontend (backend ya idempotente + UNIQUE): `Detalle.vue` ahora usa copia local reactiva `filas`, candado por-fila `filasEnCurso:Set` (no global), aplica `data.unidad` de la respuesta, botón "✓ Presente + Deshacer". Acción `DesmarcarUnidadPresente` (espejo de `MarcarUnidadPresente`, mismo orden de locks Ronda→fila, sólo `esperada=true`, rechaza ronda Finalizada) + `DELETE /inventarios-fisicos/{ronda}/unidades/{unidad}/presente`. Contadores SIEMPRE de la respuesta del servidor, nunca `++`. Cámaras: `useCamaraFoto`/`useEscanerQr` exponen `camaras/camaraActualId/puedeCambiarCamara/cambiarCamara()` (enumerateDevices tras permiso, `deviceId` exacto, `localStorage('camara:preferida')`); helper puro `composables/camara/dispositivos.ts`; botón en `CapturaEvidencia.vue`.
