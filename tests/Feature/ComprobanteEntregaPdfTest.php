<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\AcuseRecepcion;
use App\Models\Evidencia;
use App\Models\Talla;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioAcusePdf;
use App\Servicios\ServicioInventario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprobante PDF de entrega: incrusta las evidencias fotográficas junto a su
 * renglón (referencia determinista hash+mime en el snapshot), sin romper los
 * acuses históricos ni depender de que el archivo físico siga existiendo.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->tallaB = Talla::factory()->create(['valor' => 'G']);
    $this->datos['activoA']->tallas()->attach($this->tallaB);

    foreach ([$this->datos['tallaA']->id, $this->tallaB->id] as $tallaId) {
        app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $this->datos['empresaA']->id,
            almacenId: $this->datos['almacenA']->id,
            activoId: $this->datos['activoA']->id,
            tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial,
            cantidad: 50,
        ));
    }

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

it('genera el comprobante de una entrega SIN evidencia exactamente igual que antes', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ]))
        ->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::firstOrFail();

    $this->actingAs($this->admin)
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($acuse->snapshot_entrega['items'][0]['evidencias'])->toBe([]);
});

it('guarda en el snapshot la referencia determinista (hash + mime) de la evidencia de cada renglón', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [
                ['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2],
                [
                    'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->tallaB->id, 'cantidad' => 1,
                    'evidencia' => UploadedFile::fake()->image('estado-g.jpg'),
                    'evidencia_origen' => 'archivo',
                ],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::firstOrFail();
    $evidencia = Evidencia::firstOrFail();

    // Renglones ordenados por id: el primero (talla M) sin evidencia, el segundo (talla G) con ella.
    $items = $acuse->snapshot_entrega['items'];
    expect($items[0]['evidencias'])->toBe([]);
    expect($items[1]['evidencias'][0]['hash_sha256'])->toBe($evidencia->hash_sha256)
        ->and($items[1]['evidencias'][0]['mime'])->toBe($evidencia->mime);

    $this->actingAs($this->admin)->get("/acuses/{$acuse->id}/pdf")->assertOk();
});

it('el hash del documento es reverificable a partir del snapshot inmutable', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [[
                'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1,
                'evidencia' => UploadedFile::fake()->image('e.jpg'),
                'evidencia_origen' => 'archivo',
            ]],
        ]))
        ->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::firstOrFail();

    $recalculado = hash('sha256', json_encode(
        $acuse->snapshot_entrega,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));

    expect($recalculado)->toBe($acuse->hash_documento)
        ->and($acuse->hash_firma_operador)->toHaveLength(64);
});

it('un acuse histórico cuyo snapshot no tiene la clave "evidencias" sigue generando PDF sin lanzar', function () {
    $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)([
            'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        ]))
        ->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::firstOrFail();

    // Simula un snapshot anterior a esta ronda: sin la clave `evidencias`.
    $snapshot = $acuse->snapshot_entrega;
    $snapshot['items'] = array_map(function (array $item): array {
        unset($item['evidencias']);

        return $item;
    }, $snapshot['items']);
    $acuse->forceFill(['snapshot_entrega' => $snapshot])->save();

    $ruta = app(ServicioAcusePdf::class)->generar($acuse->fresh());

    expect(Storage::disk('local')->exists($ruta))->toBeTrue();

    $this->actingAs($this->admin)->get("/acuses/{$acuse->id}/pdf")->assertOk();
});
