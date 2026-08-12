<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('encuesta')) {
            Schema::connection('mysql_cursos')->create('encuesta', function (Blueprint $table) {
                $table->increments('id');
                $table->string('nombre');
                $table->unsignedInteger('tipo')->default(1);
                $table->boolean('activa')->default(true);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('encuesta_escala')) {
            Schema::connection('mysql_cursos')->create('encuesta_escala', function (Blueprint $table) {
                $table->increments('id');
                $table->string('nombre');
                $table->integer('min_valor')->default(1);
                $table->integer('max_valor')->default(5);
                $table->string('label_min')->nullable();
                $table->string('label_max')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('encuesta_pregunta')) {
            Schema::connection('mysql_cursos')->create('encuesta_pregunta', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('encuesta_id');
                $table->string('pregunta');
                $table->string('factor_evaluado', 80)->nullable();
                $table->unsignedInteger('tipo_respuesta')->default(1);
                $table->unsignedInteger('escala_id')->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->boolean('obligatoria')->default(true);
                $table->timestamps();

                $table->index(['encuesta_id', 'orden'], 'encuesta_pregunta_orden_idx');
            });
        } elseif (!Schema::connection('mysql_cursos')->hasColumn('encuesta_pregunta', 'factor_evaluado')) {
            Schema::connection('mysql_cursos')->table('encuesta_pregunta', function (Blueprint $table) {
                $table->string('factor_evaluado', 80)->nullable()->after('pregunta');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('encuesta_pregunta_opcion')) {
            Schema::connection('mysql_cursos')->create('encuesta_pregunta_opcion', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('pregunta_id');
                $table->string('valor', 80);
                $table->string('texto');
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index(['pregunta_id', 'orden'], 'encuesta_opcion_orden_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('encuesta_respuesta')) {
            Schema::connection('mysql_cursos')->create('encuesta_respuesta', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('encuesta_id');
                $table->unsignedInteger('curso_edicion_sesion_id')->nullable();
                $table->string('alumno_hash', 190);
                $table->timestamp('respondido_at')->nullable();
                $table->timestamps();

                $table->index(['curso_edicion_sesion_id', 'alumno_hash'], 'encuesta_respuesta_sesion_alumno_idx');
                $table->index(['encuesta_id', 'alumno_hash'], 'encuesta_respuesta_encuesta_alumno_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('encuesta_respuesta_detalle')) {
            Schema::connection('mysql_cursos')->create('encuesta_respuesta_detalle', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('respuesta_id');
                $table->unsignedInteger('pregunta_id');
                $table->integer('valor_escala')->nullable();
                $table->text('texto_respuesta')->nullable();
                $table->timestamps();

                $table->unique(['respuesta_id', 'pregunta_id'], 'encuesta_respuesta_detalle_unique');
                $table->index('pregunta_id', 'encuesta_respuesta_detalle_pregunta_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('encuesta_respuesta_detalle');
        Schema::connection('mysql_cursos')->dropIfExists('encuesta_respuesta');
        Schema::connection('mysql_cursos')->dropIfExists('encuesta_pregunta_opcion');
        Schema::connection('mysql_cursos')->dropIfExists('encuesta_pregunta');
        Schema::connection('mysql_cursos')->dropIfExists('encuesta_escala');
        Schema::connection('mysql_cursos')->dropIfExists('encuesta');
    }
};
