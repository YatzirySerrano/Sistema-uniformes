# Despliegue

## Variables de entorno (producción)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_MX

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uniformes
DB_USERNAME=uniformes_app        # usuario de mínimo privilegio (sin GRANT/DROP DATABASE)
DB_PASSWORD=********

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database        # si se encolan trabajos (p. ej. PDF)
MAIL_MAILER=smtp                 # correos de verificación / recuperación

UNIFORMES_ZONA_HORARIA=America/Mexico_City
UNIFORMES_PERMITIR_STOCK_NEGATIVO=false
```

> Las variables nuevas están en `.env.example`. **No** se modifica `.env` desde
> el repositorio.

## Base de datos

- Motor: MySQL 8 / MariaDB 10.6+, tablas InnoDB, charset `utf8mb4`.
- Las migraciones son agnósticas; ejecutar `php artisan migrate --force`.
- Para partir de datos limpios sin demo, ejecutar sólo
  `php artisan db:seed --class=RolesPermisosSeeder` y crear el primer
  Superadministrador manualmente (tinker o un seeder propio con datos reales).

## Pasos

```
composer install --no-dev --optimize-autoloader
php artisan key:generate           # sólo primera vez
php artisan migrate --force
php artisan storage:link
npm ci && npm run build
php artisan config:cache route:cache view:cache
php artisan queue:work             # si se usa cola (supervisor/systemd)
php artisan schedule:work          # si se añaden tareas programadas
```

## Servidor web

- HTTPS obligatorio. Cabeceras recomendadas: `Strict-Transport-Security`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (o CSP
  `frame-ancestors`), CSP razonable para la SPA de Inertia.
- Servir sólo `public/` como *document root*.
- `storage/app/private` **no** debe ser accesible por URL (firmas y PDF se
  entregan por Controller).

## Almacenamiento de archivos

- Disco `public`: branding e imágenes de prenda (vía `storage:link`).
- Disco `local` (privado): firmas, PDF de acuses y archivos temporales de
  importación.
- Para S3, apuntar el disco `local` a un bucket privado y añadir credenciales;
  el código usa `Storage::disk('local')`.

## Copias de seguridad

Respaldo diario de la base de datos y de `storage/app/private` (firmas y PDF son
evidencia). Probar restauración periódicamente.

## Permisos de sistema de archivos

`storage/` y `bootstrap/cache/` con escritura para el usuario del servidor web;
el resto de solo lectura.

## Hardening antes de producción

Sin `dd()`/`dump()`/`console.log` de depuración, sin rutas temporales, sin
credenciales reales en seeders, sin permisos temporales. Revisar
`docs/SEGURIDAD.md`.
