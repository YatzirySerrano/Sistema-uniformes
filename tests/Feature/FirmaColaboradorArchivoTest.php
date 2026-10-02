<?php

use App\Enums\RolSistema;
use App\Enums\TipoMovimiento;
use App\Models\AcuseDevolucion;
use App\Models\AcuseRecepcion;
use App\Models\BitacoraAuditoria;
use App\Models\EntregaUniforme;
use App\Models\Evidencia;
use App\Servicios\DTO\MovimientoInventarioDatos;
use App\Servicios\ServicioInventario;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firma de quien recibe / quien devuelve: dibujada en el pad o SUBIDA como
 * archivo (firma a distancia: imagen o PDF). Una sola fuente por acuse; el
 * método y los metadatos del archivo quedan registrados.
 */
function imagenFirmaPng(): string
{
    $imagen = imagecreatetruecolor(400, 150);
    imagefill($imagen, 0, 0, (int) imagecolorallocate($imagen, 255, 255, 255));
    $tinta = (int) imagecolorallocate($imagen, 15, 23, 42);
    for ($x = 20; $x < 380; $x += 3) {
        imagesetpixel($imagen, $x, 75 + (int) (30 * sin($x / 20)), $tinta);
        imageline($imagen, $x, 70 + ($x % 40), $x + 3, 80 - ($x % 30), $tinta);
    }
    ob_start();
    imagepng($imagen);

    return (string) ob_get_clean();
}

function pdfFirma(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

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

    $this->entregar = fn (array $firma) => $this->actingAs($this->admin)->from('/entregas/crear')->post('/entregas', [
        'colaborador_id' => $this->datos['colaboradorA']->id,
        'almacen_id' => $this->datos['almacenA']->id,
        'fecha_entrega' => now()->toDateString(),
        'firma_operador' => firmaDemoBase64(),
        'aceptacion' => true,
        'idempotency_key' => (string) Str::uuid(),
        'activos' => [['activo_id' => $this->datos['activoA']->id, 'talla_id' => $this->datos['tallaA']->id, 'cantidad' => 3, 'finalidad' => 'uso_personal']],
        'unidades' => [],
        ...$firma,
    ]);

    $this->devolver = function (array $firma) {
        $entrega = EntregaUniforme::query()->latest('id')->firstOrFail();

        return $this->actingAs($this->admin)->from('/devoluciones/crear')->post('/devoluciones', [
            'entrega_uniforme_id' => $entrega->id,
            'almacen_id' => $this->datos['almacenA']->id,
            'fecha' => now()->toDateString(),
            'activos' => [['detalle_entrega_id' => $entrega->detalles()->firstOrFail()->id, 'cantidad' => 1, 'condicion' => 'reutilizable']],
            'unidades' => [],
            'firma_operador' => firmaDemoBase64(),
            'aceptacion' => true,
            ...$firma,
        ]);
    };
});

it('la entrega admite la firma dibujada (sin método = dibujada) y la registra así', function () {
    ($this->entregar)(['firma' => firmaDemoBase64()])->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::query()->sole();
    expect($acuse->metodoFirma())->toBe('dibujada')
        ->and($acuse->firmaArchivo)->toBeNull()
        ->and(Storage::disk('local')->exists($acuse->ruta_firma))->toBeTrue();
});

