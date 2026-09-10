<?php

use App\Acciones\CrearEntregaUniforme;
use App\Acciones\RegistrarDevolucion;
use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\AcuseDevolucion;
use App\Models\Devolucion;
use App\Models\Evidencia;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Devoluciones: listado con estado + acuse, página de Detalle (anti-IDOR) y
 * el wizard que registra y confirma en UNA sola operación atómica (ambas
 * firmas + aceptación obligatorias en el backend).
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

    $this->payloadWizard = fn (array $extra = []): array => [
        'entrega_uniforme_id' => $this->entrega->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'activos' => [[
            'detalle_entrega_id' => $this->detalle->id,
            'cantidad' => 4,
            'condicion' => 'reutilizable',
        ]],
        ...$extra,
    ];
});

it('el listado expone estado, folio de la entrega y si tiene acuse', function () {
    app(RegistrarDevolucion::class)->ejecutar(
        $this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
        [], $this->admin->id,
    );

    $this->actingAs($this->admin)
        ->get('/devoluciones')
        ->assertInertia(fn ($page) => $page
            ->component('Devoluciones/Index')
            ->where('devoluciones.data.0.estado', EstadoDevolucion::PendienteFirma->value)
            ->where('devoluciones.data.0.entrega_folio', $this->entrega->folio)
            ->where('devoluciones.data.0.tiene_acuse', false),
        );
});

it('la página de Detalle muestra la entrega de origen, las evidencias del renglón y el acuse', function () {
    $acuse = app(RegistrarDevolucionFirmada::class)->ejecutar(
        $this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [[
            'detalle_entrega_id' => $this->detalle->id, 'cantidad' => 3, 'condicion' => 'reutilizable',
        ]],
        [], $this->admin->id, 'Fin de contrato', null,
        firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    $this->actingAs($this->admin)
        ->get("/devoluciones/{$acuse->devolucion_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Devoluciones/Detalle')
            ->where('devolucion.entrega_folio', $this->entrega->folio)
            ->where('devolucion.estado', EstadoDevolucion::Confirmada->value)
            ->where('devolucion.motivo', 'Fin de contrato')
            ->where('acuse.folio', $acuse->folio)
            ->where('acuse.tiene_pdf', true),
        );
});

it('un usuario de otra empresa no puede abrir el detalle de una devolución ajena (anti-IDOR)', function () {
    $devolucion = app(RegistrarDevolucion::class)->ejecutar(
        $this->entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $this->detalle->id, 'cantidad' => 2, 'condicion' => 'reutilizable']],
        [], $this->admin->id,
    );

    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)
        ->get("/devoluciones/{$devolucion->id}")
        ->assertForbidden();
});

it('el wizard exige firma, firma_operador y aceptación aunque se manipule el frontend', function () {
    $this->actingAs($this->admin)
        ->from('/devoluciones/crear')
        ->post('/devoluciones', ($this->payloadWizard)(['firma' => '']))
        ->assertSessionHasErrors('firma');

    $this->actingAs($this->admin)
        ->from('/devoluciones/crear')
        ->post('/devoluciones', ($this->payloadWizard)(['firma_operador' => '']))
        ->assertSessionHasErrors('firma_operador');

    $this->actingAs($this->admin)
        ->from('/devoluciones/crear')
        ->post('/devoluciones', ($this->payloadWizard)(['aceptacion' => false]))
        ->assertSessionHasErrors('aceptacion');

    expect(Devolucion::count())->toBe(0)
        ->and(SaldoInventario::first()->cantidad)->toBe(10);
});

it('el wizard crea la devolución CONFIRMADA con acuse y reingreso en una sola operación y redirige al detalle', function () {
    $respuesta = $this->actingAs($this->admin)
        ->post('/devoluciones', ($this->payloadWizard)())
        ->assertSessionHasNoErrors();

    $devolucion = Devolucion::firstOrFail();
    expect($devolucion->estado)->toBe(EstadoDevolucion::Confirmada)
        ->and(AcuseDevolucion::where('devolucion_id', $devolucion->id)->exists())->toBeTrue()
        ->and(SaldoInventario::first()->cantidad)->toBe(14); // 10 + 4 reutilizables

    $respuesta->assertRedirect("/devoluciones/{$devolucion->id}");
    expect(session('toast')['message'])->toContain($devolucion->folio);
});

it('si la confirmación final falla, el wizard revierte TODO: sin devolución, sin acuse, sin reingreso, sin archivos huérfanos', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($activoIndividual)->for($this->datos['almacenA'])->create();

    $entregaUnidad = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id, $this->datos['almacenA']->id, $this->admin->id, now()->toDateString(),
        [], [['unidad_activo_id' => $unidad->id]], [],
    );
    $detalleUnidad = $entregaUnidad->detalles->first();

    // La unidad deja de estar asignada por otra vía antes de que llegue el POST.
    $unidad->update(['estado' => EstadoUnidadActivo::Baja]);

    $this->actingAs($this->admin)
        ->post('/devoluciones', [
            'entrega_uniforme_id' => $entregaUnidad->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'fecha' => now()->toDateString(),
            'firma' => firmaDemoBase64(),
            'firma_operador' => firmaDemoBase64(),
            'aceptacion' => true,
            'unidades' => [[
                'detalle_entrega_id' => $detalleUnidad->id,
                'condicion' => 'funcionando',
                'evidencia' => UploadedFile::fake()->image('u.jpg'),
                'evidencia_origen' => 'archivo',
            ]],
        ])
        ->assertSessionHasErrors('negocio');

    expect(Devolucion::count())->toBe(0)
        ->and(AcuseDevolucion::count())->toBe(0)
        ->and(Evidencia::count())->toBe(0)
        ->and(SaldoInventario::where('activo_id', $this->datos['activoA']->id)->first()->cantidad)->toBe(10);

    // Ni evidencia ni firma quedaron en disco.
    expect(Storage::disk('local')->allFiles("evidencias/devoluciones/{$this->datos['empresaA']->id}"))->toBe([]);
    expect(Storage::disk('local')->allFiles("firmas/{$this->datos['empresaA']->id}"))->toBe([]);
});

it('el PDF de devolución con evidencia se genera, y sigue generándose si el archivo físico se borra a mano', function () {
    $this->actingAs($this->admin)
        ->post('/devoluciones', ($this->payloadWizard)([
            'activos' => [[
                'detalle_entrega_id' => $this->detalle->id,
                'cantidad' => 3,
                'condicion' => 'reutilizable',
                'evidencia' => UploadedFile::fake()->image('cond.jpg'),
                'evidencia_origen' => 'archivo',
            ]],
        ]))
        ->assertSessionHasNoErrors();

    $acuse = AcuseDevolucion::firstOrFail();

    $this->actingAs($this->admin)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // El archivo de evidencia desaparece; el comprobante debe regenerarse igual.
    Storage::disk('local')->delete(Evidencia::firstOrFail()->ruta);

    $this->actingAs($this->admin)
        ->post("/acuses-devolucion/{$acuse->id}/regenerar-pdf")
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->get("/acuses-devolucion/{$acuse->id}/pdf")
        ->assertOk();
});
