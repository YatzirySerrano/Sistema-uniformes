<?php

namespace App\Providers;

use App\Enums\RolSistema;
use App\Http\Controllers\Auth\VerificarCorreoController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAutorizacion();
        $this->configureVerificacionCorreo();
        $this->configureReportesPdf();
        Schema::defaultStringLength(191);

        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));
    }

    /**
     * Reemplaza la ruta `verification.verify` de Fortify (que exige sesión
     * del MISMO usuario y por eso daba 403 al abrir el enlace con una sesión
     * de administrador) por una que funciona sin sesión, conservando `signed`
     * (firma + expiración) y un throttle. También traduce por completo al
     * español el correo de verificación.
     */
    protected function configureVerificacionCorreo(): void
    {
        // Se registra en `booted()` para quedar DESPUÉS de las rutas de
        // Fortify y de `routes/web.php`; así esta definición gana el binding
        // del nombre `verification.verify` (misma URI y parámetros).
        $this->app->booted(function (): void {
            Route::middleware('web')
                ->get('email/verify/{id}/{hash}', VerificarCorreoController::class)
                ->middleware(['signed', 'throttle:verificacion-correo'])
                ->name('verification.verify');
        });

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject('Verifica tu correo electrónico')
            ->greeting('¡Hola!')
            ->line('Haz clic en el botón para verificar tu dirección de correo electrónico.')
            ->action('Verificar correo electrónico', $url)
            ->line('Si no creaste una cuenta, no es necesario que hagas nada.')
            ->salutation("Saludos,  \nSistema Uniformes"));
    }

    /**
     * `spatie/laravel-pdf` (driver Browsershot) necesita la ruta de un
     * binario Chrome/Chromium real. En producción se fija explícitamente con
     * `LARAVEL_PDF_CHROME_PATH` (ver runbook de despliegue); si no está
     * configurada, se autodetectan rutas habituales (Chrome de macOS para
     * este equipo de desarrollo, Chromium/Chrome de Linux) para que los
     * reportes ejecutivos funcionen sin tocar `.env`. Si no se encuentra
     * nada, se deja sin configurar: Browsershot falla con su propio mensaje
     * claro en vez de que aquí se invente una ruta que no existe.
     */
    protected function configureReportesPdf(): void
    {
        if (config('laravel-pdf.browsershot.chrome_path')) {
            return;
        }

        $rutasConocidas = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
        ];

        $ruta = collect($rutasConocidas)->first(fn (string $r): bool => is_file($r) && is_executable($r));

        if ($ruta !== null) {
            config(['laravel-pdf.browsershot.chrome_path' => $ruta]);
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8)->letters(),
        );
    }

    /**
     * El Superadministrador (equipo técnico / proveedor) tiene alcance global.
     * El resto de la autorización se resuelve por permisos y por acceso a la
     * empresa correspondiente en cada Policy.
     */
    protected function configureAutorizacion(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole(RolSistema::Superadministrador->value) ? true : null;
        });
    }
}
