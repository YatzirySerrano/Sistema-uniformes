---
paths:
    - 'resources/js/pages/Activos/Detalle.vue,resources/js/pages/Inventario/Index.vue,resources/js/components/sistema/AgregarExistenciasDialog.vue'
---

# Inventario Js Components Sistema

## talla_id "sin variante" es siempre null en el payload, nunca 0

Bug real corregido (ronda QA 2026-09-15): `Activos/Detalle.vue` armaba el form de "Configurar mínimo" con `talla_id: s.talla_id ?? 0` para una fila sin variante (talla_id NULL en el saldo). El backend (`InventarioController::validarOperacion`, regla `nullable` + `Rule::exists('activo_talla','talla_id')`) rechaza 0 porque nunca existe una fila con talla_id=0 — "sin variante" no tiene comodín (ver [[modulos-nuevos]]: `saldos_inventario.talla_ref = COALESCE(talla_id, 0)` es sólo un truco de índice único en BD, nunca un valor real de negocio). El síntoma era un modal "congelado": el diálogo sólo mostraba `formMinimo.errors.minimo`, nunca `errors.talla_id`, así que el 422 real quedaba invisible.

Regla: cualquier form que reconstruya `talla_id` a partir de un dato ya cargado (saldo, unidad, etc.) debe propagar `null` tal cual, nunca `?? 0` ni otro comodín — el patrón correcto ya existía en `Inventario/Entrada.vue` (`talla_id: it.talla_id || null`). Además, todo diálogo con más de un campo debe mostrar un resumen de error genérico (no sólo el campo más "obvio") para que un error en un campo inesperado nunca deje el modal sin feedback — patrón agregado aquí: `hayErroresMinimo` + mensaje genérico cuando hay errores pero ninguno es el de los campos ya cubiertos inline.
