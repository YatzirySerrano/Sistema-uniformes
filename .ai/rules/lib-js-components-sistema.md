---
paths:
    - 'app/{Enums/PerfilTecnicoUnidad.php,Soporte/EspecificacionUnidad.php,Http/Requests/Activos/{GuardarActivoRequest,GuardarCategoriaActivoRequest,ActualizarEspecificacionUnidadRequest}.php,Http/Requests/Concerns/ValidaEspecificacionUnidad.php},resources/js/lib/perfilTecnicoUnidad.ts,resources/js/components/sistema/CampoEspecificacionUnidad.vue'
---

# Lib Js Components Sistema

## Perfiles técnicos: campos centralizados y seguimiento individual obligatorio

Perfiles: Celular, Computadora, Tablet, Transporte, Electrodoméstico (enum en la relación 1:1 de la categoría). Campos por unidad en `unidad_activo_especificaciones`; nombres/etiquetas/reglas/normalización SÓLO en `App\Soporte\EspecificacionUnidad` (espejo TS en `lib/perfilTecnicoUnidad.ts`, control UI en `CampoEspecificacionUnidad.vue`). Transporte: clase+marca+modelo+año obligatorios y al menos uno de placas / NIV-serie (`identificadoresAlternativos()`); Electrodoméstico: sólo marca. Regla central `PerfilTecnicoUnidad::exigeSeguimientoIndividual()`: categoría con perfil ⇒ `tipo_control` individual (GuardarActivoRequest; en edición sólo si cambia categoría o control) y no se asigna perfil a una categoría con activos por cantidad. Nunca comparar nombres de tipo/categoría.
