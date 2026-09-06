---
paths:
    - 'app/Soporte/Permisos.php,database/seeders/RolesPermisosSeeder.php,app/Policies/**'
---

# Policies

## Superadmin y Administrador: mismo acceso funcional (regla permanente)

Regla del proyecto (post-Fase 10, QA manual): Superadministrador y Administrador deben tener el MISMO acceso funcional a todos los módulos administrativos/operativos, Configuración incluida. `Permisos::porRol()` ya le da a Administrador `self::todos()` — nunca lo reduzcas a una lista explícita ni hardcodees un `if ($user->esSuperadministrador())`/`hasRole('administrador')` para gatear un módulo de negocio. La distinción Superadmin (proveedor/técnico) vs Administrador (dirección del cliente) es sólo conceptual y se limita a casos ya existentes y deliberados (p. ej. `UserPolicy`: un Administrador no puede gestionar la cuenta de un Superadministrador; `BitacoraController`/`UsuarioController`: alcance cross-empresa). Toda autorización de módulo nuevo se hace por permiso (`$user->can('recurso.accion')`), nunca por rol.

Trampa no obvia: si agregas un grupo de permisos nuevo a `Permisos::GRUPOS` (o cambias `porRol()`), el cambio NO llega solo a una base de datos ya migrada/seedeada — Spatie guarda permisos y rol_has_permissions en tablas, y `RolesPermisosSeeder` (estructural, idempotente: `Permission::findOrCreate` + `Role::syncPermissions`) sólo resincroniza si se vuelve a ejecutar. Si un módulo nuevo "no aparece" para un rol que según `Permisos::porRol()` debería tenerlo, sospecha primero de un `db:seed --class=RolesPermisosSeeder` pendiente antes de tocar código — verificado así en la ronda de QA post-Fase 10 (permisos `configuracion.ver`/`configuracion.administrar` no existían aún en la tabla `permissions` de la BD de desarrollo).
