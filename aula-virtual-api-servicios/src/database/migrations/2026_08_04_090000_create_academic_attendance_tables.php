<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql_cursos';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('curso_edicion_sesion_asistencias')) {
            $schema->create('curso_edicion_sesion_asistencias', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('curso_edicion_sesion_id');
                $table->integer('meeting_id')->nullable();
                $table->enum('tipo_participante', ['alumno', 'docente']);
                $table->string('identity_key', 255);
                $table->string('alumno_correo', 190)->nullable();
                $table->unsignedBigInteger('colaborador_id')->nullable();
                $table->string('nombre_mostrado', 255)->nullable();
                $table->enum('estado_automatico', [
                    'pendiente', 'asistio', 'presente', 'tardanza', 'falta', 'no_aplica',
                ])->default('pendiente');
                $table->enum('estado_manual', [
                    'asistio', 'presente', 'tardanza', 'falta', 'justificada', 'no_aplica',
                ])->nullable();
                $table->dateTime('primer_click_at')->nullable();
                $table->unsignedInteger('click_count')->default(0);
                $table->dateTime('primer_ingreso_at')->nullable();
                $table->dateTime('ultima_salida_at')->nullable();
                $table->unsignedInteger('segundos_asistencia')->default(0);
                $table->decimal('porcentaje_permanencia', 6, 2)->default(0);
                $table->dateTime('zoom_verificado_at')->nullable();
                $table->dateTime('finalizado_at')->nullable();
                $table->text('motivo_manual')->nullable();
                $table->string('modificado_por_correo', 190)->nullable();
                $table->dateTime('modificado_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['curso_edicion_sesion_id', 'identity_key'],
                    'uq_asistencia_sesion_identidad'
                );
                $table->index(
                    ['curso_edicion_sesion_id', 'estado_automatico'],
                    'idx_asistencia_sesion_estado'
                );
                $table->index(['alumno_correo', 'curso_edicion_sesion_id'], 'idx_asistencia_alumno');
                $table->index(['colaborador_id', 'curso_edicion_sesion_id'], 'idx_asistencia_docente');
                $table->foreign('curso_edicion_sesion_id', 'fk_asistencia_academica_sesion')
                    ->references('id')->on('curso_edicion_sesiones')->cascadeOnDelete();
                $table->foreign('meeting_id', 'fk_asistencia_academica_meeting')
                    ->references('id')->on('meetings')->nullOnDelete();
            });
        }

        if (!$schema->hasTable('curso_edicion_sesion_asistencia_eventos')) {
            $schema->create('curso_edicion_sesion_asistencia_eventos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('asistencia_id')->nullable();
                $table->integer('meeting_id')->nullable();
                $table->string('external_event_id', 190)->nullable();
                $table->enum('fuente', ['portal_click', 'zoom_webhook', 'zoom_report', 'manual']);
                $table->enum('tipo_evento', ['click', 'join', 'leave', 'snapshot', 'override']);
                $table->dateTime('ocurrido_at');
                $table->string('zoom_participant_id', 190)->nullable();
                $table->string('participante_correo', 190)->nullable();
                $table->string('participante_nombre', 255)->nullable();
                $table->unsignedInteger('duracion_segundos')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('external_event_id', 'uq_asistencia_evento_externo');
                $table->index(['meeting_id', 'ocurrido_at'], 'idx_asistencia_evento_meeting');
                $table->index(['asistencia_id', 'ocurrido_at'], 'idx_asistencia_evento_resumen');
                $table->foreign('asistencia_id', 'fk_asistencia_evento_resumen')
                    ->references('id')->on('curso_edicion_sesion_asistencias')->nullOnDelete();
                $table->foreign('meeting_id', 'fk_asistencia_evento_meeting')
                    ->references('id')->on('meetings')->nullOnDelete();
            });
        }

        if (!$schema->hasTable('zoom_participant_identities')) {
            $schema->create('zoom_participant_identities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('zoom_participant_id', 190);
                $table->string('identity_key', 255);
                $table->enum('tipo_participante', ['alumno', 'docente']);
                $table->string('correo', 190)->nullable();
                $table->enum('fuente', ['correo_exacto', 'manual']);
                $table->string('creado_por_correo', 190)->nullable();
                $table->timestamps();
                $table->unique('zoom_participant_id', 'uq_zoom_participant_identity');
                $table->index('identity_key', 'idx_zoom_identity_key');
            });
        }

        if (!$schema->hasTable('meeting_attendance_syncs')) {
            $schema->create('meeting_attendance_syncs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('meeting_id');
                $table->string('zoom_uuid', 255)->nullable();
                $table->enum('estado', ['pendiente', 'procesando', 'completado', 'error'])
                    ->default('pendiente');
                $table->unsignedTinyInteger('intentos')->default(0);
                $table->string('ultimo_error_codigo', 100)->nullable();
                $table->dateTime('proximo_intento_at')->nullable();
                $table->dateTime('sincronizado_at')->nullable();
                $table->timestamps();
                $table->unique('meeting_id', 'uq_meeting_attendance_sync');
                $table->index(['estado', 'proximo_intento_at'], 'idx_meeting_sync_pending');
                $table->foreign('meeting_id', 'fk_meeting_attendance_sync')
                    ->references('id')->on('meetings')->cascadeOnDelete();
            });
        }

        if ($schema->hasColumn('curso_edicion_sesiones', 'asistencia_docente')) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE curso_edicion_sesiones MODIFY asistencia_docente ENUM('pendiente','presente','tardanza','falta','justificada','no_aplica') NOT NULL DEFAULT 'pendiente'"
            );
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('meeting_attendance_syncs');
        $schema->dropIfExists('zoom_participant_identities');
        $schema->dropIfExists('curso_edicion_sesion_asistencia_eventos');
        $schema->dropIfExists('curso_edicion_sesion_asistencias');
    }
};
