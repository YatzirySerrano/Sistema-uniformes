<?php

use App\Acciones\RegistrarTraspasoInventario;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\BitacoraAuditoria;
use App\Models\TraspasoInventario;
use App\Models\UnidadActivo;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;

/**
 * Auditoría de Traspasos: el evento debe conservar QUÉ activos se movieron,
 * como snapshot inmutable (columnas `*_snapshot` de `TraspasoRenglon`, ya
 * congeladas al momento del traspaso) dentro de `valores_nuevos.renglones` —
 * sin abrir un segundo sistema de historial ni tocar `movimientos_inventario`.
 */
beforeEach(function () {
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value);
    $this->inventario = app(ServicioInventario::class);
    $this->almacenA2 = Almacen::factory()->paraEmpresa($this->datos['empresaA'])->create(['nombre' => 'Almacén A-2']);

    $this->cargarStock = function (int $empresaId, int $almacenId, int $activoId, ?int $tallaId, int $cantidad): void {
        $this->inventario->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $empresaId, almacenId: $almacenId, activoId: $activoId, tallaId: $tallaId,
            tipo: TipoMovimiento::Inicial, cantidad: $cantidad,
        ));
    };

    $this->traspasar = fn (array $payload): TraspasoInventario => app(RegistrarTraspasoInventario::class)->ejecutar(
        $payload['empresa_origen_id'], $payload['almacen_origen_id'],
        $payload['empresa_destino_id'], $payload['almacen_destino_id'],
        $payload['renglones'], $this->admin->id, $payload['motivo'] ?? null,
    );

    $this->ultimaAuditoria = fn (): ?BitacoraAuditoria => BitacoraAuditoria::query()
        ->where('modulo', 'inventario')->where('accion', 'traspaso')->latest('id')->first();
});

it('audita activo, talla y cantidad de un traspaso de un solo renglón por cantidad', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa Operativa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 10]],
    ]);

    $renglones = ($this->ultimaAuditoria)()->valores_nuevos['renglones'];

    expect($renglones)->toHaveCount(1)
        ->and($renglones[0])->toMatchArray([
            'control' => 'cantidad',
            'activo_origen' => 'Camisa Operativa',
            'talla' => 'M',
            'cantidad' => 10,
            'unidad_codigo' => null,
        ]);
});

it('conserva todos los renglones en la auditoría cuando el traspaso mueve varios activos', function () {
    $activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón Operativo']);
    $activoB->tallas()->attach($this->datos['tallaA']);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $activoB->id, $this->datos['tallaA']->id, 15);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [
            ['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5],
            ['control' => 'cantidad', 'activo_origen_id' => $activoB->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 8],
        ],
    ]);

    $renglones = ($this->ultimaAuditoria)()->valores_nuevos['renglones'];

    expect($renglones)->toHaveCount(2)
        ->and(collect($renglones)->pluck('activo_origen')->sort()->values()->all())
        ->toBe(['Camisa', 'Pantalón Operativo'])
        ->and(collect($renglones)->pluck('cantidad')->sort()->values()->all())
        ->toBe([5, 8]);
});

it('un activo sin variante no guarda "Sin variante" superflua: la talla del renglón queda null', function () {
    $activoSinVariante = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Laptop Dell']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $activoSinVariante->id, null, 5);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $activoSinVariante->id, 'cantidad' => 3]],
    ]);

    $renglon = ($this->ultimaAuditoria)()->valores_nuevos['renglones'][0];

    expect($renglon['talla'])->toBeNull()
        ->and($renglon['activo_origen'])->toBe('Laptop Dell')
        ->and($renglon['cantidad'])->toBe(3);
});

it('un traspaso de unidad identificada guarda el código de unidad en el renglón de auditoría', function () {
    $activoIndividual = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Tablet Samsung']);
    $unidad = UnidadActivo::factory()->for($this->datos['empresaA'])->for($activoIndividual)->for($this->datos['almacenA'])->create();

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'individual', 'activo_origen_id' => $activoIndividual->id, 'unidad_ids' => [$unidad->id]]],
    ]);

    $renglon = ($this->ultimaAuditoria)()->valores_nuevos['renglones'][0];

    expect($renglon)->toMatchArray([
        'control' => 'individual',
        'activo_origen' => 'Tablet Samsung',
        'talla' => null,
        'cantidad' => 1,
        'unidad_codigo' => $unidad->codigo,
    ]);
});

it('renombrar el activo después del traspaso no altera el snapshot histórico de la auditoría', function () {
    $this->datos['activoA']->update(['nombre' => 'Camisa Operativa']);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4]],
    ]);

    $this->datos['activoA']->update(['nombre' => 'Camisa Operativa RENOMBRADA']);

    $renglon = ($this->ultimaAuditoria)()->valores_nuevos['renglones'][0];

    expect($renglon['activo_origen'])->toBe('Camisa Operativa');
});

it('el JSON técnico conserva folio, empresas y almacenes junto con los renglones, sin perder campos existentes', function () {
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);

    $traspaso = ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 4]],
    ]);

    $valoresNuevos = ($this->ultimaAuditoria)()->valores_nuevos;

    expect($valoresNuevos['folio'])->toBe($traspaso->folio)
        ->and($valoresNuevos['empresa_origen'])->toBe('Empresa A')
        ->and($valoresNuevos['almacen_origen'])->toBe('Almacén A')
        ->and($valoresNuevos['almacen_destino'])->toBe('Almacén A-2')
        ->and($valoresNuevos['renglones'])->toHaveCount(1);
});

it('un traspaso multi-renglón genera un único registro de auditoría, sin duplicar el evento', function () {
    $activoB = Activo::factory()->for($this->datos['empresaA'])->create(['nombre' => 'Pantalón Operativo']);
    $activoB->tallas()->attach($this->datos['tallaA']);

    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $this->datos['activoA']->id, $this->datos['tallaA']->id, 20);
    ($this->cargarStock)($this->datos['empresaA']->id, $this->datos['almacenA']->id, $activoB->id, $this->datos['tallaA']->id, 15);

    ($this->traspasar)([
        'empresa_origen_id' => $this->datos['empresaA']->id, 'almacen_origen_id' => $this->datos['almacenA']->id,
        'empresa_destino_id' => $this->datos['empresaA']->id, 'almacen_destino_id' => $this->almacenA2->id,
        'renglones' => [
            ['control' => 'cantidad', 'activo_origen_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 5],
            ['control' => 'cantidad', 'activo_origen_id' => $activoB->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 8],
        ],
    ]);

    expect(BitacoraAuditoria::where('modulo', 'inventario')->where('accion', 'traspaso')->count())->toBe(1);
});
