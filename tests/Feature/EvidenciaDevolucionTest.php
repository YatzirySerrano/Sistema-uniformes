<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoDevolucion;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\DetalleDevolucion;
use App\Models\Devolucion;
use App\Models\Evidencia;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Evidencia fotográfica OPCIONAL por renglón devuelto: se guarda en disco
 * privado ligada al DetalleDevolucion; el endpoint de consulta exige acceso
 * a la empresa de la devolución. El alta ahora es el wizard (register+confirm
 * en una sola petición atómica con ambas firmas).
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
        [],
        [],
    );
    $this->detalle = $this->entrega->detalles->first();

    $this->payload = fn (array $extra = []): array => [
        'entrega_uniforme_id' => $this->entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        ...$extra,
    ];
});

it('adjunta la evidencia de un renglón devuelto al DetalleDevolucion, en disco privado', function () {
    $this->actingAs($this->admin)
        ->post('/devoluciones', ($this->payload)([
            'activos' => [[
                'detalle_entrega_id' => $this->detalle->id,
                'cantidad' => 3,
                'condicion' => 'reutilizable',
                'evidencia' => UploadedFile::fake()->image('condicion.jpg'),
                'evidencia_origen' => 'archivo',
            ]],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Devolucion::first()->estado)->toBe(EstadoDevolucion::Confirmada);

    $evidencia = Evidencia::firstOrFail();
    expect($evidencia->evidenciable_type)->toBe(DetalleDevolucion::class);
    expect($evidencia->ruta)->toStartWith('evidencias/devoluciones/');
    Storage::disk('local')->assertExists($evidencia->ruta);

    $this->actingAs($this->admin)
        ->get(route('devoluciones.evidencias.ver', $evidencia))
        ->assertOk();

    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $this->actingAs($supervisorAjeno)
        ->get(route('devoluciones.evidencias.ver', $evidencia))
        ->assertForbidden();
});

it('una devolución sin evidencia sigue registrándose y confirmándose igual', function () {
    $this->actingAs($this->admin)
        ->post('/devoluciones', ($this->payload)([
            'activos' => [[
                'detalle_entrega_id' => $this->detalle->id,
                'cantidad' => 2,
                'condicion' => 'reutilizable',
            ]],
        ]))
        ->assertSessionHasNoErrors();

    expect(Evidencia::count())->toBe(0);
    expect(Devolucion::first()->estado)->toBe(EstadoDevolucion::Confirmada);
});
