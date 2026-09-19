<?php

use App\Models\ConfiguracionSistema;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(auth()->check() ? '/dashboard' : '/login');
})->name('home');

// Manifest de instalación como app (PWA). Público (sin sesión) porque el
// navegador lo pide antes de saber si hay alguien autenticado. El color de
// marca se toma de `ConfiguracionSistema` para no duplicarlo a mano; el
// nombre, de `config('app.name')`, igual que el resto de metadatos del sitio.
Route::get('/manifest.webmanifest', function () {
    $nombre = config('app.name', 'Sistema de Uniformes');

    return response()->json([
        'name' => $nombre,
        'short_name' => $nombre,
        'description' => 'Control y gestión de uniformes y activos de la empresa.',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => ConfiguracionSistema::actual()->color_principal,
        'icons' => [
            ['src' => '/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png'],
            ['src' => '/favicon.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('manifest');

require __DIR__.'/sistema.php';
require __DIR__.'/settings.php';
