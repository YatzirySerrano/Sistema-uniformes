---
paths:
    - 'app/{Servicios/ServicioCuentaColaborador.php,Http/Controllers/CuentaColaboradorController.php,Models/User.php,Models/Colaborador.php},database/migrations/*permisos*.php,app/Soporte/Permisos.php'
---

# Migrations Soporte

## User ↔ Colaborador 1:1 y permisos nuevos sin seeder

"Mi custodia" = `User::colaborador()` (HasOne, índice único en `colaboradores.usuario_id`); nunca inferir por nombre/correo/rol. Se administra en la ficha del colaborador ("Cuenta de acceso asociada"): ver = `colaboradores.usuario-ver`, cambiar = `colaboradores.usuario-administrar` (+ver); sin permiso de ver, `ColaboradorController::show` manda `cuenta: null` (ni id ni correo). Cuentas elegibles: activas, sin otra ficha, con acceso a la empresa; superadmins sólo por superadmin. Permisos NUEVOS se registran con una migración idempotente que sólo crea filas en `permissions` (no `RolesPermisosSeeder`, cuyo `syncPermissions` pisa roles reales) y NO se asignan por nombre de rol: el Administrador los concede en Roles y permisos.
