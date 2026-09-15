<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\SaldoInventario;
use App\Models\Talla;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->otra = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('activos/buscar exige empresa_id, filtra por control y no cruza empresas', function () {
    Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisola azul', 'codigo' => 'ACT-0099', 'tipo_control' => 'cantidad']);
    Activo::factory()->for($this->empresa)->seguimientoIndividual()->create(['nombre' => 'Camisola con seguimiento individual']);
    Activo::factory()->for($this->otra)->create(['nombre' => 'Camisola ajena']);

    // Sin empresa_id: no devuelve nada.
    $this->actingAs($this->admin)
        ->getJson('/activos/buscar?control=cantidad&q=camisola')
        ->assertOk()
        ->assertJsonCount(0, 'activos');

    $this->actingAs($this->admin)
        ->getJson('/activos/buscar?empresa_id='.$this->empresa->id.'&control=cantidad&q=camisola')
        ->assertOk()
        ->assertJsonCount(1, 'activos')
        ->assertJsonPath('activos.0.nombre', 'Camisola azul');
});

it('almacenes/buscar acotado por empresa_id devuelve sólo los almacenes activos de esa empresa', function () {
    Almacen::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Central Morelos', 'codigo' => 'ALM-9001']);
    Almacen::factory()->paraEmpresa($this->empresa)->inactivo()->create(['nombre' => 'Central inactivo', 'codigo' => 'ALM-9002']);
    Almacen::factory()->paraEmpresa($this->otra)->create(['nombre' => 'Central ajeno', 'codigo' => 'ALM-9003']);

    $this->actingAs($this->admin)
        ->getJson('/almacenes/buscar?empresa_id='.$this->empresa->id.'&q=central')
        ->assertOk()
        ->assertJsonCount(1, 'almacenes')
        ->assertJsonPath('almacenes.0.nombre', 'Central Morelos');
});

it('activos/buscar con almacen_id resuelve la disponibilidad de varios activos sin N+1', function () {
    $almacen = Almacen::factory()->paraEmpresa($this->empresa)->create();
    $tallaChica = Talla::factory()->create(['valor' => 'CH']);
    $tallaGrande = Talla::factory()->create(['valor' => 'G']);

    $crearActivoConSaldo = function (string $nombre) use ($almacen, $tallaChica, $tallaGrande): void {
        $activo = Activo::factory()->for($this->empresa)->create(['nombre' => $nombre]);
        $activo->tallas()->attach([$tallaChica->id, $tallaGrande->id]);
        SaldoInventario::factory()->create([
            'empresa_id' => $this->empresa->id,
            'almacen_id' => $almacen->id,
            'activo_id' => $activo->id,
            'talla_id' => $tallaChica->id,
            'cantidad' => 3,
        ]);
    };

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $contarConsultas = function () use ($almacen, &$consultas): int {
        $consultas = 0;
        $this->actingAs($this->admin)
            ->getJson('/activos/buscar?empresa_id='.$this->empresa->id.'&q=camisola&almacen_id='.$almacen->id)
            ->assertOk();

        return $consultas;
    };

    $crearActivoConSaldo('Camisola 1');
    // Llamada de calentamiento (fuera de la medición): estabiliza cachés de
    // sesión/permisos para que ambas mediciones partan del mismo estado.
    $contarConsultas();
    $conUno = $contarConsultas();

    // 4 activos más (variantes + saldo cada uno): antes del fix, cada fila
    // disparaba su propia consulta de `tallasElegibles()` + saldos, así que
    // el conteo escalaba linealmente con el resultado. Con el fix, el número
    // de consultas es el mismo sin importar cuántas filas se devuelvan.
    foreach (range(2, 5) as $i) {
        $crearActivoConSaldo("Camisola {$i}");
    }
    $conCinco = $contarConsultas();

    expect($conCinco)->toBe($conUno);

    $respuesta = $this->actingAs($this->admin)
        ->getJson('/activos/buscar?empresa_id='.$this->empresa->id.'&q=camisola&almacen_id='.$almacen->id)
        ->assertOk()
        ->assertJsonCount(5, 'activos');

    $primero = collect($respuesta->json('activos'))->firstWhere('nombre', 'Camisola 1');
    expect($primero['disponible'])->toBe(3)
        ->and(collect($primero['tallas'])->firstWhere('valor', 'CH')['disponible'])->toBe(3)
        ->and(collect($primero['tallas'])->firstWhere('valor', 'G')['disponible'])->toBe(0);
});

it('los buscadores exigen permiso de lectura', function () {
    $usuario = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($usuario)
        ->getJson('/activos/buscar?q=x')
        ->assertForbidden();

    $this->actingAs($usuario)
        ->getJson('/almacenes/buscar?q=x')
        ->assertForbidden();
});
