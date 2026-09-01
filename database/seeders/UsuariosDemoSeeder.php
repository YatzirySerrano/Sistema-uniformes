<?php

namespace Database\Seeders;

use App\Enums\RolSistema;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios ficticios para desarrollo. Contraseña única: "password".
 * NO usar en producción.
 */
class UsuariosDemoSeeder extends Seeder
{
    public function run(): void
    {
        $empresaA = Empresa::query()->where('codigo', 'EMP-A')->first();
        $empresaB = Empresa::query()->where('codigo', 'EMP-B')->first();
        $empresaC = Empresa::query()->where('codigo', 'EMP-C')->first();

        $superadmin = $this->crear('Equipo Técnico (Proveedor)', 'superadmin@example.test', RolSistema::Superadministrador);

        $admin1 = $this->crear('Ana Administradora', 'admin.ab@example.test', RolSistema::Administrador);
        $admin1->empresas()->sync([$empresaA->id, $empresaB->id]);

        $admin2 = $this->crear('Carlos Directivo', 'admin.c@example.test', RolSistema::Administrador);
        $admin2->empresas()->sync([$empresaC->id]);

        $supervisor = $this->crear('Sofía Supervisora', 'supervisor.a@example.test', RolSistema::Supervisor);
        $supervisor->empresas()->sync([$empresaA->id]);
        $supervisor->sucursales()->sync($empresaA->sucursales()->limit(2)->pluck('id'));

        $encargado = $this->crear('Esteban Encargado', 'encargado.a@example.test', RolSistema::Encargado);
        $encargado->empresas()->sync([$empresaA->id]);
        $encargado->sucursales()->sync($empresaA->sucursales()->limit(1)->pluck('id'));

        $colaborador = $this->crear('Lucía Colaboradora', 'colaborador.a@example.test', RolSistema::Colaborador);
        $colaborador->empresas()->sync([$empresaA->id]);
    }

    private function crear(string $nombre, string $email, RolSistema $rol): User
    {
        $usuario = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $nombre,
                'password' => Hash::make('password'),
                'activo' => true,
                'email_verified_at' => now(),
            ],
        );

        $usuario->syncRoles([$rol->value]);

        return $usuario;
    }
}
