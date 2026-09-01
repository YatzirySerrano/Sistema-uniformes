<?php

namespace Database\Seeders;

use App\Enums\RolSistema;
use App\Soporte\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermisosSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permisos::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $porRol = Permisos::porRol();

        foreach (RolSistema::cases() as $rol) {
            $role = Role::findOrCreate($rol->value, 'web');
            $definicion = $porRol[$rol->value] ?? [];

            if ($definicion === '*') {
                $role->syncPermissions(Permisos::todos());

                continue;
            }

            $role->syncPermissions($definicion);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
