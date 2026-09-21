---
paths:
  - 'app/Models/Area.php,database/migrations/*areas*.php,app/Servicios/ServicioImportacionColaboradores.php'
---

# Migrations Servicios

## Area tiene nombre_normalizado único por empresa; crear áreas con retry ante colisión de nombre
`Area` usa `App\Models\Concerns\NombreNormalizado` (mismo mecanismo que TipoActivo/CategoriaActivo/Talla) con índice único `areas_empresa_id_nombre_normalizado_unique` (migración `2026_09_20_000001`) — a diferencia de esos catálogos GLOBALES, aquí el índice es por empresa: dos empresas distintas pueden tener cada una su propia área con el mismo nombre. `Area::existeNombre()` (heredado del trait) NO sirve para Área: es platform-global y no filtra por `empresa_id` — nunca usarlo aquí, resolver siempre con `Area::where('empresa_id', ...)->where('nombre_normalizado', NormalizadorNombre::catalogo($nombre))`. Cualquier código que cree Áreas automáticamente a partir de texto libre (como `ServicioImportacionColaboradores::resolverOCrearArea()`) debe envolver el `Area::create()` en un try/catch de `QueryException` que, ante una violación de ESTE índice (distinguir por `areas_empresa_id_nombre_normalizado_unique` o `areas.nombre_normalizado` en el mensaje — nunca por SQLSTATE 23000 a secas, que también cubre la colisión del `codigo`), re-consulte y reutilice la fila en vez de duplicar o fallar: es la defensa real ante dos importaciones concurrentes creando la misma área nueva.
