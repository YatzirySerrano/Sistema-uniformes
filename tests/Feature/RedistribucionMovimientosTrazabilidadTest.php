<?php

use App\Enums\DireccionMovimiento;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\EntregaUniforme;
use App\Models\MovimientoInventario;
use App\Models\SaldoInventario;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Una redistribución colaborador → colaborador aparece en Movimientos como
 * evento de CUSTODIA (delta de stock 0, nunca un falso −1/+1) y la
 * trazabilidad de la entrega distingue variante, código de unidad,
 * destinatario, cantidad y folio en toda la cadena.
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
    $this->celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Celular Samsung']);
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])
        ->create(['codigo' => 'CELULAR-SAMSUNG-000002']);

    $custodioCon = function (string $nombre): Colaborador {
        $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $rol->syncPermissions(['entregas.ver', 'entregas.redistribuir']);
        $usuario = tap(User::factory()->create(), fn (User $u) => $u->assignRole($rol)->empresas()->sync([$this->datos['empresaA']->id]));

        return Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])
            ->create(['nombre_completo' => $nombre, 'usuario_id' => $usuario->id]);
    };
    $this->efren = $custodioCon('Efren Coordinador');
    $this->dulce = $custodioCon('Dulce María');
    $this->pedro = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['nombre_completo' => 'Pedro Final']);

    $this->payload = fn (Colaborador $destino, array $activos, array $unidades, array $extra = []): array => [
        'colaborador_id' => $destino->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => $activos,
        'unidades' => $unidades,
        ...$extra,
    ];
    $this->camisas = fn (int $cantidad, string $finalidad = 'redistribucion'): array => [[
        'activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => $cantidad, 'finalidad' => $finalidad,
    ]];
    $this->celularFila = fn (string $finalidad = 'redistribucion'): array => [['unidad_activo_id' => $this->unidad->id, 'finalidad' => $finalidad]];

    $this->desdeAlmacen = fn (Colaborador $destino, array $activos = [], array $unidades = []) => $this->actingAs($this->admin)
        ->post('/entregas', ($this->payload)($destino, $activos, $unidades, ['almacen_id' => $this->datos['almacenA']->id]));
    $this->redistribuir = fn (Colaborador $origen, Colaborador $destino, array $activos = [], array $unidades = []) => $this->actingAs(User::query()->findOrFail($origen->usuario_id))
        ->post('/entregas', ($this->payload)($destino, $activos, $unidades, ['origen' => 'custodia']));

    $this->stock = fn (): int => (int) SaldoInventario::query()->where('activo_id', $this->datos['activoA']->id)->value('cantidad');
    $this->movimientos = fn (array $filtros = []) => $this->actingAs($this->admin)->get('/inventario/movimientos?'.http_build_query($filtros));
});

it('la entrega desde almacén sigue apareciendo en Movimientos, con su folio', function () {
    ($this->desdeAlmacen)($this->efren, ($this->camisas)(5))->assertSessionHasNoErrors();
    $folio = EntregaUniforme::query()->sole()->folio;

    ($this->movimientos)(['tipo' => 'entrega'])
        ->assertInertia(fn ($p) => $p
            ->where('movimientos.total', 1)
            ->where('movimientos.data.0.direccion', 'salida')
            ->where('movimientos.data.0.afecta_stock', true)
            ->where('movimientos.data.0.referencia', "Entrega {$folio}"));
});

it('una redistribución aparece como evento de custodia con origen, destino, variante y cantidad, sin tocar el stock', function () {
    ($this->desdeAlmacen)($this->efren, ($this->camisas)(5))->assertSessionHasNoErrors();
    $stockAntes = ($this->stock)();
    $movimientosDeStockAntes = MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count();

    ($this->redistribuir)($this->efren, $this->dulce, ($this->camisas)(1, 'uso_personal'))->assertSessionHasNoErrors();
    $folio = EntregaUniforme::query()->where('colaborador_id', $this->dulce->id)->sole()->folio;

    expect(($this->stock)())->toBe($stockAntes)
        ->and(MovimientoInventario::query()->where('direccion', '!=', DireccionMovimiento::SinEfecto)->count())->toBe($movimientosDeStockAntes);

    $evento = MovimientoInventario::query()->where('tipo', TipoMovimiento::RedistribucionCustodia)->sole();
    expect($evento->almacen_id)->toBeNull()
        ->and($evento->existencia_anterior)->toBe($evento->existencia_resultante)
        ->and($evento->direccion)->toBe(DireccionMovimiento::SinEfecto);

    ($this->movimientos)(['tipo' => 'redistribucion_custodia'])
        ->assertInertia(fn ($p) => $p
            ->where('movimientos.total', 1)
            ->where('movimientos.data.0.tipo_etiqueta', 'Redistribución de custodia')
            ->where('movimientos.data.0.afecta_stock', false)
            ->where('movimientos.data.0.activo', 'Camisa')
            ->where('movimientos.data.0.talla', 'M')
            ->where('movimientos.data.0.cantidad', 1)
            ->where('movimientos.data.0.custodia.origen', 'Efren Coordinador')
            ->where('movimientos.data.0.custodia.destino', 'Dulce María')
            ->where('movimientos.data.0.custodia.folio', $folio)
            ->where('movimientos.data.0.custodia.finalidad_origen', 'Para redistribuir')
            ->where('movimientos.data.0.custodia.finalidad_destino', 'Uso personal'));
});

