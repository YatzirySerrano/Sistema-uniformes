---
paths:
    - 'resources/js/pages/**'
    - 'resources/js/components/**'
    - 'resources/js/components/AppLogoIcon.vue,public/favicon.svg,public/favicon.ico,public/apple-touch-icon.png'
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

## Selector de empresa (regla — Bloque A)

No hay "empresa activa" global. Cada **listado** con datos por empresa lleva un
filtro `Empresa` (visible sólo si `empresasAutorizadas.length > 1`) que se envía
como `?empresa_id=`. Cada **formulario de alta** por empresa lleva un campo
`Empresa` obligatorio (oculto al editar; el registro fija su empresa). Los
campos dependientes (sucursal, área, almacén, tipo, categoría, variante,
activo, colaborador) se recargan al cambiar la empresa y se cargan acotados por
ella (no traer todo y ocultar con Vue). La lista viene de `usePermisos()`
(`empresasAutorizadas`) o del prop `empresasAutorizadas` de la página. Cuando la
lista puede crecer, el selector de empresa es `BuscadorAsync` (filtro local),
no un `<select>` plano.

## Catálogos compartidos habilitados por empresa (regla)

`Activos/Catalogos.vue` y `Activos/Tallas.vue` administran catálogos de
plataforma. Reglas de redacción y UX:

- El selector de empresa de la parte superior se rotula **"Administrar
  disponibilidad para …"** (no sólo "Empresa"): deja claro que fija el contexto
  de los toggles por empresa.
- El toggle por fila se rotula **"Disponible en «{empresa seleccionada}»"** (no
  "Habilitada aquí"). Tooltip: deshabilitar aquí no borra el elemento ni afecta
  a otras empresas ni a los activos que ya lo usan.
- Todo mensaje de éxito de habilitar/deshabilitar **nombra la empresa**:
  "«L» deshabilitada para «Empresa X»".
- El contador "N empresas" es un botón que abre un diálogo con la **lista
  completa** de empresas autorizadas (las habilitadas marcadas) + buscador, para
  ver y gestionar. Nunca dejar sólo el número.
- Presentación en **cards / filas apiladas**, nunca tabla con scroll horizontal.
- "Estado global" (activo/activa) y "Disponible aquí" (por empresa) son cosas
  distintas y deben verse como tales.

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
- **`dependencia`**: valor del que dependen los resultados (p. ej. `empresa_id`).
  Al cambiar, el componente limpia de inmediato resultados y término, descarta la
  respuesta en vuelo (token incremental + `AbortController`) y vuelve a consultar
  en la siguiente apertura. Úsalo siempre que la lista dependa de otro campo
  (empresa → almacén / activo / colaborador; activo → variante). El consumidor
  además debe poner a `null` el id seleccionado en su `form`/`filtros` al cambiar
  esa dependencia. `buscar` recibe un 2.º argumento `AbortSignal` opcional que
  conviene pasar al `fetch`.

Aplica a: almacenes, activos, categorías, tipos, colaboradores, responsables,
sucursales, áreas, variantes numerosas, unidades serializadas, uniformes.

## Tipo vs Categoría (texto de ayuda estándar)

- Tipo de activo → "Clasificación general del activo. Ejemplo: Prenda, Equipo de
  cómputo o Dispositivo móvil."
- Categoría → "Clasificación específica dentro del tipo. Ejemplo: Camisola,
  Laptop o Teléfono celular."

## Icono/favicon del sistema son placeholder temporal (sustituir cuando exista logo final)

Se retiró el logo de Laravel (branding visible del starter kit) y se reemplazó por un icono neutro "caja + check" (mismo SVG duplicado a mano en `AppLogoIcon.vue` y, rasterizado con GD, en `favicon.svg`/`favicon.ico`/`apple-touch-icon.png`, color `#171717` = `color_principal` por defecto de `ConfiguracionSistema`). Es explícitamente TEMPORAL — cuando exista un logo/isotipo final de Sistema-uniformes, reemplazar estos 4 archivos (mantener el mismo patrón: SVG con `fill="currentColor"` para el componente Vue, PNG-in-ICO de 32x32 para favicon.ico, PNG de 180x180 para apple-touch-icon). También pendiente: `APP_NAME=Laravel` sigue en el `.env` real (no versionado, no se tocó) — el código ya usa `Sistema de Uniformes` como fallback (`config/app.php`, `.env.example`, `resources/js/app.ts`), pero para que el `<title>` de cada página y el texto junto al logo en el sidebar dejen de decir "Laravel" hay que actualizar `APP_NAME` en el `.env` real.
