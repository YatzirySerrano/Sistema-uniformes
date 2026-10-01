---
paths:
  - 'app/Acciones/{VerificarExistenciaInventarioFisico,FinalizarRondaInventarioFisico}.php,app/Models/InventarioFisicoExistencia.php,app/Servicios/ServicioResumenInventarioFisico.php,resources/js/pages/InventarioFisico/Detalle.vue'
---

# Servicios Js Pages Inventario Fisico

## «No fue posible verificar» sin columnas nuevas: estado derivado + motivo en bitácora
Estado de un renglón por cantidad, derivado (sin migración): pendiente = cantidad_contada NULL y verificada_en NULL; contado = cantidad_contada presente; no verificable = cantidad_contada NULL y verificada_en presente (quién = verificada_por). Usa `esNoVerificable()` / `resuelta()` / `scopePendientes()`; nunca escribas verificada_en sin cantidad salvo en `marcarNoVerificable()`. El motivo opcional vive en la bitácora `existencia_no_verificable` (valores_nuevos.motivo_no_verificable, misma transacción); se lee en lote con `ServicioResumenInventarioFisico::motivosNoVerificables()` (gana el registro más reciente). No es 0 ni diferencia: no entra a correcciones. Contar/marcar/reabrir comparten el candado y el 409 de `VerificarExistenciaInventarioFisico::bajoCandado()`. El cierre rechaza sólo pendientes reales.
