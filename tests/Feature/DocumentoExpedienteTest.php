<?php

use App\Enums\CategoriaDocumentoExpediente;
use App\Enums\RolSistema;
use App\Models\Colaborador;
use App\Models\DocumentoExpediente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->datos = escenarioMultiempresa();
    $this->admin = usuarioCon(RolSistema::Administrador->value);
});

/*
|--------------------------------------------------------------------------
| Alta y versiones
|--------------------------------------------------------------------------
*/

it('sube el primer documento del expediente y crea la versión 1', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Identificacion->value,
            'nombre' => 'INE',
            'descripcion' => 'Identificación oficial',
            'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect();

    $documento = DocumentoExpediente::query()->where('colaborador_id', $colaborador->id)->first();

    expect($documento)->not->toBeNull()
        ->and($documento->nombre)->toBe('INE')
        ->and($documento->categoria)->toBe(CategoriaDocumentoExpediente::Identificacion)
        ->and($documento->versiones)->toHaveCount(1)
        ->and($documento->versionActual->version)->toBe(1);

    Storage::disk('local')->assertExists($documento->versionActual->ruta);
});

it('sube una nueva versión, incrementa el número y conserva la anterior', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Fiscal->value,
        'nombre' => 'Constancia fiscal',
        'archivo' => UploadedFile::fake()->create('csf.pdf', 100, 'application/pdf'),
    ]);

    $documento = DocumentoExpediente::query()->where('nombre', 'Constancia fiscal')->firstOrFail();
    $rutaAnterior = $documento->versionActual->ruta;

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/version", [
            'archivo' => UploadedFile::fake()->create('csf-actualizada.pdf', 120, 'application/pdf'),
            'comentario' => 'Actualizada por vencimiento',
        ])
        ->assertRedirect();

    $documento->refresh();

    expect($documento->versiones)->toHaveCount(2)
        ->and($documento->versionActual->version)->toBe(2);

    Storage::disk('local')->assertExists($rutaAnterior);
    Storage::disk('local')->assertExists($documento->versionActual->ruta);
});

it('rechaza tipos de archivo no permitidos y archivos demasiado pesados', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Otros->value,
            'nombre' => 'Archivo raro',
            'archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('archivo');

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => CategoriaDocumentoExpediente::Otros->value,
            'nombre' => 'Archivo pesado',
            'archivo' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'),
        ])
        ->assertSessionHasErrors('archivo');

    expect(DocumentoExpediente::query()->count())->toBe(0);
});

it('rechaza una categoría que no existe en el catálogo', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente", [
            'categoria' => 'no-existe',
            'nombre' => 'Documento',
            'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasErrors('categoria');
});

it('lista el historial completo de versiones', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Contratos->value,
        'nombre' => 'Contrato',
        'archivo' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Contrato')->firstOrFail();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/version", [
        'archivo' => UploadedFile::fake()->create('contrato-v2.pdf', 100, 'application/pdf'),
    ]);

    $respuesta = $this->actingAs($this->admin)
        ->getJson("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/versiones");

    $respuesta->assertOk();
    expect($respuesta->json('versiones'))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Eliminar / restaurar + auditoría
|--------------------------------------------------------------------------
*/

it('cambia el estado activo del documento (eliminar/restaurar) y lo audita', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento a eliminar',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento a eliminar')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado")
        ->assertRedirect();

    expect($documento->fresh()->activo)->toBeFalse();

    $this->assertDatabaseHas('bitacora_auditoria', [
        'modulo' => 'colaboradores',
        'accion' => 'expediente-eliminar',
        'entidad_id' => $documento->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/estado")
        ->assertRedirect();

    expect($documento->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Autorización / IDOR
|--------------------------------------------------------------------------
*/

it('impide descargar el expediente sin el permiso de descarga', function () {
    $colaborador = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaborador->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Confidencial',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Confidencial')->firstOrFail();

    // El Supervisor tiene `expediente-descargar` por defecto (Permisos::porRol());
    // se le revoca explícitamente para probar el gate de forma aislada.
    $supervisor = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaA']]);
    $supervisor->revokePermissionTo('colaboradores.expediente-descargar');
    $supervisor->roles->first()->revokePermissionTo('colaboradores.expediente-descargar');

    $this->actingAs($supervisor)
        ->get("/colaboradores/{$colaborador->id}/expediente/{$documento->id}/descargar")
        ->assertForbidden();
});

it('impide ver el expediente de un colaborador de otra empresa (IDOR)', function () {
    $colaboradorA = $this->datos['colaboradorA'];

    $this->actingAs($this->admin)->post("/colaboradores/{$colaboradorA->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento A',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento A')->firstOrFail();

    $supervisorAjeno = usuarioCon(RolSistema::Supervisor->value, [$this->datos['empresaB']]);

    $this->actingAs($supervisorAjeno)
        ->get("/colaboradores/{$colaboradorA->id}/expediente/{$documento->id}/descargar")
        ->assertForbidden();

    $this->actingAs($supervisorAjeno)
        ->get("/colaboradores/{$colaboradorA->id}/expediente")
        ->assertForbidden();
});

it('devuelve 404 si el documento no pertenece al colaborador de la URL', function () {
    $colaboradorA = $this->datos['colaboradorA'];
    $colaboradorB = Colaborador::factory()->for($this->datos['empresaB'])->for($this->datos['sucursalB'])->create();

    $this->actingAs($this->admin)->post("/colaboradores/{$colaboradorA->id}/expediente", [
        'categoria' => CategoriaDocumentoExpediente::Otros->value,
        'nombre' => 'Documento A',
        'archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
    ]);
    $documento = DocumentoExpediente::query()->where('nombre', 'Documento A')->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/colaboradores/{$colaboradorB->id}/expediente/{$documento->id}/descargar")
        ->assertNotFound();
});
