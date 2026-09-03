<?php

use App\Acciones\ConfirmarAcuseRecepcion;
use App\Acciones\CrearEntregaUniforme;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Excepciones\EntregaYaFirmadaException;
use App\Excepciones\ExcepcionDeNegocioSimple;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use App\Soporte\ContextoEmpresa;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->encargado = User::factory()->create();

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));

    $this->entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['empresaA']->id,
        $this->datos['sucursalA']->id,
        $this->datos['colaboradorA']->id,
        $this->encargado->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
    );

    $this->confirmar = app(ConfirmarAcuseRecepcion::class);
});

it('crea el acuse con firma almacenada de forma privada, huellas y snapshot', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, '127.0.0.1', 'PHPUnit');

    expect($acuse->folio)->toStartWith('ACU-')
        ->and($acuse->hash_documento)->toHaveLength(64)
        ->and($acuse->hash_firma)->toHaveLength(64)
        ->and($acuse->snapshot_entrega['items'][0]['activo'])->toBe('Camisa')
        ->and($this->entrega->fresh()->estado)->toBe(EstadoEntrega::Firmada);

    Storage::disk('local')->assertExists($acuse->ruta_firma);
    Storage::disk('local')->assertExists($acuse->ruta_pdf);
});

it('rechaza una firma vacía o inválida', function () {
    $this->confirmar->ejecutar($this->entrega, 'data:image/png;base64,AAAA', null, null, null);
})->throws(ExcepcionDeNegocioSimple::class);

it('impide firmar dos veces la misma entrega', function () {
    $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    $this->confirmar->ejecutar($this->entrega->fresh(), firmaDemoBase64(), null, null, null);
})->throws(EntregaYaFirmadaException::class);

it('el snapshot del acuse no cambia aunque después se renombre el activo', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    $this->datos['activoA']->update(['nombre' => 'Camisa Renombrada']);

    expect($acuse->fresh()->snapshot_entrega['items'][0]['activo'])->toBe('Camisa');
});

it('otro colaborador no puede descargar el PDF de un acuse ajeno (IDOR)', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    $intruso = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);

    $this->actingAs($intruso)
        ->withSession([ContextoEmpresa::SESSION_KEY => $this->datos['empresaA']->id])
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertForbidden();
});

it('un supervisor autorizado sí puede descargar el PDF', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);

    $this->actingAs($supervisor)
        ->withSession([ContextoEmpresa::SESSION_KEY => $this->datos['empresaA']->id])
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('el titular con cuenta puede ver su propio comprobante', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    $titular = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->datos['colaboradorA']->update(['usuario_id' => $titular->id]);

    $this->actingAs($titular)
        ->withSession([ContextoEmpresa::SESSION_KEY => $this->datos['empresaA']->id])
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertOk();
});

it('un usuario de rol restringido de otra empresa recibe 403 al intentar ver el PDF', function () {
    $acuse = $this->confirmar->ejecutar($this->entrega, firmaDemoBase64(), null, null, null);

    // Supervisor (tiene acuses.ver-pdf) limitado a la empresa B: no alcanza a la empresa A.
    $ajeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($ajeno)
        ->withSession([ContextoEmpresa::SESSION_KEY => $this->datos['empresaB']->id])
        ->get("/acuses/{$acuse->id}/pdf")
        ->assertForbidden();
});
