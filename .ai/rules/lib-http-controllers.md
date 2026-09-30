---
paths:
  - 'resources/js/composables/useReservaBorrador.ts,resources/js/lib/avisoSinPermiso.ts,app/Http/Controllers/EntregaController.php'
---

# Lib Http Controllers

## Limpieza best-effort nunca dispara el toast de 403
`useReservaBorrador.liberar()` sólo envía `DELETE …/reserva/{token}` si ese token llegó a reservar algo, y lo marca con `CABECERA_SEGUNDO_PLANO` (`X-Peticion-Segundo-Plano: 1`), que `avisoSinPermiso` ignora. Úsala SÓLO en limpieza interna (nunca en acciones o buscadores del usuario: sus 403 deben seguir avisándose). `EntregaController::liberarReserva` autoriza `create` (almacén O redistribución), porque sólo libera apartados propios del usuario. Origen: salir de Nueva entrega mostraba "No tienes permiso…" a quien sólo tiene `entregas.redistribuir`.
