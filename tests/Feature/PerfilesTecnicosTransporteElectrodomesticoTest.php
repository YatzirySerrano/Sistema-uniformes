<?php

use App\Enums\PerfilTecnicoUnidad;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\CategoriaActivo;
use App\Models\CategoriaActivoPerfilTecnico;
use App\Models\Empresa;
use App\Models\UnidadActivo;
use Illuminate\Support\Facades\Storage;

/**
 * Perfiles técnicos Transporte y Electrodoméstico (misma arquitectura: perfil
 * de la categoría + especificaciones por unidad) y la regla central de que
 * todo perfil técnico exige seguimiento individual.
 */
beforeEach(function () {
    Storage::fake('local');
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->categoriaCon = function (?PerfilTecnicoUnidad $perfil): CategoriaActivo {
        $categoria = CategoriaActivo::factory()->create();
        if ($perfil !== null) {
            CategoriaActivoPerfilTecnico::query()->create(['categoria_activo_id' => $categoria->id, 'perfil' => $perfil]);
        }

        return $categoria;
    };

    $this->alta = fn (CategoriaActivo $categoria, string $control, array $especificaciones = [], string $nombre = 'Activo') => $this->actingAs($this->admin)->post('/activos', [
        'empresa_id' => $this->empresa->id,
        'nombre' => $nombre,
        'tipo_control' => $control,
        'categoria_id' => $categoria->id,
        'almacen_id' => $this->almacen->id,
        'cantidad_inicial' => $control === 'individual' ? count($especificaciones) : 0,
        'especificaciones' => $especificaciones,
    ]);

    $this->vehiculo = fn (array $extra = []): array => [
        'clase_vehiculo' => 'camioneta', 'marca' => 'Nissan', 'modelo' => 'NP300', 'anio' => '2022',
        'color' => 'Blanco', 'placas' => 'mor-123-a', 'numero_serie' => '3N6AD33A0MK812345', ...$extra,
    ];
});

it('Transporte guarda todos sus datos por unidad y normaliza las placas', function () {
    ($this->alta)(($this->categoriaCon)(PerfilTecnicoUnidad::Transporte), 'individual', [($this->vehiculo)()], 'Camioneta reparto')
        ->assertSessionHasNoErrors();

    $esp = UnidadActivo::query()->sole()->especificacion;
    expect($esp->clase_vehiculo)->toBe('camioneta')
        ->and($esp->marca)->toBe('Nissan')
        ->and($esp->anio)->toBe(2022)
        ->and($esp->color)->toBe('Blanco')
        ->and($esp->placas)->toBe('MOR-123-A')
        ->and($esp->numero_serie)->toBe('3N6AD33A0MK812345');
});

it('Transporte exige clase, marca, modelo y año válido', function () {
    ($this->alta)(($this->categoriaCon)(PerfilTecnicoUnidad::Transporte), 'individual', [($this->vehiculo)(['clase_vehiculo' => '', 'marca' => '', 'modelo' => '', 'anio' => ''])])
        ->assertSessionHasErrors([
            'especificaciones.0.clase_vehiculo' => 'Este dato es obligatorio para Transporte.',
            'especificaciones.0.marca' => 'Este dato es obligatorio para Transporte.',
            'especificaciones.0.modelo' => 'Este dato es obligatorio para Transporte.',
            'especificaciones.0.anio' => 'Este dato es obligatorio para Transporte.',
        ]);

    ($this->alta)(($this->categoriaCon)(PerfilTecnicoUnidad::Transporte), 'individual', [($this->vehiculo)(['anio' => '1850'])])
        ->assertSessionHasErrors(['especificaciones.0.anio' => 'El año debe ser 1900 o posterior.']);

    expect(UnidadActivo::count())->toBe(0);
});

it('un vehículo sin placas se acepta si trae NIV / serie, pero no sin ninguno de los dos', function () {
    $categoria = ($this->categoriaCon)(PerfilTecnicoUnidad::Transporte);

    ($this->alta)($categoria, 'individual', [($this->vehiculo)(['placas' => ''])], 'Auto nuevo')->assertSessionHasNoErrors();

    ($this->alta)($categoria, 'individual', [($this->vehiculo)(['placas' => '', 'numero_serie' => ''])], 'Auto sin identificar')
        ->assertSessionHasErrors(['especificaciones.0.placas' => 'Captura al menos uno: Placas o NIV / VIN / número de serie.']);

    expect(UnidadActivo::count())->toBe(1);
});

