# Multiempresa

## Jerarquía

```
PLATAFORMA
   └── EMPRESA
         └── SUCURSALES
               └── OPERACIÓN (colaboradores, inventario, entregas, ...)
```

## Relación usuario ↔ empresa

Las **empresas son entidades globales** de la plataforma; no se reparten entre
usuarios.

`empresa_usuario` (N:M) sólo acota a los **roles restringidos** (Supervisor,
Encargado, roles personalizados): esos usuarios nunca acceden a empresas que no
tengan asignadas. **Superadministrador y Administrador tienen alcance global** y
ven todas las empresas registradas sin depender de esta tabla.

`User::tieneAlcanceGlobal()` (`esSuperadministrador() || esAdministrador()`)
centraliza la regla; la consumen `puedeAccederEmpresa()`,
`ContextoEmpresa::empresasAutorizadas()` / `sucursalesDisponibles()` y
`EmpresaController@index`.

## Empresa activa

- Se guarda en sesión bajo `empresa_activa_id`.
- El middleware `ResolverEmpresaActiva` hidrata el singleton `ContextoEmpresa`
  para la petición, **revalidando** que el usuario tenga acceso. Si el usuario
  sólo tiene una empresa a su alcance, se selecciona automáticamente.
- `ContextoEmpresa::empresa()` puede ser `null`. Un contexto vacío **no**
  concede acceso a nada; los controladores que requieren empresa llaman a
  `empresaObligatoria()` que lanza una excepción de negocio (respuesta
  controlada, no 500).
- El **selector de empresa** (`components/sistema/SelectorEmpresa.vue`) hace
  `POST /empresa-activa`. Al cambiar de empresa se limpian la sucursal
  seleccionada y los filtros del tenant anterior.

## Superadministrador

Representa al proveedor / equipo técnico (no al dueño del cliente). Alcance
global mediante `Gate::before` en `AppServiceProvider`. Puede activar cualquier
empresa. Se crea por seeder (`superadmin@example.test`).

## Administrador

Dirección del cliente (dueños / directivos / responsables superiores). **Alcance
global sobre los módulos de negocio**: ve y administra **todas** las empresas de
la plataforma —incluidas las que se registren después— sin necesidad de
asociarlas en `empresa_usuario`. Puede **crear** empresas, editarlas, cambiar su
estado y seleccionar cualquiera como empresa activa (con los permisos
`empresas.*` correspondientes, que el seeder le otorga completos).

La diferencia con el Superadministrador **no** es "qué empresas ve", sino su
naturaleza: el Superadministrador es el equipo técnico/proveedor.

## Aislamiento (defensa en profundidad)

1. **Contexto**: la empresa activa se valida en cada petición.
2. **Consultas**: cada consulta de dominio se acota explícitamente
   (`where('empresa_id', ...)` / `scopeDeEmpresa`). No hay global scope
   automático: las vistas globales se programan deliberadamente.
3. **Policies**: `EmpresaPolicy`, `SucursalPolicy`, `ColaboradorPolicy`,
   `PrendaPolicy`, `EntregaUniformePolicy`, `AcuseRecepcionPolicy`,
   `DevolucionPolicy`, `UserPolicy` revalidan `puedeAccederEmpresa` /
   `puedeAccederSucursal`.
4. **Form Requests**: validación cruzada de FKs (`Rule::exists(...)->where('empresa_id', $empresaActiva)`).
5. **Tests**: `tests/Feature/MultiempresaTest.php`, `AcuseFirmaTest.php`.

## IDOR

Las rutas con binding de modelo verifican tanto la Policy como que el recurso
pertenezca a la empresa activa (`abort_unless($modelo->empresa_id === $activa->id, 404)`),
de modo que un ID existente de otra empresa responde 403/404, nunca datos.

## Sucursales

Cada sucursal pertenece a una empresa. `sucursal_usuario` acota el acceso de los
roles restringidos; si un Supervisor/Encargado no tiene sucursales asignadas
dentro de una empresa a su alcance, se asume acceso a todas las sucursales de esa
empresa. Administrador y Superadministrador siempre ven todas.
