---
paths:
    - 'app/Acciones/CrearRondaInventarioFisico.php,resources/js/pages/InventarioFisico/Crear.vue'
---

# Inventario Fisico

## Universo de inventario físico: ver `pages-inventario-fisico.md` (ronda integral por empresa)

La ronda ya NO es de un almacén. Universo vigente: existencias por cantidad de TODOS los almacenes de la empresa (renglón por almacén) + unidades En almacén o Asignadas en condición Funcionando/EnReparación/Inservible; nunca Perdido/Robado/Baja. `Crear.vue` sólo pide empresa, nombre y observaciones. No ampliar a custodios/servicios sin discutirlo.
