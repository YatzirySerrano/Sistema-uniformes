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

Sistema web empresarial multiempresa para controlar la entrega de **activos** a
colaboradores (uniformes y también equipo de cómputo, dispositivos móviles,
accesorios, etc.): inventario, entregas con firma de recepción, comprobantes
PDF, devoluciones, correcciones, reportes y auditoría.

> El sistema dejó de ser exclusivo de uniformes. "Prendas" evolucionó a
> **Activos**. Una **PRENDA** es un activo individual; un **UNIFORME** es un
> conjunto de prendas (módulo Uniformes / Conjuntos — pendiente).
>
> **Bloque A (arquitectura de fundación):** un mismo almacén abastece a varias
> empresas / razones sociales (`Almacén ↔ Empresa` = **N:M**, tabla
> `almacen_empresa`). El inventario por cantidad se llavea por
> `EMPRESA + ALMACÉN + ACTIVO + VARIANTE = STOCK`; el stock de cada empresa se
> mantiene separado aunque compartan almacén. La sucursal es sólo el
> destino/contexto del colaborador, nunca dimensión de inventario.
>
> **No hay "empresa activa".** El contexto de empresa se determina por recurso /
> formulario / filtro y el backend siempre valida el acceso (Policy + Form
> Request); nunca se confía en el `empresa_id` del frontend. No existe
> `ContextoEmpresa`, selector de empresa en el sidebar, ni `empresa_activa_id` en
> sesión.

## Reglas permanentes de este proyecto

- **NO HACER COMMIT. NO HACER PUSH. NO HACER MERGE. NO HACER PULL / REBASE.**
- **NO MODIFICAR `.env`.** Si se necesitan variables nuevas, actualizar `.env.example`.
- **NO EJECUTAR OPERACIONES DESTRUCTIVAS** (`migrate:fresh`, `db:wipe`, `DROP`, `rm -rf`, `composer update` masivo) sin autorización expresa.
- **INTERFAZ EN ESPAÑOL.** Todo texto visible (menús, botones, validaciones, errores, correos, PDF, Excel) va en español.
- **CÓDIGO DE DOMINIO NUEVO EN ESPAÑOL** cuando sea técnicamente razonable (modelos, acciones, servicios, componentes Vue, tablas y columnas).
- **RESPETAR LAS CONVENCIONES OBLIGATORIAS DE LARAVEL** (`id`, `created_at`, `updated_at`, `deleted_at`, sufijos `Controller`/`Policy`/`Request`, namespaces autodescubiertos).
- **EL USUARIO NO DEBE ADIVINAR.** Cada módulo / formulario / campo / acción ambiguo se explica con esta jerarquía (sin saturar de tooltips): (1) label comprensible por sí mismo; (2) descripción breve bajo el título del módulo (`EncabezadoPagina descripcion`); (3) texto de ayuda bajo el campo cuando aporte; (4) `AyudaTooltip` sólo para aclaraciones adicionales — nunca para ocultar información esencial. Todo icon-button lleva `aria-label` (y `title`/tooltip si el icono no es obvio). Validación **visible**: errores por campo, inline, y en filas repetibles con la clave `items.N.<campo>` junto a su fila; resumen discreto arriba ("Revisa los campos marcados"); nunca `alert()` nativo. Deshabilitar el botón sólo por faltantes estructurales; el error real se muestra al interactuar / enviar. La validación backend siempre es obligatoria (nunca sólo frontend).
- **BÚSQUEDA COMO REGLA.** Si un catálogo puede crecer (almacenes, activos, categorías, tipos, colaboradores, responsables, sucursales, áreas, variantes numerosas, unidades serializadas, uniformes…), no usar un `<select>` plano gigante: usar combobox con buscador (`BuscadorAsync.vue`). Filtro local si el catálogo es pequeño; búsqueda remota debounced (`/<recurso>/buscar`) si puede crecer a miles.

## Stack

