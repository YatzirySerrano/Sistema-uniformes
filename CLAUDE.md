<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.

- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

# Sistema de Control y Gestión de Uniformes

Sistema web empresarial multiempresa para controlar la entrega de uniformes a
colaboradores: inventario por sucursal, entregas con firma de recepción,
comprobantes PDF, devoluciones, correcciones, reportes y auditoría.

## Reglas permanentes de este proyecto

- **NO HACER COMMIT. NO HACER PUSH. NO HACER MERGE. NO HACER PULL / REBASE.**
- **NO MODIFICAR `.env`.** Si se necesitan variables nuevas, actualizar `.env.example`.
- **NO EJECUTAR OPERACIONES DESTRUCTIVAS** (`migrate:fresh`, `db:wipe`, `DROP`, `rm -rf`, `composer update` masivo) sin autorización expresa.
- **INTERFAZ EN ESPAÑOL.** Todo texto visible (menús, botones, validaciones, errores, correos, PDF, Excel) va en español.
- **CÓDIGO DE DOMINIO NUEVO EN ESPAÑOL** cuando sea técnicamente razonable (modelos, acciones, servicios, componentes Vue, tablas y columnas).
- **RESPETAR LAS CONVENCIONES OBLIGATORIAS DE LARAVEL** (`id`, `created_at`, `updated_at`, `deleted_at`, sufijos `Controller`/`Policy`/`Request`, namespaces autodescubiertos).

## Stack

- Backend: PHP 8.4, Laravel 13, Fortify (login, verificación de correo, 2FA, confirmación de contraseña), Spatie Laravel Permission, Wayfinder.
- Frontend: Vue 3 `<script setup lang="ts">`, Inertia 3, Tailwind 4, shadcn-vue/reka-ui, Lucide, Vite.
- Documentos: `maatwebsite/excel` (import/export), `barryvdh/laravel-dompdf` (PDF).
- BD de desarrollo: SQLite (`database/database.sqlite`). Producción recomendada: MySQL/MariaDB InnoDB utf8mb4 (ver `docs/DESPLIEGUE.md`). Las migraciones son agnósticas al motor.
- Calidad: Pint, Larastan/PHPStan (nivel 7, sin errores), ESLint + Prettier (`npm run check`), `vue-tsc`, Pest.

## Arquitectura

`Ruta → Controller (delgado) → Form Request → Acción/Servicio → Modelo → BD`

- `app/Acciones/` — casos de uso transaccionales: `CrearEntregaUniforme`, `ConfirmarAcuseRecepcion`, `RegistrarEntradaInventario`, `AjustarInventario`, `RegistrarDevolucion`, `CorregirEntrega`.
- `app/Servicios/` — lógica reutilizable: `ServicioInventario` (única puerta del inventario), `ServicioAuditoria` (única puerta de la bitácora), `ServicioFolios`, `ServicioAcusePdf`, `ServicioImportacionColaboradores`, `ServicioDashboard`, `ServicioReportes`.
- `app/Soporte/` — `ContextoEmpresa` (empresa activa por petición), `Permisos` (catálogo), `ValidadorFirma`.
- `app/Excepciones/` — reglas de negocio (`ExcepcionDeNegocio` → respuesta controlada en español, nunca 500).
- `app/Enums/` — `RolSistema`, `EstadoEntrega`, `TipoMovimiento`, `DireccionMovimiento`, `CondicionDevolucion`.

## Multiempresa

- Jerarquía: PLATAFORMA → EMPRESA → SUCURSALES → OPERACIÓN.
- Relación N:M `empresa_usuario` y `sucursal_usuario` (columna `usuario_id`).
- La **empresa activa** vive en sesión (`empresa_activa_id`) y se resuelve en el middleware `ResolverEmpresaActiva` hacia el singleton `ContextoEmpresa`, validando siempre el acceso. Un contexto vacío NO concede acceso a nada.
- **Superadministrador**: equipo técnico/proveedor. Alcance global (`Gate::before`). Se crea por seeder.
- **Administrador**: dueños/directivos del cliente. Puede administrar **varias** empresas autorizadas; nunca entra a empresas no asignadas.
- Aislamiento en varias capas: contexto + consultas explícitas (`scopeDeEmpresa`) + Policies (revalidan `puedeAccederEmpresa`) + Form Requests (validación cruzada de FKs) + tests.

