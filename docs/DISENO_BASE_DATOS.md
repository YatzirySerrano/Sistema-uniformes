# Diseño de la base de datos

Motor de desarrollo: SQLite. Producción recomendada: MySQL/MariaDB InnoDB
utf8mb4. Las migraciones usan el constructor de esquema de Laravel (agnóstico) y
enums se modelan como columnas `string` + cast a PHP enum.

> **Actualización (Almacenes / Áreas / Activos).** Ver
> `docs/ALMACENES_AREAS_ACTIVOS.md`. Cambios: tablas nuevas `areas`,
> `almacenes`, `almacen_sucursal` (N:M), `tipos_activo`; `colaboradores.area_id`
> (FK, con la columna de texto `area` conservada como espejo). La tabla
> `prendas` se **renombró** a `activos` (`activa`→`activo`,
> `codigo_interno`→`codigo`, + `tipo_activo_id`, `tipo_control`); `prenda_talla`
> → `activo_talla`; y `prenda_id` → `activo_id` en `saldos_inventario`,
> `movimientos_inventario`, `detalles_entrega` (+ `prenda_nombre_snapshot` →
> `activo_nombre_snapshot`) y `detalles_devolucion`. Renombrado in-place: cero
> pérdida de datos.

Se conservan `id`, `created_at`, `updated_at`, `deleted_at`. El resto de columnas
y tablas de dominio están en español.

## Tablas de dominio

| Tabla                                  | Contenido                               | Notas                                                                                                                                                               |
| -------------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `empresas`                             | Organizaciones                          | `codigo` único, colores de marca, `softDeletes`                                                                                                                     |
| `sucursales`                           | Ubicaciones de una empresa              | único `(empresa_id, codigo)`                                                                                                                                        |
| `empresa_usuario`                      | Pivote N:M usuario ↔ empresa autorizada | único `(empresa_id, usuario_id)`                                                                                                                                    |
| `sucursal_usuario`                     | Pivote N:M usuario ↔ sucursal           | sin filas ⇒ acceso a todas las sucursales de sus empresas                                                                                                           |
| `colaboradores`                        | Personal                                | único `(empresa_id, numero_empleado)`, `usuario_id` opcional, `softDeletes`                                                                                         |
| `prendas`                              | Catálogo de prendas                     | único `(empresa_id, codigo_interno)`, imagen en disco público, `softDeletes`                                                                                        |
| `tallas`                               | Catálogo de tallas                      | único `(empresa_id, valor)`, admite texto y números                                                                                                                 |
| `prenda_talla`                         | Pivote prenda ↔ talla                   |                                                                                                                                                                     |
| `saldos_inventario`                    | Existencia actual + mínimo              | único `(empresa_id, sucursal_id, prenda_id, talla_id)`                                                                                                              |
| `movimientos_inventario`               | Historia append-only                    | `tipo`, `direccion`, `cantidad`, `existencia_anterior`, `existencia_resultante`, `referencia_tipo/_id`, `motivo`, `realizado_por`, `ocurrido_en`                    |
| `entregas_uniformes`                   | Cabecera de entrega                     | `folio` único, `estado`, `encargado_id`, `confirmada_en`, `softDeletes`                                                                                             |
| `detalles_entrega`                     | Renglones de entrega                    | `prenda_nombre_snapshot`, `talla_valor_snapshot`                                                                                                                    |
| `acuses_recepcion`                     | Evidencia de recepción firmada          | `folio` único, `entrega_uniforme_id` único, `ruta_firma`, `ruta_pdf`, `snapshot_entrega` (JSON inmutable), `hash_documento`, `hash_firma`, IP y user agent de firma |
| `devoluciones` / `detalles_devolucion` | Devoluciones                            | `condicion` (`reutilizable`, `danado`, `baja`), `reingresa_inventario`                                                                                              |
| `correcciones_entrega`                 | Correcciones administrativas            | `motivo`, `valores_anteriores`, `valores_nuevos` (JSON)                                                                                                             |
| `bitacora_auditoria`                   | Bitácora                                | append-only (sin `updated_at`), snapshot de nombre de usuario                                                                                                       |
| `folios`                               | Contador de folios                      | único `(empresa_id, tipo, anio)`                                                                                                                                    |

Además: `users` gana `activo` (bool) y `ultimo_acceso_en`.

## Claves foráneas

- Pivotes: `cascadeOnDelete`.
- Referencias a catálogos e históricos: `restrictOnDelete` (no se permite borrado
  en cascada de datos con historia). `nullOnDelete` sólo donde el vínculo es
  opcional (`colaboradores.usuario_id`, `movimientos.realizado_por`,
  `bitacora.*`).

## Índices (según consultas reales)

`empresa_id`, `sucursal_id`, `colaborador_id`, `prenda_id`, `talla_id`, `estado`,
`firmado_en`, `created_at`, `(referencia_tipo, referencia_id)`, `(tipo_entidad,
entidad_id)` y los índices únicos ya citados.

## Uso de JSON

Sólo para datos inmutables que no se filtran ni ordenan: `snapshot_entrega`,
`valores_anteriores/_nuevos` de auditoría y correcciones.
