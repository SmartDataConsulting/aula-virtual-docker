<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('alumno')) {
            Schema::connection('mysql_cursos')->create('alumno', function (Blueprint $table) {
                $table->id();
                $table->string('correo', 255)->unique();
                $table->string('correo_corporativo', 255)->nullable();
                $table->string('nombres', 255);
                $table->string('apellidos', 255);
                $table->string('telefono', 50)->nullable();
                $table->date('fecha_nacimiento')->nullable();
                $table->string('foto_url', 500)->nullable();
                $table->text('presentacion_profesional')->nullable();
                $table->string('cv_url', 500)->nullable();
                $table->string('linkedin_url', 500)->nullable();
                $table->boolean('contacto_publico')->default(false);
                $table->boolean('permite_solicitudes_contacto')->default(true);
                $table->dateTime('fecha_creacion')->nullable();
                $table->dateTime('fecha_actualizacion')->nullable();

                $table->index('correo', 'idx_alumno_correo');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('solicitud_contacto')) {
            Schema::connection('mysql_cursos')->create('solicitud_contacto', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('curso_edicion_id', 50);
                $table->string('solicitante_correo', 255);
                $table->string('destinatario_correo', 255);
                $table->text('mensaje')->nullable();
                $table->string('estado', 20)->default('PENDIENTE');
                $table->dateTime('fecha_solicitud')->nullable();
                $table->dateTime('fecha_respuesta')->nullable();

                $table->index(['curso_edicion_id', 'solicitante_correo', 'destinatario_correo'], 'idx_sol_contacto_lookup');
                $table->index(['destinatario_correo', 'estado'], 'idx_sol_contacto_recibidas');
                $table->index(['solicitante_correo', 'estado'], 'idx_sol_contacto_enviadas');
            });
        }

        if (!Schema::connection('mysql_cursos')->hasTable('alumno_certificado')) {
            Schema::connection('mysql_cursos')->create('alumno_certificado', function (Blueprint $table) {
                $table->id();
                $table->string('alumno_correo', 255);
                $table->unsignedBigInteger('curso_edicion_id');
                $table->string('archivo_nombre', 255)->nullable();
                $table->string('archivo_ruta', 500)->nullable();
                $table->string('archivo_mime', 150)->nullable();
                $table->unsignedBigInteger('archivo_peso')->nullable();
                $table->string('token', 100)->unique();
                $table->string('link_publico', 700)->nullable();
                $table->string('estado', 30)->default('pendiente');
                $table->string('usuario_adjunta', 255)->nullable();
                $table->dateTime('fecha_adjunta')->nullable();
                $table->string('usuario_envia', 255)->nullable();
                $table->dateTime('fecha_envia')->nullable();
                $table->dateTime('fecha_creacion')->nullable();
                $table->dateTime('fecha_actualizacion')->nullable();

                $table->unique(['alumno_correo', 'curso_edicion_id'], 'uq_alumno_certificado_curso');
                $table->index(['curso_edicion_id', 'estado'], 'idx_alumno_certificado_curso_estado');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('alumno_certificado');
        Schema::connection('mysql_cursos')->dropIfExists('solicitud_contacto');
        Schema::connection('mysql_cursos')->dropIfExists('alumno');
    }
};
