<?php

use App\Acciones\RegistrarDevolucionFirmada;
use App\Enums\CondicionDevolucion;
use App\Enums\CondicionUnidadActivo;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Eliminar (desactivar) un colaborador exige custodia pendiente en CERO —
 * misma regla estricta que "Transferir a otra empresa": nada de "mantener
 * conmigo", porque dejaría bienes sin responsable.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];
    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $this->datos['empresaA']->id,
        almacenId: $this->datos['almacenA']->id,
        activoId: $this->datos['activoA']->id,
        tallaId: $this->datos['tallaA']->id,
        tipo: TipoMovimiento::Inicial,
        cantidad: 20,
    ));
    $this->celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])->create();

    $this->entregar = fn (array $activos = [], array $unidades = []) => $this->actingAs($this->admin)->post('/entregas', [
        'colaborador_id' => $this->colaborador->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => $activos,
        'unidades' => $unidades,
    ]);
    $this->camisas = fn (string $finalidad): array => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2, 'finalidad' => $finalidad]];

    $this->eliminar = fn () => $this->actingAs($this->admin)->from("/colaboradores/{$this->colaborador->id}")->post("/colaboradores/{$this->colaborador->id}/estado");
    $this->mensaje = 'Este colaborador todavía tiene activos bajo custodia. Registra las devoluciones antes de continuar.';
});

it('sin custodia se elimina (desactiva) y se puede restaurar como siempre', function () {
    ($this->eliminar)()->assertSessionHasNoErrors();
    expect($this->colaborador->fresh()->activo)->toBeFalse();

    ($this->eliminar)()->assertSessionHasNoErrors();
    expect($this->colaborador->fresh()->activo)->toBeTrue();
});

it('con custodia de cualquier bolsa no se puede eliminar', function (string $finalidad) {
    ($this->entregar)(($this->camisas)($finalidad))->assertSessionHasNoErrors();

    ($this->eliminar)()->assertSessionHasErrors(['negocio' => $this->mensaje]);

    expect($this->colaborador->fresh()->activo)->toBeTrue();
})->with(['uso personal' => 'uso_personal', 'para redistribuir' => 'redistribucion']);

it('con custodia histórica «sin clasificar» no se puede eliminar', function () {
    ($this->entregar)(($this->camisas)('uso_personal'))->assertSessionHasNoErrors();
    DetalleEntrega::query()->update(['finalidad' => null]);

    ($this->eliminar)()->assertSessionHasErrors(['negocio' => $this->mensaje]);

    expect($this->colaborador->fresh()->activo)->toBeTrue();
});

it('con una unidad individual asignada no se puede eliminar', function () {
    ($this->entregar)([], [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']])->assertSessionHasNoErrors();

    ($this->eliminar)()->assertSessionHasErrors(['negocio' => $this->mensaje]);

    $this->actingAs($this->admin)->getJson("/colaboradores/{$this->colaborador->id}/custodia")
        ->assertOk()
        ->assertJsonPath('tiene_pendientes', true)
        ->assertJsonPath('pendientes.0.referencia', $this->unidad->codigo);
});

it('después de devolver TODA la custodia se puede eliminar', function () {
    ($this->entregar)(($this->camisas)('uso_personal'), [['unidad_activo_id' => $this->unidad->id, 'finalidad' => 'redistribucion']])->assertSessionHasNoErrors();
    $entrega = EntregaUniforme::query()->sole();
    $detalles = $entrega->detalles()->get();

    app(RegistrarDevolucionFirmada::class)->ejecutar(
        $entrega->id, $this->datos['almacenA']->id, now()->toDateString(),
        [['detalle_entrega_id' => $detalles->firstWhere('unidad_activo_id', null)->id, 'cantidad' => 2, 'condicion' => CondicionDevolucion::Reutilizable->value]],
        [['detalle_entrega_id' => $detalles->firstWhere('unidad_activo_id', $this->unidad->id)->id, 'condicion' => CondicionUnidadActivo::Funcionando->value]],
        $this->admin->id, null, null, firmaDemoBase64(), firmaDemoBase64(), true, null, null,
    );

    ($this->eliminar)()->assertSessionHasNoErrors();

    expect($this->colaborador->fresh()->activo)->toBeFalse();
});

it('quien sólo tiene permiso de desactivar también puede consultar la custodia para ver el bloqueo', function () {
    $rol = Role::create(['name' => 'solo-desactivar', 'guard_name' => 'web']);
    $rol->syncPermissions(['colaboradores.ver', 'colaboradores.desactivar']);
    $usuario = tap(User::factory()->create(), fn (User $u) => $u->assignRole($rol)->empresas()->sync([$this->datos['empresaA']->id]));
    ($this->entregar)(($this->camisas)('uso_personal'))->assertSessionHasNoErrors();

    $this->actingAs($usuario)->getJson("/colaboradores/{$this->colaborador->id}/custodia")
        ->assertOk()
        ->assertJsonPath('tiene_pendientes', true);

    $this->actingAs($usuario)->from('/colaboradores')->post("/colaboradores/{$this->colaborador->id}/estado")
        ->assertSessionHasErrors(['negocio' => $this->mensaje]);

    expect(Colaborador::query()->whereKey($this->colaborador->id)->value('activo'))->toBeTrue();
});
