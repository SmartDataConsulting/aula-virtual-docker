<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_sesion_materiales')) {
            Schema::connection('mysql_cursos')->create('curso_edicion_sesion_materiales', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('curso_edicion_sesion_id');
                $table->string('titulo');
                $table->text('descripcion')->nullable();
                $table->string('tipo', 40);
                $table->string('nombre_archivo')->nullable();
                $table->string('ruta_archivo', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('tamano_bytes')->nullable();
                $table->string('url_externa', 500)->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('subido_por')->nullable();
                $table->timestamps();

                $table->index(['curso_edicion_sesion_id', 'activo', 'orden'], 'sesion_materiales_sesion_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('curso_edicion_sesion_materiales');
    }
};
