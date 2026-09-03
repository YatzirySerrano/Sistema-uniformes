<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->otra = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
});

it('activos/buscar exige empresa_id, filtra por control y no cruza empresas', function () {
    Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisola azul', 'codigo' => 'ACT-0099', 'tipo_control' => 'cantidad']);
    Activo::factory()->for($this->empresa)->serializado()->create(['nombre' => 'Camisola serializada rara']);
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

it('los buscadores exigen permiso de lectura', function () {
    $usuario = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($usuario)
        ->getJson('/activos/buscar?q=x')
        ->assertForbidden();

    $this->actingAs($usuario)
        ->getJson('/almacenes/buscar?q=x')
        ->assertForbidden();
});