- Backend: PHP 8.4, Laravel 13, Fortify (login, verificación de correo, 2FA, confirmación de contraseña), Spatie Laravel Permission, Wayfinder.
- Frontend: Vue 3 `<script setup lang="ts">`, Inertia 3, Tailwind 4, shadcn-vue/reka-ui, Lucide, Vite.
- Documentos: `maatwebsite/excel` (import/export), `barryvdh/laravel-dompdf` (PDF).
- BD de desarrollo: MariaDB/MySQL (config actual del equipo, p. ej. MAMP en `127.0.0.1:8889`, base `uniformes`). SQLite también soportado. Producción recomendada: MySQL/MariaDB InnoDB utf8mb4 (ver `docs/DESPLIEGUE.md`). Las migraciones son forward-only y agnósticas al motor; nunca usar `migrate:fresh`.
- Calidad: Pint, Larastan/PHPStan (nivel 7, sin errores), ESLint + Prettier (`npm run check`), `vue-tsc`, Pest.

## Arquitectura

`Ruta → Controller (delgado) → Form Request → Acción/Servicio → Modelo → BD`

- `app/Acciones/` — casos de uso transaccionales: `CrearEntregaUniforme`, `ConfirmarAcuseRecepcion`, `RegistrarEntradaInventario`, `AjustarInventario`, `RegistrarDevolucion`, `CorregirEntrega`.
- `app/Servicios/` — lógica reutilizable: `ServicioInventario` (única puerta del inventario, por `empresa + almacén`), `ResolverAlmacenOperativo` (`paraEmpresa()`: resuelve el almacén de origen de entregas/devoluciones), `ServicioAuditoria` (única puerta de la bitácora; `empresa_id` siempre explícito), `ServicioFolios`, `ServicioAcusePdf`, `ServicioImportacionColaboradores`, `ServicioDashboard`, `ServicioReportes`.
- `app/Soporte/` — `AccesoEmpresa` (servicio stateless: empresas/sucursales/almacenes autorizados de un usuario — reemplaza a `ContextoEmpresa`, eliminado), `Permisos` (catálogo), `ValidadorFirma`.
- `app/Http/Requests/Concerns/ResuelveEmpresa.php` — `empresaResuelta()`: resuelve y valida la empresa de un Form Request (input en alta, modelo de ruta en edición).
- `app/Excepciones/` — reglas de negocio (`ExcepcionDeNegocio` → respuesta controlada en español, nunca 500).
- `app/Enums/` — `RolSistema`, `EstadoEntrega`, `TipoMovimiento` (`MigracionLegacy` se conserva sólo para filas históricas), `DireccionMovimiento`, `CondicionDevolucion`, `TipoControlActivo`.
- Módulos de catálogo/organización: `AreaController`, `AlmacenController`, `ActivoController`, `TipoActivoController`, `CategoriaActivoController`, `CatalogoActivoController` (+ `Area`, `Almacen`, `Activo`, `TipoActivo`, `CategoriaActivo`, sus Policies y Form Requests en `Http/Requests/{Areas,Almacenes,Activos}`). Patrón calcado de Sucursales/Empresas.

## Multiempresa

- Jerarquía funcional: PLATAFORMA → { EMPRESAS · ALMACENES } → OPERACIÓN. Cada
  EMPRESA agrupa SUCURSALES · ÁREAS · COLABORADORES · ACTIVOS.
    - **ALMACÉN ↔ EMPRESA: N:M** (`almacen_empresa`). Un almacén abastece a
      varias empresas / razones sociales. El almacén NO pertenece a una empresa y
      NO se relaciona con sucursales (`almacenes.empresa_id` y `almacen_sucursal`
      eliminados).
    - **INVENTARIO**: llave `EMPRESA + ALMACÉN + ACTIVO + VARIANTE`
      (`saldos_inventario` = `empresa_id + almacen_id + activo_id + talla_id`).
      El stock de cada empresa se mantiene **separado** dentro del almacén
      compartido. La sucursal no es fuente de stock (`saldos_inventario` ya no
      tiene `sucursal_id`).
