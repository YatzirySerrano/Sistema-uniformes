---
paths:
  - 'app/{Models/{Reserva,RenglonReserva}.php,Enums/TipoReserva.php,Servicios/ServicioReservas.php,Acciones/{ReservarInventarioEntrega,ReservarCustodiaDevolucion}.php,Console/Commands/LimpiarReservasVencidas.php},resources/js/composables/useReservaBorrador.ts,resources/js/components/sistema/ApartadoTemporalBanner.vue'
---

# Composables Js Components Sistema

## Apartado temporal (TTL) de inventario/custodia en Entregas y Devoluciones
`Reserva` + `RenglonReserva` (tablas `reservas_inventario`/`reservas_inventario_renglones`) implementan un "hold" temporal de 10 min (`Reserva::DURACION_MINUTOS`) mientras un usuario prepara una Entrega o Devolución — nunca la operación en sí. `TipoReserva::Entrega` aparta STOCK de almacén; `TipoReserva::Devolucion` aparta el DERECHO a devolver una custodia pendiente concreta (`DetalleEntrega`) — NUNCA stock. Una reserva sólo bloquea mientras `Reserva::scopeActiva()` (no consumida/liberada/vencida); una fila vencida NUNCA bloquea aunque siga existiendo hasta que `php artisan reservas:limpiar` (cron cada 15 min) la borre — la corrección nunca depende de esa limpieza.

`ReservarInventarioEntrega`/`ReservarCustodiaDevolucion` recalculan TODA la reserva de un borrador de forma atómica (una cabecera con sus líneas, nunca N reservas sueltas): agregan demanda por clave `activo_id+talla_id` (o `detalle_entrega_id`) sumando renglones sueltos + componentes de TODOS los conjuntos del mismo borrador antes de comparar contra existencia — así detectan "artículo suelto + conjunto consumen la misma variante" (antes invisible). Bloquean saldos en orden ESTABLE (`sort($claves)`) vía `ServicioInventario::lockearSaldo()` (compartido con `registrarMovimiento()`) para reducir deadlocks entre reservas concurrentes.

La reserva es SIEMPRE una capa previa de UX/concurrencia: `CrearEntregaUniforme`/`RegistrarDevolucion` reciben un `?string $reservaToken` opcional, validan con `ServicioReservas::bloquearActivaPorToken()` (rechaza si no existe/no es del usuario/venció) y la marcan `consumida_en` al terminar, pero conservan ÍNTEGRAS sus propias validaciones/locks autoritativos — nunca confían sólo en que la reserva diga "ok".

Endpoints de disponibilidad reservation-aware (`EntregaController::disponibilidad`, `ActivoController::buscar`, `ConjuntoController::disponibilidad`, `UnidadActivoController::buscar`) aceptan `?token=` opcional para no descontarse a sí mismos. Al consultarlos para una PÁGINA de resultados (varias filas), usar los métodos BATCH de `ServicioReservas` (`demandaCantidadPorActivos()`, `unidadesApartadasPorActivosEnAlmacen()`) — nunca los de una sola clave (`demandaCantidadDeOtros()`, `unidadesApartadasPorOtros()`) dentro de un `->map()` por fila: eso reintroduce un N+1 ya detectado y cubierto por `tests/Feature/BuscadoresCatalogoTest.php`.

Frontend: `composables/useReservaBorrador.ts` (ciclo de vida: token, countdown, `reservarConRetraso` debounced, `reservar` síncrono antes de avanzar a firmas, `liberar` best-effort en `onBeforeUnmount` — NUNCA `beforeunload`, NUNCA heartbeat automático; "Extender" es SIEMPRE una acción explícita del usuario) + `components/sistema/ApartadoTemporalBanner.vue` (UI del countdown), compartidos por `Entregas/Crear.vue` y `Devoluciones/Crear.vue`. No extender este patrón a Traspasos/Inventario físico/Ajustes/Entrada de stock sin discutirlo primero — quedó deliberadamente limitado a Entregas/Devoluciones.

Tests de concurrencia/expiración/agregación: `tests/Feature/ReservaEntregaTest.php` (13 casos) y `ReservaDevolucionTest.php` (11 casos) — mantenerlos verdes es la señal de que el diseño de locks/agregación sigue siendo correcto.
