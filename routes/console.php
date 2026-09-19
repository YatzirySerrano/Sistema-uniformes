<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mantenimiento del apartado temporal de inventario (ver Reserva/ServicioReservas):
// nunca es la garantía de corrección, sólo evita que la tabla crezca sin límite.
Schedule::command('reservas:limpiar')->everyFifteenMinutes();
