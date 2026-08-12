<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_pregunta')) {
            Schema::connection('mysql_cursos')->create('evaluacion_pregunta', function (Blueprint $table) {
                $table->bigIncrements('pregunta_id');
                $table->unsignedBigInteger('evaluacion_id');
                $table->unsignedInteger('tipo_param_id')->default(1);
                $table->text('texto');
                $table->decimal('puntaje', 5, 2)->default(1);
                $table->text('feedback')->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('evaluacion_id', 'eval_pregunta_eval_idx');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('evaluacion_pregunta_opcion')) {
            Schema::connection('mysql_cursos')->create('evaluacion_pregunta_opcion', function (Blueprint $table) {
                $table->bigIncrements('opcion_id');
                $table->unsignedBigInteger('pregunta_id');
                $table->text('texto');
                $table->boolean('es_correcta')->default(false);
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('pregunta_id', 'eval_opcion_pregunta_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_pregunta_opcion');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_pregunta');
    }
};
