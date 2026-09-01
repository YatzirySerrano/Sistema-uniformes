# Seguridad

## Autenticación

- Laravel Fortify: login, recuperación de contraseña, verificación de correo,
  2FA (TOTP con confirmación y confirmación de contraseña), confirmación de
  contraseña para áreas sensibles. Passkeys y Teams deshabilitados; **registro
  público deshabilitado** (no existe `/register`).
- Contraseñas con `Hash` (bcrypt). CSRF, regeneración de sesión y rate limiting
  de Fortify (`login`, `two-factor`, `passkeys`).
- `Fortify::authenticateUsing` rechaza a los usuarios `activo = false` con un
  mensaje en español; el middleware `VerificarUsuarioActivo` además cierra la
  sesión de cualquier usuario desactivado en curso.
- Se registra `ultimo_acceso_en` en cada login.

## Autorización

- `Gate::before` concede todo al Superadministrador.
- Resto: permisos Spatie (`can('...')`) + Policies que revalidan pertenencia a
  empresa/sucursal. Nada depende sólo del nombre del rol.
- IDOR: binding de modelo + Policy + verificación `empresa_id === empresaActiva`.
- Mass assignment: `empresa_id`, `creado_por`, `encargado_id`, `realizado_por`,
  `confirmada_en`, folios y hashes los determina el servidor, nunca el navegador.
  No se usa `Model::create($request->all())`.

## Multitenant

Ver `MULTIEMPRESA.md`. Nunca se confía en `empresa_id` enviado por el navegador:
se toma de `ContextoEmpresa` y las FKs se validan con `Rule::exists()->where('empresa_id', ...)`.

## Archivos

| Tipo                                 | Disco                                                    | Ruta                                                   |
| ------------------------------------ | -------------------------------------------------------- | ------------------------------------------------------ |
| Branding (logos), imágenes de prenda | `public` (`storage/app/public`, requiere `storage:link`) | `empresas/{id}/…`, `prendas/{id}/…`                    |
| Firmas manuscritas                   | `local` **privado** (`storage/app/private`)              | `firmas/{empresa}/{uuid}.png`                          |
| Comprobantes PDF                     | `local` **privado**                                      | `acuses/{empresa}/{uuid}.pdf`                          |
| Archivos temporales de importación   | `local` **privado**                                      | `importaciones/{empresa}/…` (se borran tras confirmar) |

Las firmas y los PDF sólo se sirven por Controller con Policy.

## Validación

Toda entrada importante se valida en backend con Form Requests o
`$request->validate()`. Los mensajes están en español (`lang/es/validation.php`,
`lang/es/auth.php`, `lang/es/passwords.php`, `lang/es.json`). Vue sólo mejora la
UX.

## Errores de negocio

`App\Excepciones\ExcepcionDeNegocio` (y subclases: `ExistenciasInsuficientesException`,
`EntregaYaFirmadaException`, `AccesoEmpresaNoAutorizadoException`,
`ExcepcionDeNegocioSimple`) producen `redirect()->back()` con el mensaje en el
saco de errores y como _toast_, o `422` JSON para peticiones `expectsJson`. Nunca
un 500 para una operación inválida previsible.

## Firma de recepción

Ver `ENTREGAS_Y_ACUSES.md`: validación estricta de la imagen, almacenamiento
privado por UUID, snapshot inmutable y hashes SHA-256 (que **no** son firma
electrónica avanzada).

## Logs

No se registran contraseñas, cookies, cabeceras de autorización, tokens ni la
firma base64 completa.

## Producción (resumen, ver `DESPLIEGUE.md`)

`APP_ENV=production`, `APP_DEBUG=false`, HTTPS, cookies `secure` + `SameSite`,
HSTS, `X-Content-Type-Options`, política de frame, BD privada con usuario de
mínimo privilegio, `storage/` privado, backups.
