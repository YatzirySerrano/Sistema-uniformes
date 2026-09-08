---
paths:
    - 'app/Http/Controllers/Auth/VerificarCorreoController.php,app/Providers/AppServiceProvider.php,bootstrap/app.php,lang/es.json'
---

# Providers

## Verificación de correo: ruta propia sin sesión + correo 100% en español

`verification.verify` de Fortify exige sesión del MISMO usuario (`VerifyEmailRequest::authorize()` compara `auth()->id()` con el `{id}` de la ruta) → 403 «This action is unauthorized» cuando un admin abre el enlace de un usuario recién creado. Se REEMPLAZA la ruta (misma URI/nombre, registrada en `AppServiceProvider::boot()` dentro de `$this->app->booted()` para ganar el binding) por `App\Http\Controllers\Auth\VerificarCorreoController`: SIN `auth`, con `signed` (firma+expiración) + `throttle:verificacion-correo` (15/min por id+ip, limiter en `FortifyServiceProvider`). El controlador revalida `hash_equals(sha1($user->email), $hash)`, respeta ya-verificado, dispara `Verified`, y redirige a `login` con `status` (o a `dashboard` con toast si es el mismo usuario) — NUNCA `Auth::login()` ni toca la sesión activa. `bootstrap/app.php` `withExceptions` convierte `InvalidSignatureException`/`ThrottleRequestsException` en `email/verify*` en un redirect a login con mensaje claro (la firma se sigue validando). URL consistente: `AppServiceProvider::configureUrlFirmadas()` hace `URL::forceRootUrl(config('app.url'))` (+`forceScheme('https')` si APP_URL es https) y `bootstrap/app.php` añade `trustProxies(at:'*')`. Traducción: `Illuminate\Auth\Notifications\VerifyEmail::toMailUsing()` en `AppServiceProvider` (asunto/greeting/líneas/botón/`salutation` "Saludos,<br>Sistema Uniformes"), + claves añadidas a `lang/es.json` ("Verify your email address", "Regards,", subcopy con «»). Requiere `APP_URL` correcto por entorno + `php artisan config:clear` tras cambiarlo.