- **No hay "empresa activa".** No existe `ContextoEmpresa`, ni middleware
  `ResolverEmpresaActiva`, ni `empresa_activa_id` en sesión, ni selector de
  empresa en el sidebar. El contexto se resuelve por:
    - **listado** → filtro opcional `?empresa_id=` (searchable). Por defecto
      todo lo autorizado.
    - **alta** → campo `empresa_id` del formulario, validado en el Form Request
      (`ResuelveEmpresa`) contra el alcance del usuario.
    - **edición / detalle / estado** → la empresa la fija el registro; la Policy
      revalida `puedeAccederEmpresa($modelo->empresa_id)` (acceso ajeno = 403).
    - **entrega / devolución** → empresa **derivada del colaborador**.
- `App\Soporte\AccesoEmpresa` resuelve `empresasAutorizadas` /
  `sucursalesAutorizadas` / `almacenesAutorizados` de un usuario.
  `HandleInertiaRequests` comparte `empresasAutorizadas` (para los combobox).
- Las **empresas son entidades globales** de la plataforma. Relación N:M
  `empresa_usuario` / `sucursal_usuario` (columna `usuario_id`) acota a los
  **roles restringidos**; **no** limita a Superadministrador ni Administrador.
- **Superadministrador**: equipo técnico/proveedor. Alcance global
  (`Gate::before`). **Administrador**: dirección del cliente. Alcance global
  sobre los módulos de negocio (todas las empresas). `User::tieneAlcanceGlobal()`
  centraliza la regla.
- **Supervisor / Encargado**: sólo las empresas de `empresa_usuario` y las
  sucursales de `sucursal_usuario` (o todas las de la empresa si no tienen
  ninguna asignada). Un `empresa_id` fuera de su alcance → rechazo controlado
  (403 / error de validación).
- Aislamiento en varias capas: `AccesoEmpresa` + consultas explícitas
  (`scopeDeEmpresa`, `whereIn('empresa_id', autorizadas)`) + Policies (revalidan
  `puedeAccederEmpresa`) + Form Requests (`ResuelveEmpresa` + FKs cruzadas) +
  tests.

## Roles y permisos

- Spatie. Roles base (seeder): `superadministrador`, `administrador`, `supervisor`, `encargado`, `colaborador`.
- Permisos granulares `recurso.accion` (catálogo en `App\Soporte\Permisos`). Grupos: empresas, sucursales, **almacenes** (`almacenes.ver/crear/editar/administrar`), usuarios, roles, colaboradores, **areas** (`areas.ver/crear/editar/desactivar`), **activos** (`activos.ver/crear/editar/administrar` + `tallas.administrar` + `tipos-activo.administrar` + `categorias-activo.administrar`), **inventario** (`inventario.ver/entrada/ajustar/minimos/transferir`; `inventario.migrar` **eliminado** en el Bloque A), entregas, acuses, devoluciones, reportes, auditoría, configuración. El Administrador puede crear roles personalizados y asignar permisos. No se hardcodea autorización por nombre de rol (salvo el bypass del Superadministrador).

## Almacenes / Áreas / Activos

