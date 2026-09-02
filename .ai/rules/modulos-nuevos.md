---
paths:
    - 'app/Http/Controllers/{Almacen,Area,Activo}Controller.php'
    - 'app/Http/Requests/{Almacenes,Areas,Activos}/**'
---

# Módulos Almacenes / Áreas / Activos

Patrón calcado de `SucursalController` / `EmpresaController` (módulos aprobados):

- `use ConEmpresaActiva;` + `ServicioAuditoria` inyectado en el constructor.
- La **empresa nunca llega del frontend**: `empresaActiva()` del contexto es la
  autoridad. En `store` se fuerza `empresa_id`; en `show`/`update`/`toggle` se
  hace `abort_unless($modelo->empresa_id === $this->empresaActiva()->id, 404)`.
- Autorización: `$this->authorize(...)` con la Policy correspondiente
  (`ActivoPolicy`, `AlmacenPolicy`, `AreaPolicy`), que combina el permiso
  granular (`activos.*`, `almacenes.*`, `areas.*`) con
  `User::puedeAccederEmpresa()`. Sin hardcodear nombres de rol (salvo el bypass
  de Superadministrador en `AppServiceProvider`).
- Form Requests con `NormalizaEntrada`, `authorize()` que delega en la Policy,
  reglas cruzadas contra la empresa activa (responsable, sucursales abastecidas,
  tipo de activo, tallas). No debe haber 500 con arrays/objetos donde se espera
  string/id.
- Códigos autogenerados y únicos por empresa: `ALM-0001`, `ARE-0001`,
  `ACT-0001` (helper `generarCodigo()` con `withTrashed()`).
- Auditar `crear` / `editar` / `activar` / `desactivar` y las relaciones
  críticas (`almacenes` ↔ `sucursales`) vía `ServicioAuditoria::registrar()`.
- El listado se sirve como cards (ver `.ai/rules/pages.md`) y su query debe ser
  reutilizable para la futura exportación PDF/Excel.

## Serializados y variantes

`Activo::tipo_control` distingue `cantidad` de `serializado`. En este bloque
sólo se contempla en el catálogo: NO implementar el flujo de unidades
serializadas (número de serie / IMEI) ni el inventario por almacén todavía.
