<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if ($schema->hasTable('evaluacion_rendicion_respuesta')) {
            return;
        }

        $schema->create('evaluacion_rendicion_respuesta', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rendicion_id');
            $table->unsignedBigInteger('pregunta_id');
            $table->unsignedBigInteger('opcion_id')->nullable();
            $table->boolean('es_correcta')->nullable();
            $table->decimal('puntaje_obtenido', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['rendicion_id', 'pregunta_id'],
                'rendicion_respuesta_unique'
            );
            $table->index('pregunta_id', 'rendicion_respuesta_pregunta_idx');
            $table->index('opcion_id', 'rendicion_respuesta_opcion_idx');
        });
    }

    public function down(): void
    {
        // Deliberately preserved: this table stores submitted student answers.
    }
};
