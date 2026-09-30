---
paths:
  - 'app/Http/Controllers/InventarioController.php,resources/js/pages/Inventario/Index.vue,resources/js/components/sistema/ResumenSeguimientoIndividual.vue'
---

# Pages Inventario Js Components Sistema

## Existencias globales: seguimiento individual se resume desde unidades_activo, nunca saldos
Las unidades individuales nunca tienen fila en `saldos_inventario`; por eso el filtro «Seguimiento individual» salía vacío. `InventarioController::consultaIndividuales()` agrupa `unidades_activo` por empresa+almacén(procedencia)+activo con los mismos filtros/alcance; `filasIndividuales()` desglosa la página en UNA consulta por estado+condición resuelta con `EstadoVisibleUnidad::resolver()` (misma regla que Unidades). Prop `individuales` (pageName `pagina_individual`, `null` si control=cantidad / talla / bajo_minimo). Export con control=individual usa `exportarIndividuales()`. "Ver unidades" enlaza a `/activos/unidades?empresa_id&activo_id&almacen_id`.
