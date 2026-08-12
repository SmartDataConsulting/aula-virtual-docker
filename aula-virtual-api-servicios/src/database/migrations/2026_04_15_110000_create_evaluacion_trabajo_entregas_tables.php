<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mysql_cursos')->create('evaluacion_trabajo_entrega', function (Blueprint $table) {
            $table->bigIncrements('entrega_id');
            $table->unsignedBigInteger('evaluacion_id');
            $table->string('alumno_correo', 255);
            $table->string('estado', 20)->default('borrador');
            $table->dateTime('fecha_entrega')->nullable();
            $table->text('observacion_alumno')->nullable();
            $table->timestamps();

            $table->unique(['evaluacion_id', 'alumno_correo'], 'uq_eval_trabajo_entrega_alumno');
            $table->index(['alumno_correo'], 'idx_eval_trabajo_entrega_correo');
        });

        Schema::connection('mysql_cursos')->create('evaluacion_rendicion_trabajo', function (Blueprint $table) {
            $table->bigIncrements('archivo_id');
            $table->unsignedBigInteger('rendicion_id');
            $table->string('nombre_original', 255);
            $table->string('ruta_archivo', 500);
            $table->unsignedBigInteger('peso_bytes')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['rendicion_id', 'activo'], 'idx_eval_rendicion_trabajo_activo');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_rendicion_trabajo');
        Schema::connection('mysql_cursos')->dropIfExists('evaluacion_trabajo_entrega');
    }
};