- **Almacén** (`almacenes`): **N:M con empresas abastecidas** (`almacen_empresa`); NO pertenece a una empresa; `codigo` autogenerado y único **a nivel plataforma** (`ALM-0001`); `responsable_colaborador_id` nullable (combobox con búsqueda `almacenes/colaboradores-buscar?empresa_id=`, dentro de alguna empresa abastecida). Alta/edición: campo `empresa_ids[]` (≥ 1). `AlmacenPolicy` autoriza si el usuario accede a alguna empresa del almacén. Desactivar un almacén lo saca de operaciones **para todas sus empresas**: no toca el catálogo de activos ni los históricos.
- **Área / Departamento** (`areas`): pertenece a la empresa; `codigo` `ARE-0001`. `colaboradores.area_id` es la **fuente de verdad**; la columna de texto `colaboradores.area` se conserva como espejo temporal hasta la reingeniería de Colaboradores (`ColaboradorController` sincroniza el nombre). Relación en el modelo: `Colaborador::departamento()`.
- **Tipo de activo** (`tipos_activo`): catálogo por empresa con **CRUD completo** (`TipoActivoController`, pantalla `Activos/Catalogos.vue`, permiso `tipos-activo.administrar`). Tipos base sembrados: Prenda, Equipo de cómputo, Dispositivo móvil, Electrónico, Accesorio, Herramienta / Equipo, Otro. **No existe "Uniforme"** como tipo (un uniforme es un conjunto de activos). Alta rápida "Otro" desde el formulario de Activo: `POST tipos-activo/rapido` (JSON).
- **Categoría de activo** (`categorias_activo`): catálogo real por empresa (`CategoriaActivoController`, permiso `categorias-activo.administrar`), sustituye al texto libre. `tipo_activo_id` nullable (relación opcional). `activos.categoria_id` es la fuente de verdad; `activos.categoria` (texto) se conserva como **espejo temporal** sincronizado por `ActivoController`. Alta rápida "Otra": `POST categorias-activo/rapido`.
- **Activo** (`activos`, antes `prendas`; pivote `activo_talla`, FK `activo_id` en inventario y detalles): `codigo` `ACT-0001`; `tipo_activo_id` → `tipos_activo` (**tipo** = clasificación general/naturaleza: Prenda, Equipo de cómputo, Dispositivo móvil…); `categoria_id` → `categorias_activo` (**categoría** = clasificación específica dentro del tipo: Camisola, Laptop, Teléfono celular…); `tipo_control` (`App\Enums\TipoControlActivo`: `cantidad` | `serializado`). Serializado oculta variantes/tallas en el formulario. El flujo de **unidades serializadas** (serie/IMEI, entidad `UnidadActivo`) sigue **pendiente**.
- **Variantes / tallas** (`tallas`): el usuario **no captura `orden`** (se asigna `max(orden)+1` al crear; se reordena con ↑/↓ vía `POST tallas/reordenar`). Cada empresa tiene una **talla comodín** (`tallas.es_comodin = true`, `valor = 'Sin variante'`) creada por migración y por el hook `Empresa::booted()`; representa "sin variante" y **no** se muestra en la administración (`scopeSeleccionables`) ni en el selector del formulario de activo. Un activo por cantidad sin variantes propias usa la comodín automáticamente en inventario. Alta rápida desde el formulario de activo: `POST tallas/rapido` (JSON).

## Inventario (por EMPRESA + ALMACÉN)

- `saldos_inventario` (saldo actual, único por `empresa + almacen_id + activo + talla` — índice `saldos_inv_almacen_unico`). **Ya no tiene `sucursal_id`.** Un almacén compartido guarda saldos independientes para cada empresa.
- `movimientos_inventario` (historia append-only): **conserva** `sucursal_id` nullable como procedencia histórica; nunca en operación nueva.
- Toda modificación pasa por `ServicioInventario::registrarMovimiento()` (dimensión = `empresa + almacén`) dentro de `DB::transaction()` con `lockForUpdate()`. **No se permite stock negativo**. Mínimos por `empresa + almacén + activo + talla`.
- `InventarioController` (`index` / `entrada` / `ajuste` / `minimos`) y `MovimientoInventarioController`: filtran/reciben `empresa_id` + `almacen_id`; validan `puedeAccederEmpresa` + `Rule::exists('almacen_empresa','almacen_id')->where('empresa_id', ...)` + almacén activo. Un almacén desactivado no admite entradas/ajustes.
- **Registrar entrada** (`RegistrarEntradaInventarioRequest` + `Inventario/Entrada.vue`): primero se elige `empresa_id`; el almacén (`/almacenes/buscar?empresa_id=`) y los activos (`/activos/buscar?empresa_id=&control=cantidad`) se acotan a esa empresa. Errores inline por fila (`items.N.<campo>`); la variante se pide **sólo** si el activo tiene variantes propias (si no, se usa la comodín); serializados rechazados; fila duplicada marcada. `items.*.talla_id` es `nullable`.
- **Entregas / Devoluciones / Correcciones** (puente, reingeniería en fase E/F): la empresa se **deriva del colaborador**; `ResolverAlmacenOperativo::paraEmpresa(Empresa, ?int $almacenPreferido)` obtiene el almacén de origen (activo **único** que abastece a la empresa; cero o varios → error en español, nunca 500). `entregas_uniformes.almacen_id` y `devoluciones.almacen_id` guardan la procedencia.
- **No hay migración legacy.** `MigracionInventarioController`, `MigrarSaldosLegacyAAlmacen`, la ruta `/inventario/migracion`, el permiso `inventario.migrar` y la pantalla `Inventario/MigracionLegacy.vue` fueron eliminados en el Bloque A. Las migraciones `..._000016/17/18` consolidan y limpian el esquema (forward-only).

