<?php

use App\Enums\PerfilTecnicoUnidad;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\CategoriaActivo;
use App\Models\CategoriaActivoPerfilTecnico;
use App\Models\Empresa;
use App\Models\TipoActivo;
use App\Soporte\ResolverPerfilTecnicoUnidad;

/**
 * El perfil técnico se resuelve SÓLO por la relación 1:1
 * `CategoriaActivo::perfilTecnico` (`categoria_activo_perfil_tecnico`). Nunca
 * por `codigo` ni por nombre. `codigo` operativo (de categoría o de tipo) es
 * un concepto distinto y NO se toca.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->resolver = app(ResolverPerfilTecnicoUnidad::class);

    $this->conPerfil = function (PerfilTecnicoUnidad $perfil): Activo {
        $cat = CategoriaActivo::factory()->create();
        CategoriaActivoPerfilTecnico::query()->create([
            'categoria_activo_id' => $cat->id, 'perfil' => $perfil->value,
        ]);

        return Activo::factory()->for($this->empresa)->create(['categoria_id' => $cat->id]);
    };
});

it('resuelve el perfil desde la fila lateral de la categoría', function () {
    expect($this->resolver->paraActivo(($this->conPerfil)(PerfilTecnicoUnidad::Celular)))->toBe(PerfilTecnicoUnidad::Celular)
        ->and($this->resolver->paraActivo(($this->conPerfil)(PerfilTecnicoUnidad::Computadora)))->toBe(PerfilTecnicoUnidad::Computadora)
        ->and($this->resolver->paraActivo(($this->conPerfil)(PerfilTecnicoUnidad::Tablet)))->toBe(PerfilTecnicoUnidad::Tablet);
});

it('NO infiere el perfil por el nombre ni por el codigo de la categoría', function () {
    $cat = CategoriaActivo::factory()->create(['nombre' => 'Celular', 'codigo' => 'CELULAR']);
    $activo = Activo::factory()->for($this->empresa)->create(['categoria_id' => $cat->id]);

    expect($this->resolver->paraActivo($activo))->toBeNull();
});

it('NO usa el codigo del tipo como fallback', function () {
    $tipo = TipoActivo::factory()->create(['codigo' => 'CELULAR']);
    $cat = CategoriaActivo::factory()->create(['tipo_activo_id' => $tipo->id]);
    $activo = Activo::factory()->for($this->empresa)->create([
        'tipo_activo_id' => $tipo->id, 'categoria_id' => $cat->id,
    ]);

    expect($this->resolver->paraActivo($activo))->toBeNull();
});

it('una categoría sin fila lateral no tiene perfil', function () {
    $activo = Activo::factory()->for($this->empresa)->create([
        'categoria_id' => CategoriaActivo::factory()->create()->id,
    ]);

    expect($this->resolver->paraActivo($activo))->toBeNull();
});

it('asignar el perfil desde el catálogo NO toca categorias_activo.codigo ni tipos_activo.codigo', function () {
    $tipo = TipoActivo::query()->create(['nombre' => 'Dispositivo móvil', 'codigo' => 'TAC-0009', 'activo' => true]);
    $cat = CategoriaActivo::factory()->create(['nombre' => 'Teléfono', 'codigo' => 'CAT-OP-01', 'tipo_activo_id' => $tipo->id]);
    $admin = usuarioCon(RolSistema::Administrador->value);

    $this->actingAs($admin)
        ->put("/categorias-activo/{$cat->id}", [
            'nombre' => 'Teléfono', 'tipo_activo_id' => $tipo->id, 'perfil_tecnico' => 'celular',
        ])
        ->assertSessionHasNoErrors();

    expect($cat->fresh()->codigo)->toBe('CAT-OP-01')      // codigo operativo intacto
        ->and($tipo->fresh()->codigo)->toBe('TAC-0009')   // codigo del tipo intacto
        ->and($cat->fresh()->perfilTecnico?->perfil)->toBe(PerfilTecnicoUnidad::Celular);

    // "Sin perfil técnico" elimina la fila lateral, sigue sin tocar codigo.
    $this->actingAs($admin)
        ->put("/categorias-activo/{$cat->id}", [
            'nombre' => 'Teléfono', 'tipo_activo_id' => $tipo->id, 'perfil_tecnico' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($cat->fresh()->codigo)->toBe('CAT-OP-01')
        ->and(CategoriaActivoPerfilTecnico::query()->where('categoria_activo_id', $cat->id)->exists())->toBeFalse();
});
