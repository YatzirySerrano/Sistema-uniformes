<?php

namespace App\Console\Commands;

use App\Models\Reserva;
use Illuminate\Console\Command;

/**
 * Limpieza oportunista de reservas ya vencidas/consumidas/liberadas. Es
 * mantenimiento, NUNCA la garantía de corrección: toda consulta de
 * disponibilidad ya filtra por `Reserva::scopeActiva()`, así que una fila
 * vencida no bloquea nada aunque este comando no haya corrido todavía.
 * Idempotente: puede correr cualquier cantidad de veces sin efectos
 * distintos a borrar lo que ya no sirve.
 */
class LimpiarReservasVencidas extends Command
{
    protected $signature = 'reservas:limpiar';

    protected $description = 'Elimina reservas de inventario vencidas, consumidas o liberadas con más de una hora de antigüedad';

    public function handle(): int
    {
        $borradas = Reserva::query()
            ->where(function ($q): void {
                $q->where('expira_en', '<', now()->subHour())
                    ->orWhere('consumida_en', '<', now()->subHour())
                    ->orWhere('liberada_en', '<', now()->subHour());
            })
            ->delete();

        $this->info("Reservas de inventario eliminadas: {$borradas}.");

        return self::SUCCESS;
    }
}
