# Almacenes, Áreas / Departamentos y Activos

> **BLOQUE A — actualización.** `Almacén ↔ Empresa` es ahora **N:M**
> (`almacen_empresa`). El almacén NO pertenece a una empresa y NO se relaciona
> con sucursales (`almacenes.empresa_id` y `almacen_sucursal` eliminados). El
> `codigo` de almacén es único a nivel plataforma. Alta/edición: campo
> `empresa_ids[]` (≥ 1). El inventario se mantiene separado por empresa dentro
> del almacén. Sin "empresa activa": ver `docs/MULTIEMPRESA.md`.

Este bloque construye la nueva base del sistema tras dejar de ser exclusivo de
uniformes. El **inventario por almacén** ya está implementado (ver
`docs/INVENTARIO.md`). Siguen pendientes: cascadas de desactivación,
conjuntos/uniformes y el flujo de activos serializados (`UnidadActivo`).

## 1. Nueva jerarquía funcional

```
SISTEMA
└── EMPRESA
    ├── SUCURSALES
    ├── ALMACENES ──(N:M)── SUCURSALES que abastece
    ├── ÁREAS / DEPARTAMENTOS
    ├── COLABORADORES
    └── ACTIVOS
```

El inventario vive en el **almacén** (`ALMACÉN + ACTIVO + VARIANTE = STOCK`). La
sucursal es sólo el destino/contexto del colaborador.

## 2. Almacenes

| Aspecto     | Detalle                                                                   |
| ----------- | ------------------------------------------------------------------------- |
| Tabla       | `almacenes` (soft deletes)                                                |
| Pertenece a | `empresa_id`                                                              |
| Código      | `ALM-0001` autogenerado, único por empresa; editable y normalizado        |
| Responsable | `responsable_colaborador_id` nullable → colaborador activo de la empresa  |
| Abastece    | N:M con sucursales de la misma empresa vía `almacen_sucursal`             |
| Estado      | `activo` (bool). Desactivar **no** toca catálogo de activos ni históricos |

Endpoints: `almacenes.index` (cards), `almacenes.show` (detalle full width),
`almacenes.store`, `almacenes.update`, `almacenes.toggle`,
`almacenes.sucursales` (PUT, sincroniza sucursales abastecidas),
`almacenes.colaboradores-buscar` (JSON, autocompletado del responsable:
colaboradores activos de la empresa activa por nombre / número de empleado).

Un almacén sin sucursales muestra "Este almacén todavía no abastece sucursales."
y permite asignarlas.

## 3. Áreas / Departamentos

| Aspecto     | Detalle                                                  |
| ----------- | -------------------------------------------------------- |
| Tabla       | `areas` (soft deletes)                                   |
| Pertenece a | `empresa_id`                                             |
| Nombre      | único por empresa (normalizado, sin distinguir espacios) |
| Código      | `ARE-0001` autogenerado                                  |

### Relación con Colaborador

- Se añadió `colaboradores.area_id` (FK nullable a `areas`) — **fuente de
  verdad** futura.
- La columna de texto `colaboradores.area` **se conserva** como espejo
  temporal (importador Excel, exportador, plantilla y snapshot de acuse) hasta
  la reingeniería de Colaboradores. `ColaboradorController` mantiene `area`
  sincronizada con el nombre del área seleccionada.
- Migración de datos `2026_09_02_000003_backfill_colaborador_area`: por cada
  valor de texto distinto (por empresa, sin distinguir mayúsculas, colapsando
  espacios) crea/reutiliza un Área y enlaza `area_id`. **No** fusiona valores
  ambiguos (p. ej. "RH" y "Recursos Humanos" quedan como áreas distintas).
- En el modelo la relación se llama `Colaborador::departamento()` (no `area()`,
  para no colisionar con la columna de texto).

Listado en cards con contador de colaboradores activos / totales y acceso a
`/colaboradores?area_id={id}`.

## 4. Activos (evolución de "Prendas")

### 4.1 Estrategia de migración (elegida: renombrado in-place)

