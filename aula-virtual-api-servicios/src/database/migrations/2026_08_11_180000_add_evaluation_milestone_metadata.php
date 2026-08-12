<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if (!$schema->hasTable('curso_edicion_sesion_evaluaciones')) {
            return;
        }

        $schema->table('curso_edicion_sesion_evaluaciones', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('curso_edicion_sesion_evaluaciones', 'hito_nombre')) {
                $table->string('hito_nombre', 120)->nullable()->after('fecha_limite');
            }

            if (!$schema->hasColumn('curso_edicion_sesion_evaluaciones', 'hito_orden')) {
                $table->unsignedSmallInteger('hito_orden')->nullable()->after('hito_nombre');
            }

            if (!$schema->hasColumn('curso_edicion_sesion_evaluaciones', 'grupo_nombre')) {
                $table->string('grupo_nombre', 120)->nullable()->after('hito_orden');
            }

            if (!$schema->hasColumn('curso_edicion_sesion_evaluaciones', 'plazo_dias')) {
                $table->unsignedSmallInteger('plazo_dias')->nullable()->after('grupo_nombre');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if (!$schema->hasTable('curso_edicion_sesion_evaluaciones')) {
            return;
        }

        $schema->table('curso_edicion_sesion_evaluaciones', function (Blueprint $table) use ($schema) {
            foreach (['plazo_dias', 'grupo_nombre', 'hito_orden', 'hito_nombre'] as $column) {
                if ($schema->hasColumn('curso_edicion_sesion_evaluaciones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
