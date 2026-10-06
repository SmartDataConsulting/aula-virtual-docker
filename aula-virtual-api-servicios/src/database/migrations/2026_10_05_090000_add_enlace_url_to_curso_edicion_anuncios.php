<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');
        if ($schema->hasTable('curso_edicion_anuncios') && !$schema->hasColumn('curso_edicion_anuncios', 'enlace_url')) {
            $schema->table('curso_edicion_anuncios', function (Blueprint $table) {
                $table->string('enlace_url', 2048)->nullable()->after('contenido');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql_cursos');
        if ($schema->hasTable('curso_edicion_anuncios') && $schema->hasColumn('curso_edicion_anuncios', 'enlace_url')) {
            $schema->table('curso_edicion_anuncios', function (Blueprint $table) {
                $table->dropColumn('enlace_url');
            });
        }
    }
};
