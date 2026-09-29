---
paths:
  - 'app/Policies/**,app/Http/Controllers/{Empresa,Sucursal,Colaborador,Almacen,Activo,UnidadActivo,Conjunto,Inventario}Controller.php'
---

# Policies Http Controllers

## Selector contextual ≠ acceso al módulo; condición ≠ ajuste
`empresas/buscar` y `sucursales/buscar` NO exigen `empresas.ver`/`sucursales.ver`: sólo alcance (`AccesoEmpresa`) y devuelven datos mínimos (id/código/nombre). Colaboradores/almacenes/activos/unidades/conjuntos `buscar` usan la ability `seleccionarEnOperacion` (viewAny del módulo O el permiso operativo que lo necesita). index/show de módulos siguen con su Policy. Condición física tiene permiso propio: `activos.condicion` (existencias por cantidad, `inventario/condicion*`) y `unidades-activo.condicion` (`UnidadActivoPolicy::gestionarCondicion`: dañar/incidencia/recuperar/restaurar); `inventario.ajustar` sólo corrige existencias, `unidades-activo.administrar` sólo alta/datos/baja. Migración `..._000001_separar_permisos_condicion_de_ajuste` los concedió a los roles que tenían el permiso anterior. `empresas.administrar` sólo se usa en `EmpresaPolicy::delete` (sin ruta): etiqueta y uso real no coinciden, se dejó intacto.
