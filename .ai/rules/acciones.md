---
paths:
    - 'resources/js/pages/Inventario/Movimientos.vue,resources/js/pages/Entregas/Crear.vue,app/Policies/EntregaUniformePolicy.php,app/Acciones/CorregirEntrega.php'
---

# Acciones

## Movimientos con SelectorVista; Entrega firmada inmutable; evidencia sólo con elemento

`Inventario/Movimientos.vue` YA lleva `SelectorVista` + `useVistaPreferida('movimientos','tabla')` (a petición del usuario; la exclusión previa de `.ai/rules/pages.md` queda revertida). El payload de `MovimientoInventarioController::index` incluye `referencia` (folio del traspaso) y `unidad_codigo` para las cards. — `Entregas/Crear.vue`: `<CapturaEvidencia>` sólo se muestra con `fila.activo_id !== ''` (artículos) / `fila.unidad_activo_id !== ''` (unidades); los conjuntos NO tienen evidencia. `alElegirActivo/alElegirActivoUnidad/alElegirUnidad` limpian `evidencia`+`evidencia_origen` (y `cantidad=1` en artículos). `CapturaEvidencia` tiene `watch(archivo)` que resetea `pendiente` + el `<input type=file>` cuando el padre pone el modelo a null. `problemasPaso2` bloquea el paso 2 con lista visible (patrón del wizard de traspasos) cuando `cantidad > disponible` en artículos o conjuntos. `CrearEntregaUniforme::registrarComponenteCantidad` captura `ExistenciasInsuficientesException` y re-lanza "El stock disponible cambió. Solicitaste N unidades de X talla Y, pero actualmente sólo hay M disponibles." reusando los datos de la excepción. — Entrega FIRMADA (o Corregida/Anulada) es inmutable: `EntregaUniformePolicy::corregir` sólo permite `PendienteFirma`; `CorregirEntrega::ejecutar` lanza `ExcepcionDeNegocioSimple('Una entrega firmada no puede modificarse.')`; el botón "Corregir entrega" se quitó de `Entregas/Detalle.vue`.
