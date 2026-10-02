---
paths:
  - 'app/Enums/SeccionDashboard.php,app/Servicios/ServicioDashboard.php,resources/js/pages/Panel.vue'
---

# Servicios Js Pages

## Dashboard: secciones de estructura de la organización
Además de las secciones operativas, `SeccionDashboard` incluye Empresas, Sucursales, Contratos y Servicios (cada una con la `viewAny` de su listado), y Colaboradores aporta `colaboradores_sin_servicio` (`servicio_actual_id` nulo). Las métricas que dependen de QUÉ sucursales ve el usuario (Sucursales, Servicios) declaran `usaAlcanceSucursales()`; `PanelController` sólo calcula `sucursalesAutorizadasGlobal` si alguna sección visible lo pide. `Panel.vue` las pinta en una fila aparte, "Estructura de la organización". No inventar conceptos que el dominio no tiene (p. ej. "contratos por vencer": `fecha_fin` existe, pero no hay un umbral definido).
