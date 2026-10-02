---
paths:
  - 'resources/js/composables/useReservaBorrador.ts,app/Servicios/ServicioReservas.php,resources/js/pages/{Entregas,Devoluciones,Inventario/Traspasos}/Crear.vue'
---

# Traspasos

## Reservas: token cerrado nunca revive; liberar al salir, en pagehide y tras recargar
Reemplaza "liberar sólo en onBeforeUnmount". `ServicioReservas::obtenerOCrearCabecera()` rechaza (422) un token ya CONSUMIDO o LIBERADO (antes lo reabría → apartados fantasma por recálculos tardíos); una VENCIDA sí se renueva. `liberar($token, $userId, $tipo)` es no-op idempotente para ajeno/otro tipo/vencido/consumido; nunca toca stock ni movimientos. Frontend (`useReservaBorrador`): `liberar()` rota el token; libera al desmontar, en `pagehide` (fetch keepalive, no sendBeacon/beforeunload) y al reabrir la pestaña (token pendiente en sessionStorage `reserva-borrador:<ruta>`); re-aparta al volver del bfcache. El formulario no restaura borrador al recargar → nunca reutiliza el token anterior. Cada `post` de confirmación va entre `iniciarConfirmacion()` y `finalizarConfirmacion()` (onFinish): mientras tanto ninguna limpieza automática libera ni recalcula. Toda limpieza lleva `CABECERA_SEGUNDO_PLANO` (sin toasts). Traspasos libera al quedar sin renglones.