it('Electrodoméstico guarda marca, modelo, color y serie; sólo la marca es obligatoria', function () {
    $categoria = ($this->categoriaCon)(PerfilTecnicoUnidad::Electrodomestico);

    ($this->alta)($categoria, 'individual', [['marca' => 'LG', 'modelo' => 'MS1142', 'color' => 'Negro', 'numero_serie' => 'SN-998']], 'Microondas')
        ->assertSessionHasNoErrors();
    ($this->alta)($categoria, 'individual', [['marca' => 'Mabe']], 'Refrigerador')->assertSessionHasNoErrors();
    ($this->alta)($categoria, 'individual', [['modelo' => 'X']], 'Cafetera')
        ->assertSessionHasErrors(['especificaciones.0.marca' => 'Este dato es obligatorio para Electrodoméstico.']);

    $microondas = UnidadActivo::query()->whereHas('activo', fn ($q) => $q->where('nombre', 'Microondas'))->sole()->especificacion;
    expect($microondas->only(['marca', 'modelo', 'color', 'numero_serie']))->toBe(['marca' => 'LG', 'modelo' => 'MS1142', 'color' => 'Negro', 'numero_serie' => 'SN-998']);
});

it('editar los datos de un vehículo aplica las mismas reglas del perfil', function () {
    ($this->alta)(($this->categoriaCon)(PerfilTecnicoUnidad::Transporte), 'individual', [($this->vehiculo)()], 'Camioneta')->assertSessionHasNoErrors();
    $unidad = UnidadActivo::query()->sole();

    $this->actingAs($this->admin)
        ->patch("/activos/unidades/{$unidad->public_token}/especificacion", ($this->vehiculo)(['placas' => '', 'numero_serie' => '']))
        ->assertSessionHasErrors('placas');

    $this->actingAs($this->admin)
        ->patch("/activos/unidades/{$unidad->public_token}/especificacion", ($this->vehiculo)(['color' => 'Rojo']))
        ->assertSessionHasNoErrors();

    expect($unidad->especificacion()->value('color'))->toBe('Rojo');
});

it('todo perfil técnico obliga a seguimiento individual, aun manipulando el request', function (PerfilTecnicoUnidad $perfil) {
    ($this->alta)(($this->categoriaCon)($perfil), 'cantidad', [], 'Intento por cantidad')
        ->assertSessionHasErrors(['tipo_control' => "Los activos con perfil {$perfil->etiqueta()} requieren seguimiento individual para conservar su trazabilidad."]);

    expect(Activo::query()->where('nombre', 'Intento por cantidad')->exists())->toBeFalse();
})->with([
    'transporte' => PerfilTecnicoUnidad::Transporte,
    'electrodoméstico' => PerfilTecnicoUnidad::Electrodomestico,
    'celular' => PerfilTecnicoUnidad::Celular,
    'tablet' => PerfilTecnicoUnidad::Tablet,
    'computadora / laptop' => PerfilTecnicoUnidad::Computadora,
]);

it('una categoría sin perfil técnico sigue permitiendo control por cantidad', function () {
    ($this->alta)(($this->categoriaCon)(null), 'cantidad', [], 'Playera')->assertSessionHasNoErrors();

    expect(Activo::query()->where('nombre', 'Playera')->value('tipo_control')->value)->toBe('cantidad');
});

it('no se asigna un perfil técnico a una categoría que ya tiene activos por cantidad', function () {
    $categoria = ($this->categoriaCon)(null);
    Activo::factory()->for($this->empresa)->create(['categoria_id' => $categoria->id, 'tipo_control' => 'cantidad']);

    $this->actingAs($this->admin)
        ->put("/categorias-activo/{$categoria->id}", ['nombre' => $categoria->nombre, 'perfil_tecnico' => 'transporte'])
        ->assertSessionHasErrors('perfil_tecnico');

    expect($categoria->perfilTecnico()->exists())->toBeFalse();
});
