<?php

use App\Enums\CondicionDevolucion;
use App\Enums\EstadoDevolucion;
use App\Enums\EstadoEntrega;
use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Area;
use App\Models\BitacoraAuditoria;
use App\Models\Colaborador;
use App\Models\Contrato;
use App\Models\DetalleDevolucion;
use App\Models\DetalleEntrega;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\Servicio;
use App\Models\Sucursal;
use App\Models\UnidadActivo;

/**
 * Transferencia de un colaborador a otra empresa / razón social. Es una
 * operación DISTINTA del cambio de servicio: bloquea si hay custodia
 * pendiente, genera número de empleado nuevo, deja el servicio sin asignar y
 * no toca ningún registro histórico.
 */
beforeEach(function () {
    sembrarRolesPermisos();
    $this->dasti = Empresa::factory()->create(['nombre_comercial' => 'DASTI']);
    $this->siesa = Empresa::factory()->create(['nombre_comercial' => 'SIESA']);
    $this->sucDasti = Sucursal::factory()->for($this->dasti)->create();
    $this->sucSiesa = Sucursal::factory()->for($this->siesa)->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value); // alcance global

    $this->colaborador = fn (array $attrs = []): Colaborador => Colaborador::factory()
        ->for($this->dasti)->for($this->sucDasti)->create($attrs);

    $this->transferir = fn (Colaborador $c, array $datos = []) => $this->actingAs($this->admin)
        ->post("/colaboradores/{$c->id}/cambiar-empresa", array_merge([
            'empresa_destino_id' => $this->siesa->id,
            'sucursal_destino_id' => $this->sucSiesa->id,
            'motivo' => 'Reasignación de contrato',
        ], $datos));

    // Renglón de cantidad firmado (prenda en poder del colaborador).
    $this->conPrendaPendiente = function (Colaborador $c, int $cantidad = 2): DetalleEntrega {
        $entrega = EntregaUniforme::factory()
            ->for($this->dasti)->for($this->sucDasti)->for($c)
            ->create(['estado' => EstadoEntrega::Firmada]);

        return DetalleEntrega::factory()->for($entrega, 'entrega')->create([
            'unidad_activo_id' => null,
            'cantidad' => $cantidad,
            'activo_nombre_snapshot' => 'Camisa',
            'talla_valor_snapshot' => 'M',
        ]);
    };
});

it('DASTI→SIESA sin custodia: transfiere y limpia el servicio', function () {
    $contratoDasti = Contrato::factory()->for($this->dasti)->create();
    $servicioDasti = Servicio::factory()->for($contratoDasti)->for($this->sucDasti)->create();
    $c = ($this->colaborador)(['servicio_actual_id' => $servicioDasti->id]);
    $numeroPrevio = $c->numero_empleado;

    ($this->transferir)($c)->assertRedirect()->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->empresa_id)->toBe($this->siesa->id)
        ->and($c->sucursal_id)->toBe($this->sucSiesa->id)
        ->and($c->servicio_actual_id)->toBeNull()
        ->and($c->numero_empleado)->not->toBe($numeroPrevio);
});

it('genera un número de empleado propio de la empresa de destino', function () {
    // Un colaborador que ya existe en SIESA "reserva" un consecutivo.
    Colaborador::factory()->for($this->siesa)->for($this->sucSiesa)->create();
    $c = ($this->colaborador)();

    ($this->transferir)($c)->assertSessionHasNoErrors();

    $c->refresh();
    $repetidos = Colaborador::query()
        ->where('empresa_id', $this->siesa->id)
        ->where('numero_empleado', $c->numero_empleado)
        ->count();
    expect($repetidos)->toBe(1);
});

it('rechaza la transferencia si el colaborador tiene una unidad identificada asignada', function () {
    $c = ($this->colaborador)();
    $almacen = Almacen::factory()->paraEmpresa($this->dasti)->create();
    $activo = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->dasti)->for($activo)->for($almacen)->asignada()
        ->create(['colaborador_id' => $c->id]);

    ($this->transferir)($c)->assertSessionHasErrors();

    expect($c->fresh()->empresa_id)->toBe($this->dasti->id);
});

it('rechaza la transferencia si tiene una prenda pendiente de devolución', function () {
    $c = ($this->colaborador)();
    ($this->conPrendaPendiente)($c, 2);

    ($this->transferir)($c)->assertSessionHasErrors();

    expect($c->fresh()->empresa_id)->toBe($this->dasti->id);
});

it('una devolución parcial confirmada NO libera la custodia', function () {
    $c = ($this->colaborador)();
    $detalle = ($this->conPrendaPendiente)($c, 3);

    $dev = Devolucion::factory()->for($this->dasti)->for($this->sucDasti)->for($c)
        ->create(['estado' => EstadoDevolucion::Confirmada]);
    DetalleDevolucion::factory()->for($dev, 'devolucion')->create([
        'detalle_entrega_id' => $detalle->id,
        'unidad_activo_id' => null,
        'cantidad' => 1, // devuelve 1 de 3
        'condicion' => CondicionDevolucion::Reutilizable,
    ]);

    ($this->transferir)($c)->assertSessionHasErrors();
    expect($c->fresh()->empresa_id)->toBe($this->dasti->id);
});

it('con la devolución COMPLETA confirmada, la transferencia procede', function () {
    $c = ($this->colaborador)();
    $detalle = ($this->conPrendaPendiente)($c, 3);

    $dev = Devolucion::factory()->for($this->dasti)->for($this->sucDasti)->for($c)
        ->create(['estado' => EstadoDevolucion::Confirmada]);
    DetalleDevolucion::factory()->for($dev, 'devolucion')->create([
        'detalle_entrega_id' => $detalle->id,
        'unidad_activo_id' => null,
        'cantidad' => 3,
        'condicion' => CondicionDevolucion::Reutilizable,
    ]);

    ($this->transferir)($c)->assertSessionHasNoErrors();
    expect($c->fresh()->empresa_id)->toBe($this->siesa->id);
});

