---
paths:
  - 'app/Http/Requests/Entregas/GuardarEntregaRequest.php,resources/js/pages/Entregas/Crear.vue,app/Http/Controllers/EntregaController.php'
---

# Entregas Http Controllers

## Entrega nueva: finalidad REQUIRED por renglón, unidad concreta obligatoria, selector de custodia por talla+bolsa
Reemplaza "el backend la mantiene nullable" (views-acuses.md): `activos|unidades|conjuntos.*.finalidad` son `required` (la excepción por componente `conjuntos.*.finalidades.*` sigue opcional). Históricos NULL siguen válidos ("Sin clasificar"). Unidades: `unidades.*.activo_id` opcional; si viene, la unidad debe pertenecerle (`validarUnidadesContraActivo`). El wizard ya NO descarta en silencio una fila con activo sin unidad: la envía (backend la rechaza) y `problemasPaso2` la marca. `entregas/custodia/activos?control=cantidad` devuelve UNA opción por activo+talla+bolsa con `talla_fija` y `disponible` (id sintético); la UI muestra "Talla X · bolsa · Disponible: N" y fija la talla.
