<?php

use App\Enums\RolSistema;
use App\Models\Activo;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Soporte\ContextoEmpresa;

beforeEach(function () {
    sembrarRolesPermisos();
    $this->empresa = Empresa::factory()->create();
    $this->otra = Empresa::factory()->create();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->empresa]);
    $this->sesion = [ContextoEmpresa::SESSION_KEY => $this->empresa->id];
});

it('activos/buscar filtra por nombre, código y control y no cruza empresas', function () {
    Activo::factory()->for($this->empresa)->create(['nombre' => 'Camisola azul', 'codigo' => 'ACT-0099', 'tipo_control' => 'cantidad']);
    Activo::factory()->for($this->empresa)->serializado()->create(['nombre' => 'Camisola serializada rara']);
    Activo::factory()->for($this->otra)->create(['nombre' => 'Camisola ajena']);

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->getJson('/activos/buscar?control=cantidad&q=camisola')
        ->assertOk()
        ->assertJsonCount(1, 'activos')
        ->assertJsonPath('activos.0.nombre', 'Camisola azul');
});

it('almacenes/buscar solo devuelve almacenes activos y autorizados de la empresa activa', function () {
    Almacen::factory()->for($this->empresa)->create(['nombre' => 'Central Morelos', 'codigo' => 'ALM-0001']);
    Almacen::factory()->for($this->empresa)->inactivo()->create(['nombre' => 'Central inactivo']);
    Almacen::factory()->for($this->otra)->create(['nombre' => 'Central ajeno']);

    $this->actingAs($this->admin)->withSession($this->sesion)
        ->getJson('/almacenes/buscar?q=central')
        ->assertOk()
        ->assertJsonCount(1, 'almacenes')
        ->assertJsonPath('almacenes.0.nombre', 'Central Morelos');
});

it('los buscadores exigen permiso de lectura', function () {
    $usuario = usuarioCon(RolSistema::Colaborador->value, [$this->empresa]);

    $this->actingAs($usuario)->withSession($this->sesion)
        ->getJson('/activos/buscar?q=x')
        ->assertForbidden();

    $this->actingAs($usuario)->withSession($this->sesion)
        ->getJson('/almacenes/buscar?q=x')
        ->assertForbidden();
});
