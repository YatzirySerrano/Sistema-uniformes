# Roles y permisos

Se usa `spatie/laravel-permission` (guard `web`).

## Roles base (seeder `RolesPermisosSeeder`)

| Rol interno          | Etiqueta           | Alcance                                                                                                                                                                                             |
| -------------------- | ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `superadministrador` | Superadministrador | Global (bypass `Gate::before`). Equipo técnico / proveedor.                                                                                                                                         |
| `administrador`      | Administrador      | Alcance global sobre los módulos de negocio: **todos los permisos** y **todas las empresas** de la plataforma (incluye `empresas.crear` y `empresas.administrar`). No depende de `empresa_usuario`. |
| `supervisor`         | Supervisor         | Según permisos y **empresas/sucursales asignadas** (`empresa_usuario` / `sucursal_usuario`). Incluye `empresas.ver` para consultar y cambiar entre sus empresas.                                    |
| `encargado`          | Encargado          | Operación de entregas/firmas/devoluciones.                                                                                                                                                          |
| `colaborador`        | Colaborador        | Portal propio + firmar su recepción.                                                                                                                                                                |

## Roles personalizados

El Administrador (permiso `roles.crear` / `roles.editar`) puede crear roles
(Recursos Humanos, Almacén, Gerente Regional, Auditor, …) y asignarles cualquier
subconjunto de permisos desde la matriz de `/roles`. Los roles base no se
eliminan y el rol `superadministrador` no se modifica desde la interfaz.

## Catálogo de permisos

Fuente de verdad: `App\Soporte\Permisos::GRUPOS`. Notación `recurso.accion`.

| Grupo            | Permisos                                                                    |
| ---------------- | --------------------------------------------------------------------------- |
| Empresas         | `empresas.ver`, `empresas.crear`, `empresas.editar`, `empresas.administrar` |
| Sucursales       | `sucursales.ver/crear/editar/desactivar`                                    |
| Usuarios         | `usuarios.ver/crear/editar/desactivar`                                      |
| Roles            | `roles.ver/crear/editar/asignar`                                            |
| Colaboradores    | `colaboradores.ver/crear/editar/desactivar/importar`                        |
| Prendas y tallas | `prendas.ver/crear/editar`, `tallas.administrar`                            |
| Inventario       | `inventario.ver/entrada/ajustar/minimos/transferir`                         |
| Entregas         | `entregas.ver/crear/corregir`                                               |
| Acuses           | `acuses.ver`, `acuses.firmar`, `acuses.ver-pdf`, `acuses.ver-firma`         |
| Devoluciones     | `devoluciones.ver/crear`                                                    |
| Reportes         | `reportes.ver/exportar`                                                     |
| Auditoría        | `auditoria.ver`                                                             |
| Personalización  | `configuracion-empresa.ver/editar`                                          |

`Permisos::porRol()` define los permisos por defecto de cada rol base. La
autorización se resuelve por permiso (Spatie registra cada permiso como
_gate ability_), nunca por nombre de rol, salvo el bypass del Superadministrador
y el helper `User::tieneAlcanceGlobal()` (Superadministrador o Administrador), que
concede acceso global a los módulos de negocio (empresas, sucursales, …).

## Dónde se aplica

- **Controllers**: `abort_unless($request->user()->can('inventario.entrada'), 403)` o `$this->authorize('create', Modelo::class)`.
- **Policies**: combinan permiso + pertenencia a la empresa/sucursal.
- **Frontend**: `usePermisos().puede('...')` para ocultar acciones y menús (no sustituye la comprobación de backend).