it('la entrega admite una IMAGEN como firma del colaborador: se normaliza a PNG y se usa como firma visual', function () {
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma-juan.jpg', imagenFirmaPng()),
    ])->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::query()->sole();
    $evidencia = $acuse->firmaArchivo;
    $normalizada = Storage::disk('local')->get($acuse->ruta_firma);

    expect($acuse->metodoFirma())->toBe('archivo')
        ->and($evidencia->origen)->toBe(Evidencia::ORIGEN_FIRMA_ARCHIVO)
        ->and($evidencia->nombre_original)->toBe('firma-juan.jpg')
        ->and($evidencia->mime)->toBe('image/png') // MIME REAL por contenido, no por extensión
        ->and($evidencia->subido_por)->toBe($this->admin->id)
        ->and($evidencia->created_at)->not->toBeNull()
        ->and(str_starts_with($normalizada, "\x89PNG"))->toBeTrue()
        ->and($acuse->hash_firma)->toBe(hash('sha256', $normalizada))
        ->and($acuse->tienePdf())->toBeTrue();

    $this->actingAs($this->admin)->get("/acuses/{$acuse->id}/firma")
        ->assertOk()->assertHeader('content-type', 'image/png');

    expect(BitacoraAuditoria::query()->where('accion', 'firmar')->sole()->valores_nuevos['firma_colaborador'])
        ->toBe('firma por archivo «firma-juan.jpg»');
});

it('la entrega admite un PDF como evidencia de firma: se conserva íntegro y el comprobante lo indica como documento adjunto', function () {
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma-remota.pdf', pdfFirma()),
    ])->assertSessionHasNoErrors();

    $acuse = AcuseRecepcion::query()->sole();

    expect($acuse->firmaArchivo->esPdf())->toBeTrue()
        ->and(Storage::disk('local')->get($acuse->ruta_firma))->toBe(pdfFirma())
        ->and($acuse->hash_firma)->toBe(hash('sha256', pdfFirma()))
        ->and($acuse->tienePdf())->toBeTrue();

    $this->actingAs($this->admin)->get("/acuses/{$acuse->id}/firma")
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->admin)->get("/entregas/{$acuse->entrega_uniforme_id}")
        ->assertInertia(fn ($p) => $p
            ->where('acuse.firma_metodo', 'archivo')
            ->where('acuse.firma_archivo', ['nombre' => 'firma-remota.pdf', 'es_pdf' => true]));
});

it('la devolución admite firma dibujada, imagen o PDF de quien devuelve', function (string $metodo) {
    ($this->entregar)(['firma' => firmaDemoBase64()])->assertSessionHasNoErrors();

    $firma = match ($metodo) {
        'dibujada' => ['firma' => firmaDemoBase64()],
        'imagen' => ['firma_metodo' => 'archivo', 'firma_archivo' => UploadedFile::fake()->createWithContent('firma.png', imagenFirmaPng())],
        'pdf' => ['firma_metodo' => 'archivo', 'firma_archivo' => UploadedFile::fake()->createWithContent('firma.pdf', pdfFirma())],
    };

    ($this->devolver)($firma)->assertSessionHasNoErrors();

    $acuse = AcuseDevolucion::query()->sole();
    expect($acuse->metodoFirma())->toBe($metodo === 'dibujada' ? 'dibujada' : 'archivo')
        ->and($acuse->firmaArchivo?->esPdf() ?? false)->toBe($metodo === 'pdf');
})->with(['dibujada', 'imagen', 'pdf']);

it('rechaza un archivo de firma cuyo contenido real no es imagen ni PDF', function () {
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma.png', 'esto no es una imagen'),
    ])->assertSessionHasErrors(['negocio' => 'El archivo de firma debe ser una imagen PNG, JPG o WEBP, o un PDF.']);

    // La extensión no basta: el contenido se revisa por magic bytes antes de
    // guardar nada (además de `mimes`/`mimetypes` en el Form Request).

    expect(EntregaUniforme::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('firmas'))->toBe([]);
});

it('rechaza un archivo de firma de más de 5 MB', function () {
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma.pdf', pdfFirma().str_repeat('0', 5 * 1024 * 1024 + 10)),
    ])->assertSessionHasErrors(['firma_archivo' => 'El archivo de firma no puede pesar más de 5 MB.']);

    expect(EntregaUniforme::count())->toBe(0);
});

