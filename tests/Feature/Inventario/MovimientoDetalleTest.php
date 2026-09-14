<?php

use App\Acciones\CrearEntregaUniforme;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Empresa;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\Talla;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * Detalle de un movimiento de inventario (Fase QA): reutiliza el mismo scope
 * de empresa/almacén que el listado — un movimiento fuera de alcance responde
 * 404 (nunca 403, para no confirmar su existencia), y la referencia al
 * documento de origen sólo trae `url` cuando el usuario puede verlo.
 */
beforeEach(function () {
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
});

it('muestra el detalle de un movimiento dentro del alcance del usuario', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 2]],
        [],
        [],
    );

    $movimiento = MovimientoInventario::query()
        ->where('referencia_tipo', EntregaUniforme::class)
        ->where('referencia_id', $entrega->id)
        ->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/inventario/movimientos/{$movimiento->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventario/MovimientoDetalle')
            ->where('movimiento.tipo', 'entrega')
            ->where('movimiento.cantidad', 2)
            ->where('movimiento.referencia.etiqueta', "Entrega {$entrega->folio}")
            ->where('movimiento.referencia.url', route('entregas.show', $entrega))
            ->where('movimiento.colaborador', $this->datos['colaboradorA']->nombre_completo));
});

it('un movimiento de otra empresa fuera de alcance responde 404, nunca 403', function () {
    $empresaB = $this->datos['empresaB'];
    $almacenB = $this->datos['almacenB'];
    $activoB = Activo::factory()->for($empresaB)->create();
    $tallaB = Talla::factory()->create(['valor' => 'G']);
    $activoB->tallas()->attach($tallaB);

    app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
        empresaId: $empresaB->id, almacenId: $almacenB->id, activoId: $activoB->id, tallaId: $tallaB->id,
        tipo: TipoMovimiento::Inicial, cantidad: 5,
    ));
    $movimientoAjeno = MovimientoInventario::query()->where('empresa_id', $empresaB->id)->firstOrFail();

    // Restringido sólo a empresaA (Administrador/Superadministrador tienen
    // alcance global por diseño — no sirven para probar el 404 por alcance).
    $supervisorA = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $supervisorA->givePermissionTo('inventario.ver');

    $this->actingAs($supervisorA)
        ->get("/inventario/movimientos/{$movimientoAjeno->id}")
        ->assertNotFound();
});

it('la referencia no incluye url cuando el usuario ve el movimiento pero no puede ver la entrega referenciada', function () {
    $entrega = app(CrearEntregaUniforme::class)->ejecutar(
        $this->datos['colaboradorA']->id,
        $this->datos['almacenA']->id,
        $this->admin->id,
        now()->toDateString(),
        [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 1]],
        [],
        [],
    );
    $movimiento = MovimientoInventario::query()
        ->where('referencia_tipo', EntregaUniforme::class)
        ->where('referencia_id', $entrega->id)
        ->firstOrFail();

    // Mismo alcance de empresa que el movimiento (ve el listado sin
    // problema), pero SIN el permiso `entregas.ver`: la etiqueta se muestra
    // (es informativa), el link no — nunca se expone una URL a un recurso
    // que el usuario no puede abrir. Sin rol (los roles base ya traen
    // `entregas.ver`, y Spatie no permite "revocar" un permiso heredado por
    // rol a nivel de usuario): permisos puntuales + acceso directo a la
    // empresa vía el pivote `empresa_usuario`.
    $usuarioSinEntregas = User::factory()->create();
    $usuarioSinEntregas->empresas()->sync([$this->datos['empresaA']->id]);
    $usuarioSinEntregas->givePermissionTo('inventario.ver');

    $this->actingAs($usuarioSinEntregas)
        ->get("/inventario/movimientos/{$movimiento->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('movimiento.referencia.etiqueta', "Entrega {$entrega->folio}")
            ->where('movimiento.referencia.url', null));
});