## Roles y permisos

- Spatie. Roles base (seeder): `superadministrador`, `administrador`, `supervisor`, `encargado`, `colaborador`.
- Permisos granulares `recurso.accion` (catálogo en `App\Soporte\Permisos`). El Administrador puede crear roles personalizados y asignar permisos. No se hardcodea autorización por nombre de rol (salvo el bypass del Superadministrador).

## Inventario

- `saldos_inventario` (saldo actual, único por empresa+sucursal+prenda+talla) + `movimientos_inventario` (historia append-only con `existencia_anterior`/`existencia_resultante`).
- Toda modificación pasa por `ServicioInventario::registrarMovimiento()` dentro de `DB::transaction()` con `lockForUpdate()` sobre la fila de saldo. **No se permite stock negativo** (mensaje en español). Mínimos configurables por saldo.

## Entregas, firmas y acuses

- `entregas_uniformes` + `detalles_entrega` (con snapshot de nombre de prenda y valor de talla).
- Al registrar la entrega se descuenta el inventario (movimiento `entrega`). Estado inicial `pendiente_firma`.
- Firma manuscrita (`components/entregas/PadFirma.vue`, pointer events, funciona en móvil/tablet/desktop). Se valida en backend (`ValidadorFirma`: base64 estricto, magic bytes, MIME, peso, dimensiones, no vacía).
- `ConfirmarAcuseRecepcion`: congela un **snapshot inmutable**, guarda la firma en disco **privado** (`storage/app/private/firmas/{empresa}/{uuid}.png`), calcula `hash_documento` y `hash_firma` (SHA-256), crea el `acuses_recepcion` y marca la entrega firmada. El **PDF se materializa después de confirmar la transacción**; si falla, el acuse sigue siendo válido y el PDF se puede regenerar.
- Acceso a firma y PDF **sólo por Controller** con Policy (`AcuseRecepcionPolicy`): Superadministrador, Administrador, Supervisor/Encargado autorizados y el colaborador titular. Nunca URL pública.
- Entrega firmada: no se edita; se aplica **corrección administrativa** (`CorregirEntrega`) que conserva el original y el acuse, compensa el inventario y queda en auditoría.

## Auditoría

- `bitacora_auditoria` append-only (sin `updated_at`). Única escritura desde `ServicioAuditoria::registrar()`.

## Frontend

- Páginas por dominio en `resources/js/pages/{Colaboradores,Prendas,Inventario,Entregas,Acuses,Devoluciones,Reportes,Empresas,Sucursales,Usuarios,Roles,Auditoria,Portal}`.
- Componentes compartidos en `resources/js/components/sistema/` y `resources/js/components/entregas/`.
- `usePermisos()` expone `puede()` / `contexto` desde los props compartidos por `HandleInertiaRequests`. El menú lateral (`AppSidebar.vue`) oculta opciones según permisos e incluye el selector de empresa.
- Branding por empresa: tokens CSS (`--marca-principal`, …) calculados en `Empresa::tokensDeMarca()` con contraste automático.

## Datos de prueba

`php artisan db:seed` prepara empresas A/B/C con branding distinto, sucursales, tallas, prendas, ~165 colaboradores, inventario (normal/bajo/cero), y entregas de ejemplo (pendiente, firmada con PDF, devuelta, corregida). Contraseña de todos los usuarios ficticios: `password`. Superadministrador: `superadmin@example.test`.

## Documentación

`docs/` (en español): `ARQUITECTURA.md`, `DISENO_BASE_DATOS.md`, `MULTIEMPRESA.md`, `PERMISOS.md`, `INVENTARIO.md`, `ENTREGAS_Y_ACUSES.md`, `SEGURIDAD.md`, `DESPLIEGUE.md`.
