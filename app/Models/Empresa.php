<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $codigo
 * @property string $nombre_comercial
 * @property string|null $razon_social
 * @property string|null $logo_ruta
 * @property string $color_principal
 * @property string $color_secundario
 * @property string $color_acento
 * @property bool $activa
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'codigo',
        'nombre_comercial',
        'razon_social',
        'rfc',
        'logo_ruta',
        'telefono',
        'correo',
        'direccion',
        'color_principal',
        'color_secundario',
        'color_acento',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'empresa_usuario', 'empresa_id', 'usuario_id')->withTimestamps();
    }

    /**
     * @return HasMany<Sucursal, $this>
     */
    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    /**
     * Sucursales activas de la empresa. La condición replica `Sucursal::scopeActivas`
     * y es la única definición de "sucursal activa" usada en los contadores.
     *
     * @return HasMany<Sucursal, $this>
     */
    public function sucursalesActivas(): HasMany
    {
        return $this->hasMany(Sucursal::class)->where('activa', true);
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    /**
     * Colaboradores activos de la empresa. La condición replica
     * `Colaborador::scopeActivos` y es la única definición de "colaborador activo"
     * usada en los contadores.
     *
     * @return HasMany<Colaborador, $this>
     */
    public function colaboradoresActivos(): HasMany
    {
        return $this->hasMany(Colaborador::class)->where('activo', true);
    }

    /**
     * @return HasMany<Prenda, $this>
     */
    public function prendas(): HasMany
    {
        return $this->hasMany(Prenda::class);
    }

    /**
     * @return HasMany<Talla, $this>
     */
    public function tallas(): HasMany
    {
        return $this->hasMany(Talla::class);
    }

    /**
     * @return HasMany<EntregaUniforme, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaUniforme::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Paleta de marca lista para inyectar como CSS custom properties.
     *
     * @return array<string, string>
     */
    public function tokensDeMarca(): array
    {
        return [
            '--marca-principal' => $this->color_principal,
            '--marca-principal-texto' => self::colorContrastante($this->color_principal),
            '--marca-secundaria' => $this->color_secundario,
            '--marca-secundaria-texto' => self::colorContrastante($this->color_secundario),
            '--marca-acento' => $this->color_acento,
            '--marca-acento-texto' => self::colorContrastante($this->color_acento),
        ];
    }

    /**
     * Devuelve #ffffff o #0f172a según la luminancia del color de fondo para
     * mantener contraste legible (WCAG aproximado).
     */
    public static function colorContrastante(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#0f172a';
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $luminancia = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminancia > 0.55 ? '#0f172a' : '#ffffff';
    }
}
