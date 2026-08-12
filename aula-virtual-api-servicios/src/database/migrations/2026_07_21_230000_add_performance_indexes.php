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
        $this->addIndexIfPossible('usuario', ['email', 'colaborador_id'], 'idx_usuario_email_colaborador');
        $this->addIndexIfPossible('curso_edicion', ['docente_id_colaborador', 'activo', 'estadocurso'], 'idx_curso_docente_activo_estado');
        $this->addIndexIfPossible('curso_edicion_sesiones', ['curso_edicion_id', 'fecha', 'estado_sesion'], 'idx_sesiones_curso_fecha_estado');
        $this->addIndexIfPossible('evaluacion', ['curso_id', 'activo', 'tipo_param_id'], 'idx_evaluacion_curso_activo_tipo');
        $this->addIndexIfPossible('evaluacion_rendicion', ['evaluacion_id', 'alumno_correo', 'estado'], 'idx_eval_rendicion_eval_correo_estado_perf');
        $this->addIndexIfPossible('evaluacion_trabajo_entrega', ['evaluacion_id', 'alumno_correo_normalizado', 'estado'], 'idx_eval_entrega_eval_alumno_estado');
        $this->addIndexIfPossible('evaluacion_trabajo_entrega', ['evaluacion_id', 'alumno_correo', 'estado'], 'idx_eval_entrega_eval_correo_estado');
        $this->addIndexIfPossible('curso_edicion_sesion_materiales', ['curso_edicion_sesion_id', 'activo', 'orden'], 'idx_materiales_sesion_activo_orden_perf');
        $this->addIndexIfPossible('alumno_certificado', ['curso_edicion_id', 'estado'], 'idx_alumno_certificado_curso_estado_perf');
        $this->addIndexIfPossible('encuesta_respuesta', ['curso_edicion_sesion_id', 'alumno_hash'], 'idx_encuesta_respuesta_sesion_alumno_perf');
        $this->addIndexIfPossible('encuesta_respuesta', ['encuesta_id', 'alumno_hash'], 'idx_encuesta_respuesta_encuesta_alumno_perf');
        $this->addIndexIfPossible('encuesta_links', ['curso_edicion_id', 'curso_edicion_sesion_id', 'activo'], 'idx_encuesta_links_curso_sesion_activo');
        $this->addIndexIfPossible('encuesta_links', ['curso_edicion_id', 'activo'], 'idx_encuesta_links_curso_activo');
        $this->addIndexIfPossible('encuesta_respuestas', ['link_id', 'correo_normalizado', 'submission_uuid'], 'idx_encuesta_respuestas_link_correo_uuid');
        $this->addIndexIfPossible('encuesta_respuestas', ['curso_edicion_id', 'curso_edicion_sesion_id'], 'idx_encuesta_respuestas_curso_sesion');
        $this->addIndexIfPossible('encuesta_respuesta_detalles', ['respuesta_id', 'pregunta_id'], 'idx_encuesta_detalles_respuesta_pregunta');
        $this->addIndexIfPossible('curso_edicion_sesion_asistencias', ['curso_edicion_sesion_id', 'estado_final'], 'idx_asistencias_sesion_estado');
        $this->addIndexIfPossible('curso_edicion_sesion_asistencias', ['curso_edicion_sesion_id', 'identity_key'], 'idx_asistencias_sesion_identity_perf');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('curso_edicion_sesion_asistencias', 'idx_asistencias_sesion_identity_perf');
        $this->dropIndexIfExists('curso_edicion_sesion_asistencias', 'idx_asistencias_sesion_estado');
        $this->dropIndexIfExists('encuesta_respuesta_detalles', 'idx_encuesta_detalles_respuesta_pregunta');
        $this->dropIndexIfExists('encuesta_respuestas', 'idx_encuesta_respuestas_curso_sesion');
        $this->dropIndexIfExists('encuesta_respuestas', 'idx_encuesta_respuestas_link_correo_uuid');
        $this->dropIndexIfExists('encuesta_links', 'idx_encuesta_links_curso_activo');
        $this->dropIndexIfExists('encuesta_links', 'idx_encuesta_links_curso_sesion_activo');
        $this->dropIndexIfExists('encuesta_respuesta', 'idx_encuesta_respuesta_encuesta_alumno_perf');
        $this->dropIndexIfExists('encuesta_respuesta', 'idx_encuesta_respuesta_sesion_alumno_perf');
        $this->dropIndexIfExists('alumno_certificado', 'idx_alumno_certificado_curso_estado_perf');
        $this->dropIndexIfExists('curso_edicion_sesion_materiales', 'idx_materiales_sesion_activo_orden_perf');
        $this->dropIndexIfExists('evaluacion_trabajo_entrega', 'idx_eval_entrega_eval_correo_estado');
        $this->dropIndexIfExists('evaluacion_trabajo_entrega', 'idx_eval_entrega_eval_alumno_estado');
        $this->dropIndexIfExists('evaluacion_rendicion', 'idx_eval_rendicion_eval_correo_estado_perf');
        $this->dropIndexIfExists('evaluacion', 'idx_evaluacion_curso_activo_tipo');
        $this->dropIndexIfExists('curso_edicion_sesiones', 'idx_sesiones_curso_fecha_estado');
        $this->dropIndexIfExists('curso_edicion', 'idx_curso_docente_activo_estado');
        $this->dropIndexIfExists('usuario', 'idx_usuario_email_colaborador');
    }

    private function addIndexIfPossible(string $table, array $columns, string $indexName): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (!$schema->hasColumn($table, $column)) {
                return;
            }
        }

        $schema->table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT COUNT(*) AS total
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$table, $indexName]
        );

        return (int) ($rows[0]->total ?? 0) > 0;
    }
};
