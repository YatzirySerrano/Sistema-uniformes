<?php

use App\Enums\EstadoDevolucion;
use App\Enums\RolSistema;
use App\Exports\ListadoExport;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Filtros reales de Devoluciones (Fase QA): folio/colaborador, sucursal,
 * estado y rango de fechas, combinables, y respetando el alcance de empresa
 * del usuario (una sucursal ajena nunca filtra nada fuera de su alcance).
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->sucursal1 = Sucursal::factory()->for($this->empresa)->create(['nombre' => 'Sucursal Norte']);
    $this->sucursal2 = Sucursal::factory()->for($this->empresa)->create(['nombre' => 'Sucursal Sur']);
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);

    $this->colaboradorA = Colaborador::factory()->for($this->empresa)->for($this->sucursal1)
        ->create(['nombre_completo' => 'Ana Pérez', 'numero_empleado' => 'EMP-100']);
    $this->colaboradorB = Colaborador::factory()->for($this->empresa)->for($this->sucursal2)
        ->create(['nombre_completo' => 'Beto Ruiz', 'numero_empleado' => 'EMP-200']);
});

it('filtra por folio', function () {
    $dev = Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['folio' => 'DEV-2026-000123']);
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)->create();

    $this->actingAs($this->admin)
        ->get('/devoluciones?buscar=000123')
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.id', $dev->id));
});

it('filtra por número de empleado del colaborador', function () {
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)->create();
    Devolucion::factory()->for($this->empresa)->for($this->sucursal2)->for($this->colaboradorB)->create();

    $this->actingAs($this->admin)
        ->get('/devoluciones?buscar=EMP-200')
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.colaborador', 'Beto Ruiz'));
});

it('filtra por sucursal', function () {
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)->create();
    Devolucion::factory()->for($this->empresa)->for($this->sucursal2)->for($this->colaboradorB)->create();

    $this->actingAs($this->admin)
        ->get("/devoluciones?sucursal_id={$this->sucursal2->id}")
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.sucursal', 'Sucursal Sur'));
});

it('filtra por estado', function () {
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['estado' => EstadoDevolucion::PendienteFirma]);
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['estado' => EstadoDevolucion::Confirmada]);

    $this->actingAs($this->admin)
        ->get('/devoluciones?estado=confirmada')
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.estado', 'confirmada'));
});

it('filtra por rango de fechas desde/hasta', function () {
    $dentro = Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['fecha' => '2026-03-15']);
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['fecha' => '2026-01-01']);
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['fecha' => '2026-06-01']);

    $this->actingAs($this->admin)
        ->get('/devoluciones?desde=2026-03-01&hasta=2026-03-31')
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.id', $dentro->id));
});

it('combina varios filtros a la vez', function () {
    $objetivo = Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['estado' => EstadoDevolucion::Confirmada, 'fecha' => '2026-03-15']);
    // Mismo colaborador/fecha pero otro estado: no debe aparecer.
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['estado' => EstadoDevolucion::PendienteFirma, 'fecha' => '2026-03-15']);

    $this->actingAs($this->admin)
        ->get("/devoluciones?sucursal_id={$this->sucursal1->id}&estado=confirmada&desde=2026-03-01&hasta=2026-03-31")
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 1)->where('devoluciones.data.0.id', $objetivo->id));
});

it('una sucursal de otra empresa (fuera de alcance) no filtra nada ajeno', function () {
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)->create();

    $ajena = Empresa::factory()->create();
    $sucursalAjena = Sucursal::factory()->for($ajena)->create();

    $this->actingAs($this->admin)
        ->get("/devoluciones?sucursal_id={$sucursalAjena->id}")
        ->assertInertia(fn ($page) => $page->has('devoluciones.data', 0));
});

it('el export respeta el filtro de estado aplicado', function () {
    Excel::fake();

    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['folio' => 'DEV-2026-000001', 'estado' => EstadoDevolucion::Confirmada]);
    Devolucion::factory()->for($this->empresa)->for($this->sucursal1)->for($this->colaboradorA)
        ->create(['folio' => 'DEV-2026-000002', 'estado' => EstadoDevolucion::PendienteFirma]);

    $this->actingAs($this->admin)->get('/devoluciones/exportar?estado=confirmada')->assertOk();

    Excel::assertDownloaded(
        Str::slug('Devoluciones').'-todas-las-empresas-'.now()->toDateString().'.xlsx',
        function (ListadoExport $export): bool {
            $folios = array_column($export->array(), 0);
            expect($folios)->toContain('DEV-2026-000001')->not->toContain('DEV-2026-000002');

            return true;
        },
    );
});
