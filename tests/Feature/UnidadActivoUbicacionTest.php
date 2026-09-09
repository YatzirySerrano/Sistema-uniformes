<?php

use App\Acciones\RegistrarUnidadesActivo;
use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\UnidadActivo;
use App\Servicios\ServicioUnidadesActivo;

/**
 * Ubicación operativa de una `UnidadActivo` (`UnidadActivo::ubicacionOperativa()`):
 * en almacén → el almacén; asignada → el servicio VIGENTE del colaborador
 * (nunca un dato propio de la unidad, que no existe a propósito).
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->sucursal = Sucursal::factory()->for($this->empresa)->create();
    $this->almacen = Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Almacén Central']);
    $this->activo = Activo::factory()->for($this->empresa)->seguimientoIndividual()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->contrato = Contrato::factory()->for($this->empresa)->create(['nombre' => 'Laboratorios Polab']);
    $this->servicio = Servicio::factory()->for($this->contrato)->for($this->sucursal)->create(['nombre' => 'Polab Cuernavaca']);
    $this->colaborador = Colaborador::factory()->for($this->empresa)->for($this->sucursal)->create();

    $this->unidad = app(RegistrarUnidadesActivo::class)->ejecutar(
        empresa: $this->empresa,
        activo: $this->activo,
        almacen: $this->almacen,
        cantidad: 1,
        motivo: 'Alta inicial',
        realizadoPor: $this->admin->id,
    )->first();
});

it('CASO 3 previo / unidad en almacén: la ubicación operativa es el almacén', function () {
    $ubicacion = $this->unidad->fresh()->ubicacionOperativa();

    expect($ubicacion['tipo'])->toBe('almacen');
    expect($ubicacion['almacen']['nombre'])->toBe('Almacén Central');
});

it('CASO 1: unidad asignada a un colaborador con servicio muestra contrato y servicio', function () {
    $this->colaborador->update(['servicio_actual_id' => $this->servicio->id]);

    app(ServicioUnidadesActivo::class)->asignar(
        $this->unidad,
        $this->colaborador->id,
        $this->admin->id,
        EntregaUniforme::class,
        1,
        'Entrega de prueba',
    );

    $ubicacion = $this->unidad->fresh()->ubicacionOperativa();

    expect($ubicacion['tipo'])->toBe('servicio');
    expect($ubicacion['contrato'])->toBe('Laboratorios Polab');
    expect($ubicacion['servicio'])->toBe('Polab Cuernavaca');
});

it('unidad asignada a un colaborador SIN servicio muestra "sin_servicio", nunca inventa uno', function () {
    app(ServicioUnidadesActivo::class)->asignar(
        $this->unidad,
        $this->colaborador->id,
        $this->admin->id,
        EntregaUniforme::class,
        1,
        'Entrega de prueba',
    );

    $ubicacion = $this->unidad->fresh()->ubicacionOperativa();

    expect($ubicacion['tipo'])->toBe('sin_servicio');
});

it('CASO 2: cambiar el servicio del colaborador cambia la ubicación mostrada de la unidad, sin tocar la unidad', function () {
    $this->colaborador->update(['servicio_actual_id' => $this->servicio->id]);
    app(ServicioUnidadesActivo::class)->asignar(
        $this->unidad, $this->colaborador->id, $this->admin->id, EntregaUniforme::class, 1,
    );

    $otroServicio = Servicio::factory()->for($this->contrato)->for($this->sucursal)->create(['nombre' => 'Polab Jiutepec']);
    $this->colaborador->update(['servicio_actual_id' => $otroServicio->id]);

    $ubicacion = $this->unidad->fresh()->ubicacionOperativa();

    expect($ubicacion['servicio'])->toBe('Polab Jiutepec');
    // La unidad en sí no guarda ningún dato de servicio propio.
    expect($this->unidad->fresh()->getAttributes())->not->toHaveKey('servicio_id');
});

it('CASO 3: tras la devolución, la unidad vuelve a mostrar el almacén y no el servicio del colaborador anterior', function () {
    $this->colaborador->update(['servicio_actual_id' => $this->servicio->id]);
    app(ServicioUnidadesActivo::class)->asignar(
        $this->unidad, $this->colaborador->id, $this->admin->id, EntregaUniforme::class, 1,
    );
    expect($this->unidad->fresh()->ubicacionOperativa()['tipo'])->toBe('servicio');

    app(ServicioUnidadesActivo::class)->devolver(
        $this->unidad->fresh(),
        CondicionUnidadActivo::Funcionando,
        $this->almacen->id,
        $this->admin->id,
        EntregaUniforme::class,
        1,
        'Devolución de prueba',
    );

    $ubicacion = $this->unidad->fresh()->ubicacionOperativa();

    expect($ubicacion['tipo'])->toBe('almacen');
    expect($ubicacion['almacen']['nombre'])->toBe('Almacén Central');

    // El colaborador conserva su servicio vigente, pero la unidad ya no lo
    // refleja porque dejó de estar asignada a él.
    expect($this->colaborador->fresh()->servicio_actual_id)->toBe($this->servicio->id);
});
