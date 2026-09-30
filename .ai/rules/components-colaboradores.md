---
paths:
  - 'app/Http/Controllers/ColaboradorController.php,resources/js/components/colaboradores/CustodiaPendienteBaja.vue'
---

# Components Colaboradores

## Eliminar (desactivar) colaborador exige custodia en cero
`ColaboradorController::toggle` al desactivar bloquea al colaborador (lockForUpdate) y rechaza con `ExcepcionDeNegocioSimple` si `ServicioCustodiaColaborador::tienePendientes()` (cualquier finalidad, sin clasificar, unidades asignadas incl. perdidas/robadas, componentes de conjuntos) — misma regla estricta que cambio de empresa, sin "mantener". Restaurar no se bloquea. Los diálogos de Index/Detalle usan `CustodiaPendienteBaja` (GET colaboradores/{id}/custodia; `consultarCustodia` ahora también admite `desactivar`) y deshabilitan "Eliminar" salvo estado 'libre' (fail-closed). La cascada Empresa/Sucursal NO pasa por esta regla (fuera de alcance).
