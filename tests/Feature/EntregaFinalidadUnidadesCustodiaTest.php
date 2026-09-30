<?php

use App\Enums\EstadoUnidadActivo;
use App\Enums\FinalidadCustodia;
use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\Activo;
use App\Models\Colaborador;
use App\Models\DetalleEntrega;
use App\Models\EntregaUniforme;
use App\Models\Talla;
use App\Models\UnidadActivo;
use App\Models\User;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Nueva entrega: finalidad OBLIGATORIA por renglón (históricos NULL siguen
 * válidos), unidad concreta obligatoria para seguimiento individual, y el
 * selector "Desde mi custodia" con bolsa + variante + disponible explícitos.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->talla36 = Talla::factory()->create(['valor' => '36']);
    $this->datos['activoA']->tallas()->attach($this->talla36);

    foreach ([$this->datos['tallaA'], $this->talla36] as $talla) {
        app(ServicioInventario::class)->registrarMovimiento(new MovimientoInventarioDatos(
            empresaId: $this->datos['empresaA']->id,
            almacenId: $this->datos['almacenA']->id,
            activoId: $this->datos['activoA']->id,
            tallaId: $talla->id,
            tipo: TipoMovimiento::Inicial,
            cantidad: 50,
        ));
    }

    $this->celular = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create(['nombre' => 'Celular Samsung']);
    $this->unidad = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])->create();
    $this->juan = $this->datos['colaboradorA'];

    $this->payload = fn (array $extra = []): array => [
        'colaborador_id' => $this->juan->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma' => firmaDemoBase64(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [],
        'unidades' => [],
        ...$extra,
    ];
    $this->entregar = fn (array $extra = []) => $this->actingAs($this->admin)->from('/entregas/crear')->post('/entregas', ($this->payload)($extra));
    $this->pantalones = fn (int $cantidad, ?string $finalidad = 'uso_personal', ?Talla $talla = null): array => array_filter([
        'activo_id' => $this->datos['activoA']->id,
        'talla_id' => ($talla ?? $this->datos['tallaA'])->id,
        'cantidad' => $cantidad,
        'finalidad' => $finalidad,
    ], fn ($v) => $v !== null);
});

/*
|--------------------------------------------------------------------------
| Finalidad obligatoria (backend)
|--------------------------------------------------------------------------
*/

it('una entrega nueva sin finalidad en el renglón se rechaza en el backend', function () {
    ($this->entregar)(['activos' => [($this->pantalones)(2, null)], 'unidades' => [['unidad_activo_id' => $this->unidad->id]]])
        ->assertSessionHasErrors([
            'activos.0.finalidad' => 'Elige la finalidad (Uso personal o Para redistribuir) de este renglón.',
            'unidades.0.finalidad' => 'Elige la finalidad (Uso personal o Para redistribuir) de esta unidad.',
        ]);

    expect(EntregaUniforme::count())->toBe(0);
});

it('registra la finalidad elegida: uso personal o para redistribuir', function (string $finalidad) {
    ($this->entregar)(['activos' => [($this->pantalones)(2, $finalidad)]])->assertSessionHasNoErrors();

    expect(DetalleEntrega::query()->sole()->finalidad)->toBe(FinalidadCustodia::from($finalidad));
})->with(['uso_personal', 'redistribucion']);

it('un renglón histórico con finalidad NULL sigue siendo válido y se muestra «Sin clasificar»', function () {
    ($this->entregar)(['activos' => [($this->pantalones)(2)]])->assertSessionHasNoErrors();
    $detalle = DetalleEntrega::query()->sole();
    $detalle->update(['finalidad' => null]);

    $this->actingAs($this->admin)->get("/entregas/{$detalle->entrega_uniforme_id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('entrega.items.0.finalidad', null)
            ->where('entrega.items.0.finalidad_etiqueta', 'Sin clasificar'));
});

/*
|--------------------------------------------------------------------------
| Unidad concreta obligatoria
|--------------------------------------------------------------------------
*/

it('un activo de seguimiento individual sin unidad concreta no puede entregarse', function () {
    ($this->entregar)(['unidades' => [['activo_id' => $this->celular->id, 'finalidad' => 'uso_personal']]])
        ->assertSessionHasErrors(['unidades.0.unidad_activo_id' => 'Selecciona la unidad concreta (su código) que vas a entregar.']);

    // Tampoco por la vía de "artículos por cantidad".
    ($this->entregar)(['activos' => [['activo_id' => $this->celular->id, 'cantidad' => 1, 'finalidad' => 'uso_personal']]])
        ->assertSessionHasErrors('activos.0.activo_id');

    expect(EntregaUniforme::count())->toBe(0)
        ->and($this->unidad->fresh()->estado)->toBe(EstadoUnidadActivo::EnAlmacen);
});

it('rechaza una unidad que no pertenece al activo elegido en el renglón', function () {
    $laptop = Activo::factory()->for($this->datos['empresaA'])->seguimientoIndividual()->create();

    ($this->entregar)(['unidades' => [['activo_id' => $laptop->id, 'unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal']]])
        ->assertSessionHasErrors(['unidades.0.unidad_activo_id' => 'La unidad seleccionada no corresponde al activo elegido en este renglón.']);
});

