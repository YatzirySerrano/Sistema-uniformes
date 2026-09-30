---
paths:
  - 'app/Acciones/CrearRondaInventarioFisico.php,resources/js/pages/InventarioFisico/Crear.vue'
---

# Inventario Fisico

## Universo de inventario físico = unidades FÍSICAMENTE en el almacén (reemplaza "sólo disponibles")
El alcance de una ronda es UN almacén. `CrearRondaInventarioFisico::universo()` incluye `estado=en_almacen`, `colaborador_id IS NULL` y condición en `condicionesFisicamentePresentes()` = Funcionando, EnReparacion, Inservible. Excluye Asignada (su almacen_id sólo es procedencia; está con un custodio), Perdido, Robado y Baja. Corrige lo dicho en models-models.md ("universo sólo disponible") y models-soporte.md ("perdidas/robadas/reparación/asignadas SÍ entran"). No hay inventario de custodios/servicios: no ampliar sin discutirlo.
