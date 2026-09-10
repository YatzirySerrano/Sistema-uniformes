<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\Evidencia;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evidencia fotográfica OPCIONAL por renglón de entrega. Se guarda en disco
 * privado, ligada al DetalleEntrega. Una entrega sin evidencia sigue
 * funcionando exactamente igual.
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
        cantidad: 50,
    ));

    $this->payload = fn (array $extra = []): array => [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        ...$extra,
    ];
});

it('registra una entrega SIN evidencia igual que siempre', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ]))
        ->assertSessionHasNoErrors();

    expect(Evidencia::count())->toBe(0);
    expect(EntregaUniforme::count())->toBe(1);
});

it('adjunta la evidencia de un renglón al DetalleEntrega correcto, en disco privado', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [[
                'activo_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id,
                'cantidad' => 3,
                'evidencia' => UploadedFile::fake()->image('estado.jpg', 800, 600),
                'evidencia_origen' => 'camara',
            ]],
        ]))
        ->assertSessionHasNoErrors();

    $evidencia = Evidencia::firstOrFail();
    expect($evidencia->evidenciable_type)->toBe(DetalleEntrega::class);
    expect($evidencia->origen)->toBe('camara');
    expect($evidencia->mime)->toBe('image/jpeg');
    $detalle = DetalleEntrega::findOrFail($evidencia->evidenciable_id);
    expect($detalle->cantidad)->toBe(3);
    Storage::disk('local')->assertExists($evidencia->ruta);
    expect($evidencia->ruta)->toStartWith('evidencias/entregas/');
});

it('un renglón con evidencia NO se consolida con otro del mismo activo+variante', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2],
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3, 'evidencia' => UploadedFile::fake()->image('e.jpg')],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $entrega = EntregaUniforme::firstOrFail();
    expect($entrega->detalles()->count())->toBe(2);
    expect(Evidencia::count())->toBe(1);
});

it('rechaza un archivo de evidencia que no es imagen', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [[
                'activo_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id,
                'cantidad' => 1,
                'evidencia' => UploadedFile::fake()->create('doc.txt', 20, 'text/plain'),
            ]],
        ]))
        ->assertSessionHasErrors('activos.0.evidencia');

    expect(EntregaUniforme::count())->toBe(0);
});

it('si el alta falla no deja archivos de evidencia huérfanos', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [[
                'activo_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id,
                'cantidad' => 999, // sin stock suficiente → revienta dentro de la acción
                'evidencia' => UploadedFile::fake()->image('e.jpg'),
            ]],
        ]));

    expect(EntregaUniforme::count())->toBe(0);
    expect(Evidencia::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('evidencias'))->toBe([]);
});

it('el endpoint de evidencia exige acceso a la empresa de la entrega (anti-IDOR)', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [[
                'activo_id' => $this->datos['activoA']->id,
                'talla_id' => $this->datos['tallaA']->id,
                'cantidad' => 1,
                'evidencia' => UploadedFile::fake()->image('e.jpg'),
            ]],
        ]))
        ->assertSessionHasNoErrors();

    $evidencia = Evidencia::firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('entregas.evidencias.ver', $evidencia))
        ->assertOk();

    // Administrador tiene alcance global; un supervisor acotado a la otra
    // empresa no puede ver esta evidencia.
    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);
    $this->actingAs($supervisorAjeno)
        ->get(route('entregas.evidencias.ver', $evidencia))
        ->assertForbidden();
});
