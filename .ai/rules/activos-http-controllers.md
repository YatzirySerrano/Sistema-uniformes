---
paths:
  - 'app/Http/Requests/Activos/GuardarActivoRequest.php,resources/js/pages/Activos/Formulario.vue,app/Http/Controllers/CategoriaActivoController.php'
---

# Activos Http Controllers

## Tipo y categoría de Activo OBLIGATORIOS; la categoría fija su tipo (ronda 2026-09-30)
Reemplaza "tipo y categoría opcionales" de models.md/modulos-nuevos.md. `GuardarActivoRequest`: `tipo_activo_id` y `categoria_id` son `required` en ALTA y EDICIÓN (un histórico sin clasificar se ve/edita, pero guardar exige completarlos; no hubo migración ni relleno por nombre). Coherencia estructural: si `categorias_activo.tipo_activo_id` no es NULL, el tipo enviado debe ser ése (petición manipulada Prenda+Carro → error en `categoria_id`); categoría sin tipo admite cualquiera. Front: al elegir categoría con tipo ligado se fija el tipo (aviso "Se seleccionó … automáticamente"); cambiar el tipo a uno incompatible quita la categoría. Nunca comparar nombres. Tests: helper `clasificacionActivo()` en tests/Pest.php (categoría SIN tipo por defecto, compatible con cualquier tipo que sobrescribas).
