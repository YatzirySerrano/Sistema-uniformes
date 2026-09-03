<?php

namespace App\Models;

use App\Enums\RolSistema;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property bool $activo
 * @property Carbon|null $ultimo_acceso_en
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'activo'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'ultimo_acceso_en' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Empresas que este usuario está autorizado a operar.
     *
     * @return BelongsToMany<Empresa, $this>
     */
    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'empresa_usuario', 'usuario_id', 'empresa_id')->withTimestamps();
    }

    /**
     * Sucursales concretas a las que el usuario tiene acceso. Si un usuario no
     * tiene sucursales asignadas dentro de una empresa autorizada, se asume
     * acceso a todas las sucursales de esa empresa (ver App\Soporte\AccesoEmpresa).
     *
     * @return BelongsToMany<Sucursal, $this>
     */
    public function sucursales(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'sucursal_usuario', 'usuario_id', 'sucursal_id')->withTimestamps();
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'usuario_id');
    }

    public function esSuperadministrador(): bool
    {
        return $this->hasRole(RolSistema::Superadministrador->value);
    }

    public function esAdministrador(): bool
    {
        return $this->hasRole(RolSistema::Administrador->value);
    }

    /**
     * ¿El usuario tiene alcance global sobre los módulos de negocio (empresas,
     * sucursales, colaboradores, inventario, …)?
     *
     * Cierto para el equipo técnico/proveedor (Superadministrador) y para la
     * dirección del cliente (Administrador). Las empresas son entidades globales
     * de la plataforma: estos roles ven y administran todas, sin depender de la
     * relación `empresa_usuario`. Los roles restringidos (Supervisor, Encargado,
     * roles personalizados) sí quedan acotados por `empresa_usuario` /
     * `sucursal_usuario`.
     */
    public function tieneAlcanceGlobal(): bool
    {
        return $this->esSuperadministrador() || $this->esAdministrador();
    }

    /**
     * ¿El usuario tiene acceso autorizado a la empresa indicada?
     */
    public function puedeAccederEmpresa(Empresa|int $empresa): bool
    {
        if ($this->tieneAlcanceGlobal()) {
            return true;
        }

        $empresaId = $empresa instanceof Empresa ? $empresa->getKey() : $empresa;

        return $this->empresas()->whereKey($empresaId)->exists();
    }

    /**
     * ¿El usuario tiene acceso a la sucursal indicada (y a su empresa)?
     */
    public function puedeAccederSucursal(Sucursal $sucursal): bool
    {
        if (! $this->puedeAccederEmpresa($sucursal->empresa_id)) {
            return false;
        }

        if ($this->tieneAlcanceGlobal()) {
            return true;
        }

        $asignadasEnEmpresa = $this->sucursales()
            ->where('sucursales.empresa_id', $sucursal->empresa_id)
            ->count();

        // Sin asignación específica => acceso a todas las sucursales de la empresa.
        if ($asignadasEnEmpresa === 0) {
            return true;
        }

        return $this->sucursales()->whereKey($sucursal->getKey())->exists();
    }
}
