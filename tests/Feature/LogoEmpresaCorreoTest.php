<?php

use App\Soporte\LogoEmpresaCorreo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo de empresa en correos (comprobantes de entrega/devolución):
 * - Con logo PNG/JPG/GIF existente → se embebe como data URI (nunca una URL
 *   remota, que muchos clientes de correo bloquean o no pueden alcanzar).
 * - Sin logo, archivo faltante, o SVG (formato de logo válido para subir y
 *   para el PDF/Excel, pero no soportado de forma confiable embebido en
 *   correo — Outlook de escritorio no lo renderiza) → `null`, y el layout
 *   del correo pinta un placeholder con la inicial de la empresa en vez de
 *   dejar un hueco o un ícono roto.
 */
beforeEach(function () {
    Storage::fake('public');
});

it('devuelve null cuando no hay ruta de logo', function () {
    expect(LogoEmpresaCorreo::dataUri(null))->toBeNull();
});

it('devuelve null cuando el archivo no existe en el disco', function () {
    expect(LogoEmpresaCorreo::dataUri('logos/no-existe.png'))->toBeNull();
});

it('devuelve null para SVG aunque el archivo exista (no embebible de forma confiable en correo)', function () {
    Storage::disk('public')->put('logos/empresa.svg', '<svg></svg>');

    expect(LogoEmpresaCorreo::dataUri('logos/empresa.svg'))->toBeNull();
});

it('embebe un PNG existente como data URI', function () {
    Storage::disk('public')->put('logos/empresa.png', 'contenido-png-de-prueba');

    $uri = LogoEmpresaCorreo::dataUri('logos/empresa.png');

    expect($uri)->not->toBeNull()
        ->and($uri)->toStartWith('data:image/png;base64,')
        ->and(base64_decode(Str::after($uri, 'base64,')))->toBe('contenido-png-de-prueba');
});

it('el layout del correo muestra el logo cuando hay uno embebible', function () {
    $html = view('emails.layout', [
        'titulo' => 'Confirmación de entrega',
        'folio' => 'ENT-0001',
        'empresaNombre' => 'Empresa Demo',
        'color' => '#171717',
        'logo' => 'data:image/png;base64,Zm9v',
    ])->render();

    expect($html)->toContain('<img src="data:image/png;base64,Zm9v"')
        ->and($html)->not->toContain('rgba(255,255,255,0.18)'); // no debe mostrarse el placeholder si sí hay logo
});

it('el layout del correo muestra un placeholder con la inicial cuando no hay logo embebible', function () {
    $html = view('emails.layout', [
        'titulo' => 'Confirmación de entrega',
        'folio' => 'ENT-0001',
        'empresaNombre' => 'Dasti',
        'color' => '#171717',
        'logo' => null,
    ])->render();

    expect($html)->not->toContain('<img src=""')
        ->and($html)->not->toContain('<img src="{{')
        ->and($html)->toContain('rgba(255,255,255,0.18)') // fondo del placeholder
        ->and(preg_match('/>\s*D\s*</', $html))->toBe(1); // inicial de "Dasti", en mayúscula
});
