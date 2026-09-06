<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        throw_if(
            empty($tableNames),
            Exception::class,
            'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.'
        );

        throw_if(
            $teams && empty($columnNames['team_foreign_key'] ?? null),
            Exception::class,
            'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.'
        );

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        */

        Schema::create($tableNames['permissions'], static function (Blueprint $table) {
            $table->bigIncrements('id');

            // Reducidos para evitar error 1071 en MySQL/MariaDB antiguos
            $table->string('name', 125);
            $table->string('guard_name', 50);

            $table->timestamps();

            $table->unique(
                ['name', 'guard_name'],
                'permissions_name_guard_name_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        Schema::create(
            $tableNames['roles'],
            static function (Blueprint $table) use ($teams, $columnNames) {
                $table->bigIncrements('id');

                if ($teams || config('permission.testing')) {
                    $table->unsignedBigInteger(
                        $columnNames['team_foreign_key']
                    )->nullable();

                    $table->index(
                        $columnNames['team_foreign_key'],
                        'roles_team_foreign_key_index'
                    );
                }

                // Reducidos para evitar índices demasiado grandes
                $table->string('name', 125);
                $table->string('guard_name', 50);

                $table->timestamps();

                if ($teams || config('permission.testing')) {
                    $table->unique(
                        [
                            $columnNames['team_foreign_key'],
                            'name',
                            'guard_name',
                        ],
                        'roles_team_name_guard_name_unique'
                    );
                } else {
                    $table->unique(
                        ['name', 'guard_name'],
                        'roles_name_guard_name_unique'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Model Has Permissions
        |--------------------------------------------------------------------------
        */

        Schema::create(
            $tableNames['model_has_permissions'],
            static function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $pivotPermission,
                $teams
            ) {
                $table->unsignedBigInteger($pivotPermission);

                // IMPORTANTE:
                // string() sin longitud sería 255 y puede exceder los 1000 bytes
                $table->string('model_type', 191);

                $table->unsignedBigInteger(
                    $columnNames['model_morph_key']
                );

                $table->index(
                    [
                        $columnNames['model_morph_key'],
                        'model_type',
                    ],
                    'model_has_permissions_model_id_model_type_index'
                );

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');

                if ($teams) {
                    $table->unsignedBigInteger(
                        $columnNames['team_foreign_key']
                    );

                    $table->index(
                        $columnNames['team_foreign_key'],
                        'model_has_permissions_team_foreign_key_index'
                    );

                    $table->primary(
                        [
                            $columnNames['team_foreign_key'],
                            $pivotPermission,
                            $columnNames['model_morph_key'],
                            'model_type',
                        ],
                        'model_has_permissions_permission_model_type_primary'
                    );
                } else {
                    $table->primary(
                        [
                            $pivotPermission,
                            $columnNames['model_morph_key'],
                            'model_type',
                        ],
                        'model_has_permissions_permission_model_type_primary'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Model Has Roles
        |--------------------------------------------------------------------------
        */

        Schema::create(
            $tableNames['model_has_roles'],
            static function (Blueprint $table) use (
                $tableNames,
                $columnNames,
                $pivotRole,
                $teams
            ) {
                $table->unsignedBigInteger($pivotRole);

                // Reducido para evitar error 1071
                $table->string('model_type', 191);

                $table->unsignedBigInteger(
                    $columnNames['model_morph_key']
                );

                $table->index(
                    [
                        $columnNames['model_morph_key'],
                        'model_type',
                    ],
                    'model_has_roles_model_id_model_type_index'
                );

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');

                if ($teams) {
                    $table->unsignedBigInteger(
                        $columnNames['team_foreign_key']
                    );

                    $table->index(
                        $columnNames['team_foreign_key'],
                        'model_has_roles_team_foreign_key_index'
                    );

                    $table->primary(
                        [
                            $columnNames['team_foreign_key'],
                            $pivotRole,
                            $columnNames['model_morph_key'],
                            'model_type',
                        ],
                        'model_has_roles_role_model_type_primary'
                    );
                } else {
                    $table->primary(
                        [
                            $pivotRole,
                            $columnNames['model_morph_key'],
                            'model_type',
                        ],
                        'model_has_roles_role_model_type_primary'
                    );
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Role Has Permissions
        |--------------------------------------------------------------------------
        */

        Schema::create(
            $tableNames['role_has_permissions'],
            static function (Blueprint $table) use (
                $tableNames,
                $pivotRole,
                $pivotPermission
            ) {
                $table->unsignedBigInteger($pivotPermission);
                $table->unsignedBigInteger($pivotRole);

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');

                $table->primary(
                    [$pivotPermission, $pivotRole],
                    'role_has_permissions_permission_id_role_id_primary'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Clear Permission Cache
        |--------------------------------------------------------------------------
        */

        app('cache')
            ->store(
                config('permission.cache.store') !== 'default'
                    ? config('permission.cache.store')
                    : null
            )
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        throw_if(
            empty($tableNames),
            Exception::class,
            'Error: config/permission.php not found and defaults could not be merged.'
        );

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
