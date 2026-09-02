<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Introduce la relación estructurada Colaborador → Área. La columna de texto
     * `area` se conserva como espejo para compatibilidad temporal con el
     * importador, el exportador y el snapshot de acuse hasta la reingeniería del
     * módulo Colaboradores. La fuente de verdad futura es `area_id`.
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->foreignId('area_id')->nullable()->after('area')
                ->constrained('areas')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['empresa_id', 'area_id']);
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropForeign(['area_id']);
            $table->dropIndex(['empresa_id', 'area_id']);
            $table->dropColumn('area_id');
        });
    }
};
