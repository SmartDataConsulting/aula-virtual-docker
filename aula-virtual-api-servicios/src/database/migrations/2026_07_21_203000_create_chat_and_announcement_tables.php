<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('sala_chat')) {
            Schema::connection('mysql_cursos')->create('sala_chat', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('tipo_contexto', 40);
            $table->string('id_contexto', 80);
            $table->string('titulo')->nullable();
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['tipo_contexto', 'id_contexto'], 'sala_chat_contexto_unique');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('mensaje_chat')) {
            Schema::connection('mysql_cursos')->create('mensaje_chat', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('sala_id', 36);
            $table->char('mensaje_padre_id', 36)->nullable();
            $table->string('usuario_id', 190);
            $table->string('nombre_usuario', 190);
            $table->string('rol_usuario', 40)->nullable();
            $table->text('mensaje');
            $table->boolean('eliminado')->default(false);
            $table->boolean('fijado')->default(false);
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();

            $table->index(['sala_id', 'eliminado', 'fecha_creacion'], 'mensaje_chat_sala_idx');
            $table->index('mensaje_padre_id', 'mensaje_chat_padre_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_anuncios')) {
            Schema::connection('mysql_cursos')->create('curso_edicion_anuncios', function (Blueprint $table) {
            $table->increments('id');
            $table->string('entidad_tipo', 40);
            $table->unsignedInteger('entidad_id');
            $table->string('titulo');
            $table->text('contenido');
            $table->string('tipo', 40)->default('general');
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('creado_por')->nullable();
            $table->unsignedInteger('editado_por')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->nullable();
            $table->timestamp('editado_en')->nullable();

            $table->index(['entidad_tipo', 'entidad_id', 'activo'], 'curso_anuncios_entidad_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_anuncio_lecturas')) {
            Schema::connection('mysql_cursos')->create('curso_edicion_anuncio_lecturas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('anuncio_id');
            $table->string('alumno_correo', 190);
            $table->timestamp('leido_en')->useCurrent();

            $table->unique(['anuncio_id', 'alumno_correo'], 'anuncio_lecturas_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('curso_edicion_anuncio_lecturas');
        Schema::connection('mysql_cursos')->dropIfExists('curso_edicion_anuncios');
        Schema::connection('mysql_cursos')->dropIfExists('mensaje_chat');
        Schema::connection('mysql_cursos')->dropIfExists('sala_chat');
    }
};
