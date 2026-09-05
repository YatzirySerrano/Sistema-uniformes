<?php

namespace App\Models;

use App\Soporte\ColorContraste;
use Illuminate\Database\Eloquent\Model;

/**
 * Personalización visual GLOBAL de la instancia (no por empresa, no por
 * usuario). Fila única con `id = 1`. Ver `variablesClaro()`/`variablesOscuro()`
 * para los custom properties CSS que consume `resources/views/app.blade.php`.
 *
 * @property int $id
 * @property string $color_principal
 * @property string $color_hover_principal
 * @property string $color_texto_boton_principal
 * @property string $fondo_general
 * @property string $fondo_tarjetas
 * @property string $fondo_sidebar
 * @property string|null $color_secundario
 */
class ConfiguracionSistema extends Model
{
    protected $table = 'configuracion_sistema';

    protected $fillable = [
        'color_principal',
        'color_hover_principal',
        'color_texto_boton_principal',
        'fondo_general',
        'fondo_tarjetas',
        'fondo_sidebar',
        'color_secundario',
    ];

    /**
     * Fila única de configuración. Se crea con los valores por defecto (el
     * tema claro original de shadcn) la primera vez que se consulta.
     */
    public static function actual(): self
    {
        return static::query()->firstOrCreate(['id' => 1], self::valoresPorDefecto());
    }

    /**
     * @return array<string, string|null>
     */
    public static function valoresPorDefecto(): array
    {
        return [
            'color_principal' => '#171717',
            'color_hover_principal' => '#292929',
            'color_texto_boton_principal' => '#fafafa',
            'fondo_general' => '#ffffff',
            'fondo_tarjetas' => '#ffffff',
            'fondo_sidebar' => '#fafafa',
            'color_secundario' => null,
        ];
    }

    /**
     * Custom properties para el tema claro: color principal + fondos.
     *
     * @return array<string, string>
     */
    public function variablesClaro(): array
    {
        $variables = [
            ...$this->variablesMarca(),
            '--background' => $this->fondo_general,
            '--card' => $this->fondo_tarjetas,
            '--popover' => $this->fondo_tarjetas,
            '--sidebar' => $this->fondo_sidebar,
            '--sidebar-background' => $this->fondo_sidebar,
        ];

        if ($this->color_secundario) {
            $variables['--secondary'] = $this->color_secundario;
            $variables['--secondary-foreground'] = ColorContraste::contrastante($this->color_secundario);
            $variables['--accent'] = $this->color_secundario;
            $variables['--accent-foreground'] = ColorContraste::contrastante($this->color_secundario);
        }

        return $variables;
    }

    /**
     * Custom properties para el tema oscuro: sólo la familia del color
     * principal (identidad de marca), nunca los fondos — el modo oscuro
     * conserva sus propios fondos para no perder contraste ni "romperlo".
     *
     * @return array<string, string>
     */
    public function variablesOscuro(): array
    {
        return $this->variablesMarca();
    }

    /**
     * Identidad de marca compartida por ambos temas: color principal, hover
     * y su texto. Los fondos (claro) y `--sidebar-accent` (hover suave del
     * sidebar) se dejan fuera a propósito para no confundir "activo" con
     * "hover" en la navegación.
     *
     * @return array<string, string>
     */
    private function variablesMarca(): array
    {
        return [
            '--primary' => $this->color_principal,
            '--primary-hover' => $this->color_hover_principal,
            '--primary-foreground' => $this->color_texto_boton_principal,
            '--sidebar-primary' => $this->color_principal,
            '--sidebar-primary-foreground' => $this->color_texto_boton_principal,
            '--ring' => $this->color_principal,
        ];
    }
}
