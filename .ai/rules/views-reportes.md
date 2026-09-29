---
paths:
  - 'app/Http/Controllers/InventarioFisicoController.php,app/Servicios/ServicioResumenInventarioFisico.php,resources/views/reportes/inventario-fisico-acta.blade.php'
---

# Views Reportes

## PDF de ronda de inventario físico = acta completa e histórica
`GET inventarios-fisicos/{id}/exportar?formato=pdf` ignora `seccion`/`tipo` y renderiza el ACTA (`reportes.inventario-fisico-acta`, datos de `ServicioResumenInventarioFisico::datosActa()`): cabecera, contadores, TODAS las unidades y TODOS los renglones por cantidad (bloques independientes: uno vacío nunca oculta al otro), firma y correcciones. Bug previo: el PDF era la tabla genérica de UNA sección de unidades (`faltantes` por defecto desde el detalle) e ignoraba existencias → rondas sólo por cantidad salían vacías. Sólo datos congelados/registrados por la ronda: nunca el estado/almacén/colaborador ACTUAL de la unidad ni `saldos_inventario`. Excel sigue siendo plano por `tipo`. `resumenCorrecciones()` es la fuente única del estado de correcciones (detalle y PDF).
