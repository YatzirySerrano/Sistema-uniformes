---
paths:
  - 'app/Servicios/ServicioReportes.php,app/Http/Controllers/ReporteController.php,app/Http/Controllers/UnidadActivoController.php'
---

# Controllers Http Controllers

## "Unidades por estado" del reporte: mismo alcance que el listado de unidades
`ServicioReportes::metricasUnidades($empresaIds, $filtros)` cuenta SÓLO `unidades_activo` (nunca `saldos_inventario`) con el alcance de `UnidadActivoController::consultaUnidades()`: la empresa DUEÑA de la unidad (autorizadas ∩ `filtros.empresa_id`) más los filtros explícitos de almacén y activo. NO restringir por "almacenes que abastecen a la empresa": en una unidad asignada `almacen_id` es sólo su procedencia. Bug 2026-10: ignoraba `empresa_id` y con "DASTI" mostraba las unidades de INMAG (97 % disponibles). Toda métrica nueva del reporte debe aplicar `filtros.empresa_id` como las demás consultas de `ServicioReportes`. Test: `ReporteUnidadesPorEstadoTest.php` (cuadra listado vs gráfica por estado visible).
