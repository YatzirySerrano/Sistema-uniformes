---
paths:
  - 'app/Policies/DevolucionPolicy.php,app/Acciones/RegistrarDevolucion.php,app/Http/Requests/Devoluciones/GuardarDevolucionRequest.php,app/Http/Controllers/DevolucionController.php,resources/js/pages/Devoluciones/Crear.vue,resources/js/components/colaboradores/CustodiaPanel.vue'
---

# Js Components Colaboradores

## Devoluciones: custodia propia, reparto por condición y finalidad de sólo lectura
La custodia propia se evalúa POR RENGLÓN/UNIDAD, nunca por la entrega completa, en `DevolucionPolicy::recibirRenglon(User, DetalleEntrega)`: si es custodia propia (vínculo `User::colaborador()`) y "Uso personal" o "Sin clasificar" (null, nunca se asume redistribución), se exige `devoluciones.procesar-custodia-propia`. Lo de redistribución propia, la custodia ajena y las cuentas sin ficha usan permisos normales, y el autor de la entrega es irrelevante. El lote pasa siempre por `DevolucionPolicy::motivoRechazoRenglones()`, que usan `create` (`puede_devolver`/`motivo_bloqueo` por fila; `bloqueoCustodiaPropia` sólo si TODAS las filas están bloqueadas), `reservar` y, como autoridad final, `RegistrarDevolucion` antes de crear nada (`ExcepcionDeNegocio`, nunca un 403 crudo). Para dividir un renglón por condición se envía `activos.N.condiciones=[{condicion,cantidad}]` en lugar de `condicion`: es UNA sola devolución con una fila `DetalleDevolucion` por condición del mismo `detalle_entrega_id`, con suma exacta validada en el Request y en la Acción. Cada fila conserva su efecto (sólo Reutilizable reingresa) y el tope contra lo pendiente se aplica una vez sobre el total. La finalidad de custodia es de sólo lectura: se retiró `PUT entregas/renglones/{detalle}/finalidad`; una reclasificación futura sería otro flujo explícito y auditado.