it('rechaza una unidad no disponible en el almacén de origen', function () {
    $asignada = UnidadActivo::factory()->for($this->datos['empresaA'], 'empresa')->for($this->celular)->for($this->datos['almacenA'])->asignada()->create();

    ($this->entregar)(['unidades' => [['activo_id' => $this->celular->id, 'unidad_activo_id' => $asignada->id, 'finalidad' => 'uso_personal']]])
        ->assertSessionHasErrors('unidades.0.unidad_activo_id');
});

it('rechaza la misma unidad duplicada en la misma entrega', function () {
    $fila = ['activo_id' => $this->celular->id, 'unidad_activo_id' => $this->unidad->id, 'finalidad' => 'uso_personal'];

    ($this->entregar)(['unidades' => [$fila, $fila]])
        ->assertSessionHasErrors(['unidades.1.unidad_activo_id' => 'No puedes elegir la misma unidad dos veces.']);
});

it('una unidad válida del activo elegido se entrega', function () {
    ($this->entregar)(['unidades' => [['activo_id' => $this->celular->id, 'unidad_activo_id' => $this->unidad->id, 'finalidad' => 'redistribucion']]])
        ->assertSessionHasNoErrors();

    expect($this->unidad->fresh())
        ->estado->toBe(EstadoUnidadActivo::Asignada)
        ->colaborador_id->toBe($this->juan->id);
});

/*
|--------------------------------------------------------------------------
| Selector "Desde mi custodia": bolsas separadas y etiquetadas
|--------------------------------------------------------------------------
*/

describe('selector desde mi custodia', function () {
    beforeEach(function () {
        $crearUsuario = function (array $permisos): User {
            $rol = Role::create(['name' => 'rol-'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
            $rol->syncPermissions($permisos);

            return tap(User::factory()->create(), fn (User $u) => $u->assignRole($rol)->empresas()->sync([$this->datos['empresaA']->id]));
        };
        $this->coordinador = $crearUsuario(['entregas.ver', 'entregas.redistribuir']);
        $this->conPropios = $crearUsuario(['entregas.ver', 'entregas.redistribuir', 'entregas.redistribuir-propios']);
        $this->custodio = Colaborador::factory()->for($this->datos['empresaA'])->for($this->datos['sucursalA'])->create(['usuario_id' => $this->coordinador->id]);

        // Pantalón talla M x5 para uso personal + talla 36 x10 para redistribuir.
        ($this->entregar)([
            'colaborador_id' => $this->custodio->id,
            'activos' => [($this->pantalones)(5, 'uso_personal'), ($this->pantalones)(10, 'redistribucion', $this->talla36)],
        ])->assertSessionHasNoErrors();

        $this->opciones = fn (User $usuario): array => $this->actingAs($usuario)
            ->getJson("/entregas/custodia/activos?empresa_id={$this->datos['empresaA']->id}&control=cantidad")
            ->assertOk()
            ->json('activos');
    });

    it('sin «redistribuir propios» sólo ofrece la bolsa para redistribuir, con su variante y disponible', function () {
        $opciones = ($this->opciones)($this->coordinador);

        expect($opciones)->toHaveCount(1)
            ->and($opciones[0])->toMatchArray([
                'nombre' => 'Camisa',
                'bolsa' => 'redistribucion',
                'bolsa_etiqueta' => 'Para redistribuir',
                'talla_fija' => ['id' => $this->talla36->id, 'valor' => '36'],
                'disponible' => 10,
            ]);
    });

    it('con «redistribuir propios» muestra también la bolsa personal, etiquetada y separada, sin mezclar cantidades', function () {
        $this->custodio->update(['usuario_id' => $this->conPropios->id]);

        $opciones = collect(($this->opciones)($this->conPropios))->keyBy('bolsa');

        expect($opciones)->toHaveCount(2)
            ->and($opciones['redistribucion'])->toMatchArray(['bolsa_etiqueta' => 'Para redistribuir', 'disponible' => 10, 'talla_fija' => ['id' => $this->talla36->id, 'valor' => '36']])
            ->and($opciones['personal'])->toMatchArray(['bolsa_etiqueta' => 'Uso personal / sin clasificar', 'disponible' => 5, 'talla_fija' => ['id' => $this->datos['tallaA']->id, 'valor' => 'M']])
            ->and($opciones['redistribucion']['id'])->not->toBe($opciones['personal']['id']);
    });

    it('la misma talla en dos bolsas son dos opciones distintas', function () {
        $this->custodio->update(['usuario_id' => $this->conPropios->id]);
        ($this->entregar)(['colaborador_id' => $this->custodio->id, 'activos' => [($this->pantalones)(3, 'redistribucion')]])->assertSessionHasNoErrors();

        $deTallaM = collect(($this->opciones)($this->conPropios))->where('talla_fija.valor', 'M')->keyBy('bolsa');

        expect($deTallaM)->toHaveCount(2)
            ->and($deTallaM['personal']['disponible'])->toBe(5)
            ->and($deTallaM['redistribucion']['disponible'])->toBe(3);
    });
});
