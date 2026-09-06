<?php

namespace App\Providers;

use App\Enums\RolSistema;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        Schema::defaultStringLength(191);

        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));
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