it('la redistribución de una unidad individual muestra su código y queda en el detalle del movimiento', function () {
    ($this->desdeAlmacen)($this->efren, [], ($this->celularFila)())->assertSessionHasNoErrors();
    ($this->redistribuir)($this->efren, $this->dulce, [], ($this->celularFila)('uso_personal'))->assertSessionHasNoErrors();

    ($this->movimientos)(['tipo' => 'redistribucion_custodia'])
        ->assertInertia(fn ($p) => $p
            ->where('movimientos.data.0.unidad_codigo', 'CELULAR-SAMSUNG-000002')
            ->where('movimientos.data.0.custodia.origen', 'Efren Coordinador')
            ->where('movimientos.data.0.custodia.destino', 'Dulce María'));

    $evento = MovimientoInventario::query()->where('tipo', TipoMovimiento::RedistribucionCustodia)->sole();
    $this->actingAs($this->admin)->get("/inventario/movimientos/{$evento->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('movimiento.afecta_stock', false)
            ->where('movimiento.colaborador', 'Dulce María')
            ->where('movimiento.custodia.origen', 'Efren Coordinador'));

    // La ficha de la unidad no duplica el evento (ya lo reconstruye de la entrega).
    $this->actingAs($this->admin)->get("/activos/unidades/{$this->unidad->public_token}")
        ->assertInertia(fn ($p) => $p->where('movimientos', fn ($movs) => collect($movs)->where('tipo', 'Redistribución de custodia')->count() === 1));
});

it('la trazabilidad por cantidad dice variante, destinatario, cantidad entregada y folio', function () {
    ($this->desdeAlmacen)($this->efren, ($this->camisas)(5))->assertSessionHasNoErrors();
    ($this->redistribuir)($this->efren, $this->dulce, ($this->camisas)(1))->assertSessionHasNoErrors();
    $origen = EntregaUniforme::query()->where('colaborador_id', $this->efren->id)->sole();
    $hijo = EntregaUniforme::query()->where('colaborador_id', $this->dulce->id)->sole();

    $this->actingAs($this->admin)->get("/entregas/{$origen->id}")
        ->assertInertia(fn ($p) => $p
            ->where('entrega.items.0.talla', 'M')
            ->where('entrega.items.0.unidad_codigo', null)
            ->where('entrega.items.0.redistribuido_a.0.colaborador', 'Dulce María')
            ->where('entrega.items.0.redistribuido_a.0.cantidad', 1)
            ->where('entrega.items.0.redistribuido_a.0.talla', 'M')
            ->where('entrega.items.0.redistribuido_a.0.unidad_codigo', null)
            ->where('entrega.items.0.redistribuido_a.0.folio', $hijo->folio));
});

it('la trazabilidad de una unidad usa el código de unidad, no una talla', function () {
    ($this->desdeAlmacen)($this->efren, [], ($this->celularFila)())->assertSessionHasNoErrors();
    ($this->redistribuir)($this->efren, $this->dulce, [], ($this->celularFila)())->assertSessionHasNoErrors();
    $origen = EntregaUniforme::query()->where('colaborador_id', $this->efren->id)->sole();
    $hijo = EntregaUniforme::query()->where('colaborador_id', $this->dulce->id)->sole();

    $this->actingAs($this->admin)->get("/entregas/{$origen->id}")
        ->assertInertia(fn ($p) => $p
            ->where('entrega.items.0.talla', null)
            ->where('entrega.items.0.unidad_codigo', 'CELULAR-SAMSUNG-000002')
            ->where('entrega.items.0.redistribuido_a.0.unidad_codigo', 'CELULAR-SAMSUNG-000002')
            ->where('entrega.items.0.redistribuido_a.0.talla', null)
            ->where('entrega.items.0.redistribuido_a.0.colaborador', 'Dulce María')
            ->where('entrega.items.0.redistribuido_a.0.folio', $hijo->folio));
});

it('una cadena Almacén → Efren → Dulce → Pedro sigue siendo reconstruible sin modificar entregas anteriores', function () {
    ($this->desdeAlmacen)($this->efren, ($this->camisas)(5))->assertSessionHasNoErrors();
    ($this->redistribuir)($this->efren, $this->dulce, ($this->camisas)(3))->assertSessionHasNoErrors();
    ($this->redistribuir)($this->dulce, $this->pedro, ($this->camisas)(2, 'uso_personal'))->assertSessionHasNoErrors();

    $alEfren = EntregaUniforme::query()->where('colaborador_id', $this->efren->id)->sole();
    $aDulce = EntregaUniforme::query()->where('colaborador_id', $this->dulce->id)->sole();
    $aPedro = EntregaUniforme::query()->where('colaborador_id', $this->pedro->id)->sole();

    $this->actingAs($this->admin)->get("/entregas/{$aDulce->id}")
        ->assertInertia(fn ($p) => $p
            ->where('entrega.items.0.cantidad', 3)
            ->where('entrega.items.0.recibido_de.folio', $alEfren->folio)
            ->where('entrega.items.0.recibido_de.colaborador', 'Efren Coordinador')
            ->where('entrega.items.0.redistribuido_a.0.folio', $aPedro->folio)
            ->where('entrega.items.0.redistribuido_a.0.cantidad', 2));

    // El documento original conserva su cantidad: cada eslabón es su propia entrega.
    expect($alEfren->detalles()->sole()->cantidad)->toBe(5)
        ->and(MovimientoInventario::query()->where('tipo', TipoMovimiento::RedistribucionCustodia)->count())->toBe(2)
        ->and(($this->stock)())->toBe(15);
});
