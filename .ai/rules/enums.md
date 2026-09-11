---
paths:
    - 'app/{Enums/PerfilTecnicoUnidad.php,Soporte/ResolverPerfilTecnicoUnidad.php,Models/UnidadActivoEspecificacion.php}'
---

# Enums

## Perfil técnico de unidad = relación 1:1 de la categoría, nunca codigo ni nombre

El perfil técnico (Celular/Computadora/Tablet) que decide qué datos por unidad se piden/muestran vive en la relación 1:1 `CategoriaActivo::perfilTecnico` (tabla `categoria_activo_perfil_tecnico`, columna `perfil` casteada a `PerfilTecnicoUnidad`), resuelto SÓLO por `App\Soporte\ResolverPerfilTecnicoUnidad::paraActivo()`. NUNCA por `categorias_activo.codigo` (ni `tipos_activo.codigo` — `TAC-####` es identificador operativo y no se toca), NUNCA por `nombre`. El admin lo asigna desde `Activos/Catalogos.vue` (SelectSimple "Perfil técnico"); "Sin perfil técnico" borra la fila lateral. `CategoriaActivoController::update()` no toca `codigo`. Datos técnicos en tabla 1:1 `unidad_activo_especificaciones` (imei string UNIQUE tolerando NULL; número NO único). Enmascarar IMEI (`••••NNNN`) en listados/selectores/inventario físico; completo sólo en detalle autenticado + export admin.