Se evaluaron: (A) renombrar/evolucionar la estructura existente, (B) crear una
estructura nueva y migrar datos, (C) capa de compatibilidad temporal. Se eligió
**(A)** por cero pérdida de datos (es un rename, no una copia de filas), mínima
duplicación y para no reescribir dos veces:

| Antes                                                   | Ahora                                          |
| ------------------------------------------------------- | ---------------------------------------------- |
| tabla `prendas`                                         | tabla `activos`                                |
| `prendas.activa` / `prendas.codigo_interno`             | `activos.activo` / `activos.codigo`            |
| pivote `prenda_talla` (`prenda_id`)                     | pivote `activo_talla` (`activo_id`)            |
| `saldos_inventario.prenda_id`                           | `saldos_inventario.activo_id`                  |
| `movimientos_inventario.prenda_id`                      | `movimientos_inventario.activo_id`             |
| `detalles_entrega.prenda_id` / `prenda_nombre_snapshot` | `activo_id` / `activo_nombre_snapshot`         |
| `detalles_devolucion.prenda_id`                         | `detalles_devolucion.activo_id`                |
| modelo `Prenda`, `PrendaController`, `PrendaPolicy`     | `Activo`, `ActivoController`, `ActivoPolicy`   |
| permisos `prendas.*`                                    | permisos `activos.*` (+ `activos.administrar`) |
| rutas `/prendas`                                        | rutas `/activos` (`/prendas` redirige)         |
| páginas `resources/js/pages/Prendas`                    | `resources/js/pages/Activos`                   |

El snapshot inmutable de los acuses ya emitidos guardó la clave `prenda`; los
nuevos guardan `activo` y la plantilla `acuses/comprobante.blade.php` lee ambas
(los históricos no se alteran).

### 4.2 Clasificación

- **PRENDA vs UNIFORME**: una prenda es un activo individual; un uniforme es un
  **conjunto** de prendas (módulo pendiente). El tipo `tipos_activo` **no**
  incluye "Uniforme".
- `tipo_activo_id` → `tipos_activo` por empresa con **CRUD completo**
  (`TipoActivoController`, pantalla `Activos/Catalogos.vue`, permiso
  `tipos-activo.administrar`). Tipos base sembrados: Prenda, Equipo de cómputo,
  Dispositivo móvil, Electrónico, Accesorio, Herramienta / Equipo, Otro. Alta
  rápida "Otro" desde el formulario del activo (`tipos-activo/rapido`, JSON).
- `categoria_id` → `categorias_activo` (catálogo real por empresa, `tipo_activo_id`
  opcional). Fuente de verdad; `activos.categoria` (texto) es espejo temporal.
  Alta rápida "Otra" (`categorias-activo/rapido`).
- `tipo_control` (`App\Enums\TipoControlActivo`):
    - `cantidad`: existencias agregadas (uniformes, accesorios).
    - `serializado`: cada unidad se identifica por serie / IMEI. El formulario
      oculta variantes/tallas; el flujo de `UnidadActivo` **no** está
      implementado.
- Variantes / tallas (`Activo::tallas()`, pivote `activo_talla`) son
  **opcionales**.

### 4.3 Relación Almacén ↔ Activo

El stock vive en `saldos_inventario.almacen_id` (ver `docs/INVENTARIO.md`).
Desactivar un almacén nunca desactiva un activo del catálogo.

## 5. Requerimiento global de exportación

Cada listado tendrá `[Exportar Excel]` y `[Exportar PDF]` respetando filtros. Los
`index` de Áreas, Almacenes y Activos se diseñaron con el query acotado y
`through()` para que pantalla, PDF y Excel compartan la misma consulta (patrón
de `ServicioReportes`). La exportación por módulo no se implementa en este
bloque.

## 6. Pendientes ligados a este bloque

- Cascada de desactivación (Empresa/Sucursal/Almacén → dependientes) con
  reactivación selectiva; los históricos nunca se tocan.
- Uniformes / Conjuntos (conjunto ↔ muchos activos + cantidad requerida).
- Flujo de activos serializados (`UnidadActivo`).
- Reingeniería de UI de Entregas / Devoluciones sobre almacén (hoy vía puente
  `ResolverAlmacenOperativo`).
- Redesign de Variantes / Tallas y activo "sin variante" (Unitalla).
