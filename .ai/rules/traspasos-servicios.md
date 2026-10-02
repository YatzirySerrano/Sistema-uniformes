---
paths:
  - 'resources/js/composables/useDisponibilidadViva.ts,resources/js/pages/{Entregas,Devoluciones,Inventario/Traspasos}/Crear.vue,app/Servicios/ServicioReservas.php'
---

# Traspasos Servicios

## Disponibilidad viva: polling de SÓLO LECTURA, nunca heartbeat
Entregas/Traspasos/Devoluciones refrescan la disponibilidad cada 8 s (paso 2, pestaña visible, sin confirmar) con `useDisponibilidadViva` contra GET `entregas/disponibilidad`, `inventario/traspasos/disponibilidad` (origen) y `devoluciones/disponibilidad` (CUSTODIA, nunca stock), siempre con `?token=` propio y `activo_ids[]` batch (`ServicioReservas::disponibilidadEfectivaEnAlmacen`/`custodiaApartadaPorDetalles`). Nunca sondear el endpoint de reservar: renovaría el TTL. La respuesta de reservar es más fresca: se fusiona en el mapa e `invalidar()` descarta el sondeo en vuelo. Nunca cambiar la cantidad capturada; sólo marcarla inválida. Mensajes de "no alcanza" sólo vía `lib/mensajesDisponibilidad.ts` (singular/plural, causa "otras operaciones" si `apartado_por_otros > 0` o la cifra bajó). Redistribución (custodia) no participa.
