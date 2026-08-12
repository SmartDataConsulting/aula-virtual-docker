<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if (!$schema->hasTable('evaluacion_rendicion_calificacion')) {
            $schema->create('evaluacion_rendicion_calificacion', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rendicion_id');
                $table->unsignedInteger('usuario_id');
                $table->decimal('puntaje_total', 5, 2)->default(0);
                $table->decimal('porcentaje', 5, 2)->nullable();
                $table->boolean('aprobado')->nullable();
                $table->text('observacion_docente')->nullable();
                $table->dateTime('fecha_correccion')->nullable();
                $table->timestamps();

                $table->unique(['rendicion_id'], 'uq_calificacion_rendicion');
                $table->index(['usuario_id'], 'idx_calificacion_usuario');

                $table->foreign('rendicion_id', 'fk_calificacion_rendicion')
                    ->references('id')
                    ->on('evaluacion_rendicion')
                    ->cascadeOnDelete();

                $table->foreign('usuario_id', 'fk_calificacion_usuario')
                    ->references('id')
                    ->on('usuario');
            });
        }

        if (!$schema->hasTable('evaluacion_rendicion_calificacion_detalle')) {
            $schema->create('evaluacion_rendicion_calificacion_detalle', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('calificacion_id');
                $table->unsignedInteger('criterio_id');
                $table->decimal('puntaje_obtenido', 5, 2)->default(0);
                $table->text('comentario')->nullable();
                $table->timestamps();

                $table->unique(['calificacion_id', 'criterio_id'], 'uq_calificacion_criterio');

                $table->foreign('calificacion_id', 'fk_detalle_calificacion')
                    ->references('id')
                    ->on('evaluacion_rendicion_calificacion')
                    ->cascadeOnDelete();

                $table->foreign('criterio_id', 'fk_detalle_criterio')
                    ->references('criterio_id')
                    ->on('evaluacion_trabajo_rubrica_criterio')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_rendicion_calificacion_detalle');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_rendicion_calificacion');
    }
};
