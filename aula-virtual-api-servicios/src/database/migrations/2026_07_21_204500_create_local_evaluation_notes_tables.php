<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion')) {
            Schema::connection('mysql_cursos')->create('evaluacion', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('curso_id')->nullable();
                $table->unsignedInteger('tipo_param_id')->default(1);
                $table->string('nombre');
                $table->unsignedInteger('tiempo_minutos')->default(30);
                $table->decimal('puntaje_aprobacion', 5, 2)->default(70);
                $table->text('descripcion')->nullable();
                $table->decimal('peso', 5, 2)->default(0);
                $table->unsignedInteger('version')->default(1);
                $table->boolean('activo')->default(true);
                $table->boolean('publicada')->default(false);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_sesion_evaluaciones')) {
            Schema::connection('mysql_cursos')->create('curso_edicion_sesion_evaluaciones', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('sesion_id');
                $table->unsignedBigInteger('evaluacion_id');
                $table->dateTime('fecha_limite')->nullable();
                $table->timestamps();

                $table->unique(['sesion_id', 'evaluacion_id'], 'sesion_evaluacion_unique');
                $table->index('evaluacion_id', 'sesion_evaluacion_eval_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_rendicion')) {
            Schema::connection('mysql_cursos')->create('evaluacion_rendicion', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('evaluacion_id');
                $table->string('alumno_correo', 190);
                $table->string('estado', 30)->default('en_progreso');
                $table->dateTime('fecha_inicio')->nullable();
                $table->dateTime('fecha_fin')->nullable();
                $table->dateTime('fecha_entrega')->nullable();
                $table->decimal('puntaje_total', 5, 2)->nullable();
                $table->boolean('aprobado')->nullable();
                $table->timestamps();

                $table->index(['evaluacion_id', 'alumno_correo', 'estado'], 'rendicion_alumno_estado_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_trabajo_rubrica')) {
            Schema::connection('mysql_cursos')->create('evaluacion_trabajo_rubrica', function (Blueprint $table) {
                $table->bigIncrements('rubrica_id');
                $table->unsignedBigInteger('evaluacion_id');
                $table->string('nombre');
                $table->unsignedInteger('orden')->default(1);
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_trabajo_rubrica_criterio')) {
            Schema::connection('mysql_cursos')->create('evaluacion_trabajo_rubrica_criterio', function (Blueprint $table) {
                $table->bigIncrements('criterio_id');
                $table->unsignedBigInteger('rubrica_id');
                $table->string('nombre');
                $table->text('descripcion')->nullable();
                $table->decimal('puntaje_max', 5, 2)->default(0);
                $table->unsignedInteger('orden')->default(1);
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_rendicion_calificacion_detalle')) {
            Schema::connection('mysql_cursos')->create('evaluacion_rendicion_calificacion_detalle', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('calificacion_id');
                $table->unsignedInteger('criterio_id');
                $table->decimal('puntaje_obtenido', 5, 2)->default(0);
                $table->text('comentario')->nullable();
                $table->timestamps();

                $table->unique(['calificacion_id', 'criterio_id'], 'calificacion_criterio_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_rendicion_calificacion_detalle');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_trabajo_rubrica_criterio');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_trabajo_rubrica');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_rendicion');
        Schema::connection('mysql_cursos')->dropIfExists('curso_edicion_sesion_evaluaciones');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion');
    }
};
