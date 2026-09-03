---
paths:
    - 'resources/js/pages/**'
    - 'resources/js/components/**'
---

# UX — el usuario no debe adivinar

Regla permanente. Cada módulo / formulario / campo / acción ambiguo se explica
con esta jerarquía, **sin** saturar de tooltips:

1. **Label** comprensible por sí mismo.
2. **Descripción de módulo** breve bajo el título (`EncabezadoPagina descripcion`),
   redactada para un usuario no técnico. Existe en todos los `index` y formularios
   principales.
3. **Texto de ayuda** bajo el campo cuando aporte (`<p class="text-muted-foreground text-xs">`).
4. **`AyudaTooltip`** sólo para aclaraciones adicionales — nunca para ocultar
   información esencial.

Iconos: todo icon-button lleva `aria-label` (y `title` si el icono no es
evidente).

## Validación visible

- Errores **por campo**, inline, con estado de error en el control
  (`InputError` + `border-destructive` / `invalido`).
- Filas repetibles: el error va **junto a su fila**, leyendo la clave
  `items.N.<campo>` de `form.errors` (helper `errFila(i, campo)` en
  `Inventario/Entrada.vue`).
- Resumen discreto arriba tras enviar ("No pudimos… Revisa los campos
  marcados"). Nunca `alert()` nativo; los errores globales van por toast.
- El botón se puede deshabilitar por faltantes **estructurales** (combobox /
  cantidad / motivo vacíos), pero el error concreto se muestra al
  interactuar / enviar. La validación backend es siempre obligatoria.

## Combobox con buscador (regla)

Si un catálogo puede crecer, no usar un `<select>` plano gigante: usar
`components/sistema/BuscadorAsync.vue`.

- `buscar: (q) => Promise<Opcion[]>` — remoto debounced contra
  `/<recurso>/buscar` (endpoints JSON: `activos/buscar`,
  `almacenes/buscar`, `almacenes/colaboradores-buscar`) si el catálogo puede
  llegar a miles; o filtro local sobre una lista ya cargada si es pequeño.
- `modelValue` es el **objeto** seleccionado (no un id): el consumidor mantiene
  el id en su `form`/`filtros` y el objeto en un `ref` paralelo.
- Props útiles: `placeholderBusqueda`, `sinResultados`, `disabled`, `invalido`.

Aplica a: almacenes, activos, categorías, tipos, colaboradores, responsables,
sucursales, áreas, variantes numerosas, unidades serializadas, uniformes.

## Tipo vs Categoría (texto de ayuda estándar)

- Tipo de activo → "Clasificación general del activo. Ejemplo: Prenda, Equipo de
  cómputo o Dispositivo móvil."
- Categoría → "Clasificación específica dentro del tipo. Ejemplo: Camisola,
  Laptop o Teléfono celular."
