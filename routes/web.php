<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(auth()->check() ? '/dashboard' : '/login');
})->name('home');

require __DIR__.'/sistema.php';
require __DIR__.'/settings.php';