## Entregas, firmas y acuses

- `entregas_uniformes` + `detalles_entrega` (con snapshot `activo_nombre_snapshot` y `talla_valor_snapshot`). El snapshot inmutable del acuse usa la clave `activo` (los acuses previos guardaron `prenda`; la plantilla lee ambas).
- Al registrar la entrega se descuenta el inventario (movimiento `entrega`). Estado inicial `pendiente_firma`.
- Firma manuscrita (`components/entregas/PadFirma.vue`, pointer events, funciona en móvil/tablet/desktop). Se valida en backend (`ValidadorFirma`: base64 estricto, magic bytes, MIME, peso, dimensiones, no vacía).
- `ConfirmarAcuseRecepcion`: congela un **snapshot inmutable**, guarda la firma en disco **privado** (`storage/app/private/firmas/{empresa}/{uuid}.png`), calcula `hash_documento` y `hash_firma` (SHA-256), crea el `acuses_recepcion` y marca la entrega firmada. El **PDF se materializa después de confirmar la transacción**; si falla, el acuse sigue siendo válido y el PDF se puede regenerar.
- Acceso a firma y PDF **sólo por Controller** con Policy (`AcuseRecepcionPolicy`): Superadministrador, Administrador, Supervisor/Encargado autorizados y el colaborador titular. Nunca URL pública.
- Entrega firmada: no se edita; se aplica **corrección administrativa** (`CorregirEntrega`) que conserva el original y el acuse, compensa el inventario y queda en auditoría.

## Auditoría

- `bitacora_auditoria` append-only (sin `updated_at`). Única escritura desde `ServicioAuditoria::registrar()`. Auditan crear/editar/activar/desactivar de Almacén, Área, Activo, **Tipo de activo** y **Categoría de activo**; los cambios de relación `Almacén ↔ Sucursal`; entradas/ajustes/mínimos de inventario; y la **migración legacy** (`modulo=inventario`, `accion=migracion_legacy`).

## Frontend

- Páginas por dominio en `resources/js/pages/{Colaboradores,Areas,Activos,Almacenes,Inventario,Entregas,Acuses,Devoluciones,Reportes,Empresas,Sucursales,Usuarios,Roles,Auditoria,Portal}`.
- Listados en **cards** (no tabla): Empresas, Sucursales, Áreas, Almacenes, Activos — con filtros búsqueda/estado/orden y "Limpiar filtros". Vistas de detalle a **ancho completo**.
- Componentes compartidos en `resources/js/components/sistema/` (incluye `BuscadorAsync.vue` = combobox con buscador — acepta `buscar` remoto o filtro local; props `placeholderBusqueda`, `sinResultados`, `disabled`, `invalido` —, `EncabezadoPagina.vue`, `EstadoVacio.vue`, `AyudaTooltip.vue`, `SelectorItemsActivos.vue`) y `resources/js/components/{entregas,areas,almacenes}/`. Endpoints JSON de búsqueda: `activos/buscar`, `almacenes/buscar`, `almacenes/colaboradores-buscar`.
- `usePermisos()` expone `puede()` y `empresasAutorizadas` desde los props compartidos por `HandleInertiaRequests` (`empresasAutorizadas`; ya no hay `contextoEmpresa`). El menú lateral (`AppSidebar.vue`) oculta opciones según permisos y **no** tiene selector de empresa. Orden: Panel · Personal (Colaboradores, Áreas) · Catálogo e inventario (Activos, Almacenes, Inventario, Movimientos) · Operación · Reportes · Administración · Auditoría. "Tipos y categorías" se abre desde el índice de Activos (`/activos-catalogos`); "Personalización" se abre desde el detalle de cada empresa (`/empresas/{empresa}/personalizacion`).
- Cada listado con datos por empresa lleva un filtro `Empresa` (`?empresa_id=`) visible sólo con más de una empresa autorizada; cada formulario de alta por empresa lleva un campo `Empresa` obligatorio (oculto al editar). Ver `.ai/rules/ux.md`.
- Branding por empresa: tokens CSS (`--marca-principal`, …) calculados en `Empresa::tokensDeMarca()` con contraste automático.
- Requisito global (no implementado): cada listado tendrá `[Exportar Excel]` / `[Exportar PDF]` respetando filtros; el query del `index` debe ser reutilizable por pantalla/PDF/Excel.

