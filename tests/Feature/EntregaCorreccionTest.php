<?php

use App\Acciones\CorregirEntrega;
use App\Acciones\RegistrarEntregaFirmada;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\EntregaUniforme;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Una entrega FIRMADA es un documento histórico inmutable: no se corrige (ni
 * por UI, ni por URL directa). Sólo una entrega todavía `PendienteFirma`
 * (camino de firma diferida legado) admite corrección administrativa.
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

    $this->acuse = app(RegistrarEntregaFirmada::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5]],
        [], [], null, null,
        firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );
    $this->entrega = EntregaUniforme::findOrFail($this->acuse->entrega_uniforme_id);
});

it('la entrega nace firmada tras el flujo único', function () {
    expect($this->entrega->estado)->toBe(EstadoEntrega::Firmada);
});

it('la Policy no autoriza corregir una entrega firmada, corregida ni anulada; sí una pendiente de firma', function () {
    expect($this->admin->can('corregir', $this->entrega))->toBeFalse();

    $this->entrega->update(['estado' => EstadoEntrega::Corregida]);
    expect($this->admin->can('corregir', $this->entrega->fresh()))->toBeFalse();

    $this->entrega->update(['estado' => EstadoEntrega::Anulada]);
    expect($this->admin->can('corregir', $this->entrega->fresh()))->toBeFalse();

    $this->entrega->update(['estado' => EstadoEntrega::PendienteFirma]);
    expect($this->admin->can('corregir', $this->entrega->fresh()))->toBeTrue();
});

it('el endpoint de corrección responde 403 sobre una entrega firmada', function () {
    $this->actingAs($this->admin)
        ->get("/entregas/{$this->entrega->id}/corregir")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post("/entregas/{$this->entrega->id}/corregir", [
            'motivo' => 'Ajuste de cantidades',
            'items' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        ])
        ->assertForbidden();
});

it('la acción rechaza corregir una entrega firmada con un mensaje humano y no toca el acuse ni su hash', function () {
    $hashAntes = $this->acuse->hash_documento;
    $snapshotAntes = $this->acuse->snapshot_entrega;

    expect(fn () => app(CorregirEntrega::class)->ejecutar(
        $this->entrega,
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3]],
        'Ajuste de cantidades',
        $this->admin->id,
    ))->toThrow(ExcepcionDeNegocioSimple::class, 'Una entrega firmada no puede modificarse.');

    $this->acuse->refresh();
    expect($this->acuse->hash_documento)->toBe($hashAntes)
        ->and($this->acuse->snapshot_entrega)->toBe($snapshotAntes)
        ->and($this->entrega->fresh()->detalles->first()->cantidad)->toBe(5);
});

it('la pantalla de detalle ya no ofrece "Corregir entrega"', function () {
    $this->actingAs($this->admin)
        ->get("/entregas/{$this->entrega->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Entregas/Detalle')
            ->where('permisos.corregir', false));
});