it('no exige firma dibujada y archivo a la vez, y rechaza enviar ambos', function () {
    // Sólo archivo: suficiente.
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma.png', imagenFirmaPng()),
    ])->assertSessionHasNoErrors();

    // Ambas: contradictorio → rechazado.
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma' => firmaDemoBase64(),
        'firma_archivo' => UploadedFile::fake()->createWithContent('firma.png', imagenFirmaPng()),
    ])->assertSessionHasErrors('firma');

    // Método archivo sin archivo.
    ($this->entregar)(['firma_metodo' => 'archivo'])
        ->assertSessionHasErrors(['firma_archivo' => 'Sube el archivo con la firma del colaborador.']);

    expect(EntregaUniforme::count())->toBe(1);
});

it('rechaza en el Form Request una extensión no admitida', function () {
    ($this->entregar)([
        'firma_metodo' => 'archivo',
        'firma_archivo' => UploadedFile::fake()->create('firma.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('firma_archivo');

    expect(EntregaUniforme::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Comprobante: el bloque de "documento adjunto" cabe en su recuadro
|--------------------------------------------------------------------------
| QA 2026-10: el nombre (UUID) y el SHA-256 de 64 caracteres no tenían
| dónde partirse, el bloque reutilizaba la altura fija de la imagen de firma
| y la tabla de firmas tenía ancho automático, así que el texto invadía
| "Firma de quien entrega". El solapamiento real se revisa en QA manual
| sobre el PDF; aquí se fija la estructura que lo evita.
*/

it('el comprobante muestra completos el nombre largo y el SHA-256 del documento adjunto, en una caja que los parte y sin invadir la otra firma', function (string $vista) {
    $nombre = '085a1853-5f9e-44c1-9ab5-001e4467fa7b-firma-remota-del-colaborador-con-nombre-muy-largo.pdf';
    $hash = '007d7590941804b5e69d3198b3fc8b439b0d2bfc5e680bb51f095a6fe96b0054';
    ($this->entregar)(['firma' => firmaDemoBase64()])->assertSessionHasNoErrors();
    $acuse = AcuseRecepcion::query()->sole();

    $datos = [
        'acuse' => $acuse, 'snapshot' => $acuse->snapshot_entrega, 'firmaDataUri' => null,
        'firmaArchivo' => (object) ['created_at' => now()],
        'firmaDocumentoAdjunto' => (object) ['nombre_original' => $nombre, 'hash_sha256' => $hash],
        'firmaOperadorDataUri' => null, 'logoDataUri' => null, 'evidenciasPorItem' => [],
    ];
    $html = view($vista, $datos)->render();

    expect($html)
        ->toContain($nombre)
        ->toContain($hash)
        ->toContain('Firma: documento adjunto')
        ->toContain('<div class="firma-documento">')
        ->toContain('<table class="firmas">')
        ->toContain('table.firmas { width: 100%; table-layout: fixed; }')
        ->toMatch('/\.firma-documento \{[^}]*overflow-wrap: anywhere/')
        ->toMatch('/\.valor-largo \{[^}]*overflow-wrap: anywhere/')
        // La caja del documento ya no usa la altura fija de la imagen.
        ->not->toMatch('/class="firma-img"[^>]*>\s*<strong>Firma: documento adjunto/');

    // Y el motor real (Dompdf) lo genera sin errores.
    expect(Pdf::loadView($vista, $datos)->setPaper('letter')->output())->toStartWith('%PDF');
})->with(['acuses.comprobante', 'acuses.comprobante-devolucion']);

it('la firma dibujada se sigue imprimiendo como imagen en su recuadro', function () {
    ($this->entregar)(['firma' => firmaDemoBase64()])->assertSessionHasNoErrors();
    $acuse = AcuseRecepcion::query()->sole();

    $html = view('acuses.comprobante', [
        'acuse' => $acuse, 'snapshot' => $acuse->snapshot_entrega, 'firmaDataUri' => firmaDemoBase64(),
        'firmaOperadorDataUri' => firmaDemoBase64(), 'logoDataUri' => null, 'evidenciasPorItem' => [],
    ])->render();

    expect($html)->toContain('<img class="firma-img" src="data:image/png;base64,')
        ->not->toContain('<div class="firma-documento">')
        ->toContain($acuse->hash_firma);
});
