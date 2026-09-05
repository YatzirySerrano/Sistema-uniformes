---
paths:
    - 'resources/js/pages/**, resources/js/components/**'
---

# Components

## Select shadcn: SelectSimple para enums finitos, BuscadorAsync para catálogos

No quedan `<select>` nativos en `resources/js` (barrido completo). Regla de clasificación: enum finito y pequeño (estados, condiciones, tipo_control, orden, formato, módulo de auditoría…) → `components/sistema/SelectSimple.vue` (wrapper shadcn/reka `Select`; opciones `{valor, etiqueta, disabled?}`; usa el centinela interno `__vacio__` para soportar una opción "Todas/Todos" con `valor: ''`, así que nunca hace falta trabajar alrededor de eso desde el consumidor). Catálogo que puede crecer (Empresa, Sucursal, Colaborador, Almacén, Activo, Unidad, Conjunto, Tipo, Categoría, Variante) → `BuscadorAsync.vue`, en modo remoto (`buscar` pega a un endpoint `/recurso/buscar`) cuando la lista no está ya cargada, o en modo "filtro local" (`buscar` es un `async` que filtra un array ya recibido por props, p. ej. `empresasAutorizadas`) cuando el catálogo ya llegó completo al cliente — nunca dispara una petición nueva sólo para restilizar. `SelectSimple`/`Select` de reka-ui no renderiza wrapper DOM (SelectRoot es lógico, sin nodo propio): para dar ancho a `SelectSimple` envuélvelo en un `<div class="w-XX">`, nunca pases `class` directo al componente esperando fallthrough. Cambiar la Empresa de un formulario/filtro debe limpiar los combos dependientes (sucursal/almacén/etc.) vía `watch()`, igual que en `[[entregas]]`.
