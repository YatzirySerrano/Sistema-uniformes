<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Prepara un ambiente de demostración completo (datos ficticios).
     * NO contiene datos reales. Contraseña de todos los usuarios: "password".
     */
    public function run(): void
    {
        $this->call([
            RolesPermisosSeeder::class,
            EmpresasDemoSeeder::class,
            UsuariosDemoSeeder::class,
            CatalogosDemoSeeder::class,
            ColaboradoresDemoSeeder::class,
            InventarioDemoSeeder::class,
            EntregasDemoSeeder::class,
        ]);
    }
}
