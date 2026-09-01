# Sistema de Control y Gestión de Uniformes

Sistema web empresarial para controlar la entrega de uniformes a colaboradores de
múltiples empresas y sucursales: inventario por sucursal, entregas con firma de
recepción, comprobantes PDF, devoluciones, correcciones, reportes y auditoría.
Interfaz y documentación en español.

## Stack

Laravel 13 · PHP 8.4 · Fortify · Spatie Permission · Inertia 3 · Vue 3 + TypeScript ·
Tailwind 4 · Wayfinder · Maatwebsite Excel · DomPDF · Pest.

## Puesta en marcha (desarrollo)

```bash
composer install
npm install
cp .env.example .env          # si aún no existe
php artisan key:generate
php artisan migrate
php artisan db:seed            # datos ficticios de demostración
php artisan storage:link
composer run dev               # o: npm run dev + php artisan serve
```

BD de desarrollo: SQLite (`database/database.sqlite`).

## Usuarios de demostración

Contraseña de todos: `password`.

| Correo | Rol | Empresas |
| --- | --- | --- |
| `superadmin@example.test` | Superadministrador | todas |
| `admin.ab@example.test` | Administrador | Industrias del Valle + Alimentos Sierra Verde |
| `admin.c@example.test` | Administrador | Logística Ferro |
| `supervisor.a@example.test` | Supervisor | Industrias del Valle (2 sucursales) |
| `encargado.a@example.test` | Encargado | Industrias del Valle (1 sucursal) |
| `colaborador.a@example.test` | Colaborador | Industrias del Valle (portal propio) |

## Verificaciones

```bash
vendor/bin/pint                        # formato PHP
vendor/bin/phpstan analyse --memory-limit=1G   # estático (nivel 7, 0 errores)
npm run check                          # ESLint + Prettier + tipos
npm run build                          # build de assets
php artisan test --compact             # Pest
```

## Documentación

Ver `docs/`: `ARQUITECTURA.md`, `DISENO_BASE_DATOS.md`, `MULTIEMPRESA.md`,
`PERMISOS.md`, `INVENTARIO.md`, `ENTREGAS_Y_ACUSES.md`, `SEGURIDAD.md`,
`DESPLIEGUE.md`. Resumen y reglas permanentes en `CLAUDE.md`.
