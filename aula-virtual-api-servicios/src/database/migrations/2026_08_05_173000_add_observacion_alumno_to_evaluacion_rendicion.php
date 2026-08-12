<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if ($schema->hasTable('evaluacion_rendicion')
            && !$schema->hasColumn('evaluacion_rendicion', 'observacion_alumno')) {
            $schema->table('evaluacion_rendicion', function (Blueprint $table) {
                $table->text('observacion_alumno')->nullable()->after('fecha_entrega');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if ($schema->hasTable('evaluacion_rendicion')
            && $schema->hasColumn('evaluacion_rendicion', 'observacion_alumno')) {
            $schema->table('evaluacion_rendicion', function (Blueprint $table) {
                $table->dropColumn('observacion_alumno');
            });
        }
    }
};