it('una unidad ya devuelta (no asignada) no bloquea la transferencia', function () {
    $c = ($this->colaborador)();
    $almacen = Almacen::factory()->paraEmpresa($this->dasti)->create();
    $activo = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->dasti)->for($activo)->for($almacen)
        ->create(['colaborador_id' => $c->id, 'estado' => 'en_almacen']);

    ($this->transferir)($c)->assertSessionHasNoErrors();
    expect($c->fresh()->empresa_id)->toBe($this->siesa->id);
});

it('rechaza una sucursal de destino que no pertenece a la empresa de destino', function () {
    $c = ($this->colaborador)();

    ($this->transferir)($c, ['sucursal_destino_id' => $this->sucDasti->id])
        ->assertSessionHasErrors('sucursal_destino_id');

    expect($c->fresh()->empresa_id)->toBe($this->dasti->id);
});

it('rechaza un área de destino que no pertenece a la empresa de destino', function () {
    $c = ($this->colaborador)();
    $areaDasti = Area::factory()->for($this->dasti)->create();

    ($this->transferir)($c, ['area_destino_id' => $areaDasti->id])
        ->assertSessionHasErrors('area_destino_id');
});

it('un usuario sin acceso a la empresa de destino recibe 403', function () {
    // Supervisor acotado sólo a DASTI.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->dasti]);
    $supervisor->givePermissionTo('colaboradores.cambiar-empresa');
    $c = ($this->colaborador)();

    $this->actingAs($supervisor)
        ->post("/colaboradores/{$c->id}/cambiar-empresa", [
            'empresa_destino_id' => $this->siesa->id,
            'sucursal_destino_id' => $this->sucSiesa->id,
            'motivo' => 'x',
        ])
        ->assertForbidden();
});

it('un usuario sin el permiso colaboradores.cambiar-empresa recibe 403', function () {
    $encargado = usuarioCon(RolSistema::Encargado->value, [$this->dasti, $this->siesa]);
    $c = ($this->colaborador)();

    $this->actingAs($encargado)
        ->post("/colaboradores/{$c->id}/cambiar-empresa", [
            'empresa_destino_id' => $this->siesa->id,
            'sucursal_destino_id' => $this->sucSiesa->id,
            'motivo' => 'x',
        ])
        ->assertForbidden();
});

it('no permite asignar un servicio de DASTI a un colaborador ya trasladado a SIESA', function () {
    $contratoDasti = Contrato::factory()->for($this->dasti)->create();
    $servicioDasti = Servicio::factory()->for($contratoDasti)->for($this->sucDasti)->create();
    $c = ($this->colaborador)();
    ($this->transferir)($c)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$c->id}/servicio", ['servicio_id' => $servicioDasti->id])
        ->assertSessionHasErrors('servicio_id');
});

it('los registros históricos de DASTI quedan intactos tras la transferencia', function () {
    $c = ($this->colaborador)();
    $entrega = EntregaUniforme::factory()->for($this->dasti)->for($this->sucDasti)->for($c)
        ->create(['estado' => EstadoEntrega::Firmada, 'folio' => 'ENT-2026-000123']);

    ($this->transferir)($c)->assertSessionHasNoErrors();

    $entrega->refresh();
    expect($entrega->empresa_id)->toBe($this->dasti->id)
        ->and($entrega->folio)->toBe('ENT-2026-000123')
        ->and($entrega->colaborador_id)->toBe($c->id);
});

it('conserva el mismo colaborador (id) tras la transferencia', function () {
    $c = ($this->colaborador)();
    $idAntes = $c->id;

    ($this->transferir)($c)->assertSessionHasNoErrors();

    expect($c->fresh()->id)->toBe($idAntes)
        ->and(Colaborador::query()->count())->toBe(1);
});

it('registra la transferencia en la bitácora de auditoría', function () {
    $c = ($this->colaborador)();

    ($this->transferir)($c, ['motivo' => 'Cambio de adscripción'])->assertSessionHasNoErrors();

    $bitacora = BitacoraAuditoria::query()
        ->where('modulo', 'colaboradores')->where('accion', 'cambiar_empresa')->latest('id')->first();

    expect($bitacora)->not->toBeNull()
        ->and($bitacora->empresa_id)->toBe($this->siesa->id)
        ->and($bitacora->motivo)->toBe('Cambio de adscripción')
        ->and($bitacora->valores_anteriores['empresa'])->toBe('DASTI')
        ->and($bitacora->valores_nuevos['empresa'])->toBe('SIESA')
        ->and($bitacora->valores_nuevos['servicio'])->toBe('Sin servicio');
});

it('el re-chequeo bajo lock aborta si aparece custodia entre la previsualización y la confirmación', function () {
    $c = ($this->colaborador)();

    // Previsualización: sin pendientes.
    $this->actingAs($this->admin)->getJson("/colaboradores/{$c->id}/custodia")
        ->assertOk()->assertJsonPath('tiene_pendientes', false);

    // Entre la vista previa y el confirmar, se le asigna una unidad.
    $almacen = Almacen::factory()->paraEmpresa($this->dasti)->create();
    $activo = Activo::factory()->for($this->dasti)->seguimientoIndividual()->create();
    UnidadActivo::factory()->for($this->dasti)->for($activo)->for($almacen)->asignada()
        ->create(['colaborador_id' => $c->id]);

    ($this->transferir)($c)->assertSessionHasErrors();
    expect($c->fresh()->empresa_id)->toBe($this->dasti->id);
});
