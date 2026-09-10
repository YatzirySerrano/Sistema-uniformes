<?php

use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Models\DocumentoExpediente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Captura de una identificación oficial FALTANTE durante el flujo de entrega:
 * se guarda en el EXPEDIENTE del colaborador (no sólo en la entrega), con
 * autorización de mínimo privilegio y sin permitir duplicados.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value, [$this->datos['empresaA']]);
    $this->colaborador = $this->datos['colaboradorA'];
});

it('detecta que no hay INE y permite subir una que queda en el expediente', function () {
    $this->actingAs($this->admin)
        ->getJson("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertOk()
        ->assertJson(['disponible' => false]);

    $this->actingAs($this->admin)
        ->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
            'archivo' => UploadedFile::fake()->image('ine.jpg', 1000, 640),
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $documento = DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->first();

    expect($documento)->not->toBeNull();
    expect($documento->versiones()->count())->toBe(1);
    expect($documento->versionActual->hash_sha256)->not->toBeEmpty();

    // Una entrega posterior del mismo colaborador ya la encuentra.
    $this->actingAs($this->admin)
        ->getJson("/entregas/documento-identidad/{$this->colaborador->id}")
        ->assertJson(['disponible' => true]);
});

it('no crea una segunda identificación si el colaborador ya tiene una', function () {
    $this->actingAs($this->admin)->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
        'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ])->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
            'archivo' => UploadedFile::fake()->image('ine2.jpg'),
        ])
        ->assertStatus(422);

    expect(DocumentoExpediente::query()
        ->where('colaborador_id', $this->colaborador->id)
        ->where('categoria', CategoriaDocumentoExpediente::Identificacion)
        ->count())->toBe(1);
});

it('rechaza un archivo que no es imagen ni PDF', function () {
    $this->actingAs($this->admin)
        ->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
            'archivo' => UploadedFile::fake()->create('x.txt', 10, 'text/plain'),
        ])
        ->assertStatus(422);
});

it('exige poder registrar entregas y acceso a la empresa del colaborador', function () {
    // Encargado sin acceso a la empresa del colaborador.
    $encargadoAjeno = usuarioCon(RolSistema::Encargado->value, [$this->datos['empresaB']]);
    $this->actingAs($encargadoAjeno)
        ->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
            'archivo' => UploadedFile::fake()->image('ine.jpg'),
        ])
        ->assertForbidden();

    // Colaborador base: no puede registrar entregas.
    $colaboradorUser = usuarioCon(RolSistema::Colaborador->value, [$this->datos['empresaA']]);
    $this->actingAs($colaboradorUser)
        ->postJson("/entregas/documento-identidad/{$this->colaborador->id}", [
            'archivo' => UploadedFile::fake()->image('ine.jpg'),
        ])
        ->assertForbidden();
});
