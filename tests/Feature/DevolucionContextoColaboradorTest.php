<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\Devolucion;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Storage;

/**
 * Flujo "Transferir a otra empresa" → "Ir a Devoluciones": el módulo de Nueva
 * devolución debe llegar contextualizado con el colaborador (IDs reales, no
 * folios), sólo con SUS pendientes, agrupados por entrega de origen, y al
 * terminar una devolución debe regresar automáticamente a ese contexto hasta
 * agotar los pendientes.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    Storage::fake('local');

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
});

it('el contexto de un colaborador sólo trae SUS pendientes reales, agrupados por entrega de origen, con IDs (no folios)', function () {
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    // `ServicioCustodiaColaborador` sólo cuenta custodia de entregas ya
    // FIRMADAS (una `PendienteFirma` aún no transfirió custodia real).
    $entregaA = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4]], [], [],
    );
    $entregaA->update(['estado' => EstadoEntrega::Firmada]);

    // Pendiente de OTRO colaborador: nunca debe filtrarse al contexto de A.
    $entregaB = app(CrearEntregaUniforme::class)->ejecutar(
        $colaboradorB->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]], [], [],
    );
    $entregaB->update(['estado' => EstadoEntrega::Firmada]);

    $respuesta = $this->actingAs($this->admin)
        ->get("/devoluciones/crear?colaborador_id={$this->datos['colaboradorA']->id}");

    $contexto = $respuesta->viewData('page')['props']['colaboradorContexto'];

    expect($contexto['id'])->toBe($this->datos['colaboradorA']->id)
        ->and($contexto['pendientes'])->toHaveCount(1)
        ->and($contexto['pendientes'][0]['entrega_id'])->toBe($entregaA->id)
        ->and($contexto['pendientes'][0]['detalle_entrega_id'])->toBe($entregaA->detalles->first()->id)
        ->and($contexto['pendientes'][0]['cantidad'])->toBe(4);
});

it('al confirmar una devolución con pendientes restantes, redirige de vuelta al contexto del mismo colaborador sin pasar por su perfil', function () {
    $entrega1 = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]], [], [],
    );
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();
    app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );

    $respuesta = $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega1->id,
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $entrega1->detalles->first()->id,
            'cantidad' => 2,
            'condicion' => 'reutilizable',
        ]],
    ])->assertSessionHasNoErrors();

    $respuesta->assertRedirect(route('devoluciones.create', ['colaborador_id' => $this->datos['colaboradorA']->id]));
    expect(session('toast')['message'])->toContain('Quedan pendientes');
});

it('al confirmar el ÚLTIMO pendiente, redirige al perfil del colaborador en vez de al contexto de devoluciones', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]], [], [],
    );

    $respuesta = $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $entrega->detalles->first()->id,
            'cantidad' => 2,
            'condicion' => 'reutilizable',
        ]],
    ])->assertSessionHasNoErrors();

    $respuesta->assertRedirect(route('colaboradores.show', $this->datos['colaboradorA']->id));
    expect(session('toast')['message'])->toContain('Ya no quedan pendientes');
});

it('un colaborador_id manipulado que no es el dueño real de la entrega se ignora para la redirección (nunca se confía el ID del cliente)', function () {
    $otroColaborador = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create();

    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]], [], [],
    );

    $respuesta = $this->actingAs($this->admin)->post('/devoluciones', [
        'entrega_uniforme_id' => $entrega->id,
        'colaborador_id' => $otroColaborador->id, // no es el dueño real de $entrega
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $entrega->detalles->first()->id,
            'cantidad' => 2,
            'condicion' => 'reutilizable',
        ]],
    ])->assertSessionHasNoErrors();

    $devolucion = Devolucion::where('entrega_uniforme_id', $entrega->id)->firstOrFail();
    $respuesta->assertRedirect("/devoluciones/{$devolucion->id}");
});