## Datos de prueba

`php artisan db:seed` prepara empresas A/B/C con branding distinto, sucursales, tallas, **tipos base y categorías** de activo, activos (uniformes + un serializado de ejemplo), áreas, **almacenes**: uno propio por empresa **más un "Almacén Central Morelos" compartido por todas** (`almacen_empresa`), ~165 colaboradores (con `area_id`), **inventario por empresa + almacén** (normal/bajo/cero, sólo activos por cantidad; separado por empresa en el almacén compartido), y entregas de ejemplo (pendiente, firmada con PDF, devuelta, corregida) que salen del almacén propio de la empresa. Contraseña de todos los usuarios ficticios: `password`. Superadministrador: `superadmin@example.test`.

> Tras el Bloque A, corre `php artisan migrate --force` y luego `php artisan db:seed` para regenerar los datos demo con la arquitectura N:M (las migraciones `..._000017/18` descartan saldos legacy por sucursal irrecuperables — sólo datos demo).

## Pendientes (bloques futuros)

- **Bloque A — HECHO**: Almacén ↔ Empresa N:M, fin de "empresa activa", inventario por empresa + almacén, limpieza legacy.
- Bloque B — tipo/categoría **opcionales** + alta inline dentro del combobox + Tipo/Categoría searchable.
- Bloque C — **activos serializados**: entidad `UnidadActivo` (serie/IMEI/etiqueta/MAC), entrada unidad por unidad, entrega/devolución por unidad, concurrencia.
- Bloque D — Módulo **Uniformes/Conjuntos** (`uniforme_activo`, disponibilidad calculada; sin stock propio).
- Bloque E/F — Reingeniería de UI de **Entregas / Devoluciones** (colaborador → empresa/sucursal derivadas, selector de almacén, uniformes, serializados, PDF nuevo). Hoy funcionan vía puente `ResolverAlmacenOperativo::paraEmpresa`.
- Bloque G — **Cascada de desactivación** (Empresa/Almacén/Área/Activo → dependientes) con causas de suspensión y reactivación selectiva; nunca alterar históricos.
- Bloque H — **Exportación Excel/PDF por módulo** con query service compartido.
- Bloque I — filtros searchable en el resto de módulos.
- Bloque J — componente compartido de **subida de archivos** (drag & drop) y su despliegue global.
- Bloque K — reordenar variantes con drag & drop accesible.
- Reingeniería de Colaboradores (importador Excel, expediente, INE, aviso de privacidad, doble firma) y Reportes nuevos.
- Pasada global de **responsive** (6 breakpoints); bug responsive/hover del sidebar; ancho completo en detalles restantes.

## Documentación

`docs/` (en español): `ARQUITECTURA.md`, `DISENO_BASE_DATOS.md`, `MULTIEMPRESA.md`, `PERMISOS.md`, `INVENTARIO.md`, `ENTREGAS_Y_ACUSES.md`, `SEGURIDAD.md`, `DESPLIEGUE.md`, `ALMACENES_AREAS_ACTIVOS.md`.
