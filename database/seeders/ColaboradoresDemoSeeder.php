<?php

namespace Database\Seeders;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Seeder;

class ColaboradoresDemoSeeder extends Seeder
{
    public function run(): void
    {
        $areas = ['Producción', 'Almacén', 'Ventas', 'Administración', 'Logística', 'Mantenimiento'];
        $puestos = ['Operador', 'Auxiliar', 'Supervisor de línea', 'Analista', 'Chofer', 'Almacenista'];

        foreach (Empresa::with('sucursales')->get() as $indiceEmpresa => $empresa) {
            $sucursales = $empresa->sucursales;
            $cantidad = 40 + $indiceEmpresa * 15;

            for ($i = 1; $i <= $cantidad; $i++) {
                $numero = ($indiceEmpresa + 1) * 10000 + $i;

                Colaborador::query()->updateOrCreate(
                    ['empresa_id' => $empresa->id, 'numero_empleado' => (string) $numero],
                    [
                        'sucursal_id' => $sucursales->random()->id,
                        'nombre_completo' => fake()->name(),
                        'puesto' => fake()->randomElement($puestos),
                        'area' => fake()->randomElement($areas),
                        'correo' => fake()->boolean(40) ? fake()->unique()->safeEmail() : null,
                        'activo' => fake()->boolean(90),
                    ],
                );
            }
        }

        // Vincula la usuaria "colaborador.a@example.test" a un colaborador de EMP-A.
        $empresaA = Empresa::query()->where('codigo', 'EMP-A')->first();
        $usuaria = User::query()->where('email', 'colaborador.a@example.test')->first();

        if ($empresaA && $usuaria) {
            $colaborador = Colaborador::query()->where('empresa_id', $empresaA->id)->where('activo', true)->first();
            $colaborador?->update(['usuario_id' => $usuaria->id, 'correo' => $usuaria->email]);
        }
    }
}
