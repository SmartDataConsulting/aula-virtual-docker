<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONNECTION = 'mysql_cursos';

    public function up(): void
    {
        if (!Schema::connection(self::CONNECTION)->hasTable('encuesta_respuesta')) {
            return;
        }

        Schema::connection(self::CONNECTION)->table('encuesta_respuesta', function (Blueprint $table) {
            if (!Schema::connection(self::CONNECTION)->hasColumn('encuesta_respuesta', 'scope_type')) {
                $table->string('scope_type', 16)->nullable()->after('curso_edicion_sesion_id');
            }
            if (!Schema::connection(self::CONNECTION)->hasColumn('encuesta_respuesta', 'scope_id')) {
                $table->unsignedInteger('scope_id')->nullable()->after('scope_type');
            }
        });

        if (Schema::connection(self::CONNECTION)->hasTable('encuesta_respuesta_detalle')) {
            Schema::connection(self::CONNECTION)->table('encuesta_respuesta_detalle', function (Blueprint $table) {
                if (!Schema::connection(self::CONNECTION)->hasColumn('encuesta_respuesta_detalle', 'opcion_id')) {
                    $table->unsignedInteger('opcion_id')->nullable()->after('valor_escala');
                }
            });
        }

        DB::connection(self::CONNECTION)->statement("
            UPDATE encuesta_respuesta er
            INNER JOIN encuesta e ON e.id = er.encuesta_id
            LEFT JOIN curso_edicion_sesiones ces ON ces.id = er.curso_edicion_sesion_id
            SET er.scope_type = CASE WHEN e.tipo = 2 THEN 'course' ELSE 'session' END,
                er.scope_id = CASE WHEN e.tipo = 2 THEN ces.curso_edicion_id ELSE er.curso_edicion_sesion_id END
            WHERE er.scope_type IS NULL OR er.scope_id IS NULL
        ");

        $duplicates = DB::connection(self::CONNECTION)->selectOne("
            SELECT COUNT(*) AS total
            FROM (
                SELECT encuesta_id, scope_type, scope_id, alumno_hash
                FROM encuesta_respuesta
                WHERE scope_type IS NOT NULL AND scope_id IS NOT NULL
                GROUP BY encuesta_id, scope_type, scope_id, alumno_hash
                HAVING COUNT(*) > 1
            ) duplicate_groups
        ");

        if ((int) ($duplicates->total ?? 0) === 0) {
            $this->addIndexIfMissing(
                'encuesta_respuesta',
                'encuesta_respuesta_scope_unique',
                'UNIQUE INDEX encuesta_respuesta_scope_unique (encuesta_id, scope_type, scope_id, alumno_hash)'
            );
        } else {
            Log::warning('survey_scope_unique_index_skipped', [
                'duplicate_groups' => (int) $duplicates->total,
            ]);
            $this->addIndexIfMissing(
                'encuesta_respuesta',
                'encuesta_respuesta_scope_idx',
                'INDEX encuesta_respuesta_scope_idx (encuesta_id, scope_type, scope_id, alumno_hash)'
            );
        }

        if (Schema::connection(self::CONNECTION)->hasTable('encuesta_respuesta_detalle')) {
            $this->addIndexIfMissing(
                'encuesta_respuesta_detalle',
                'encuesta_respuesta_opcion_idx',
                'INDEX encuesta_respuesta_opcion_idx (opcion_id)'
            );
        }
    }

    public function down(): void
    {
        // Incremental production alignment: survey evidence is intentionally preserved.
    }

    private function addIndexIfMissing(string $table, string $name, string $definition): void
    {
        $exists = DB::connection(self::CONNECTION)->selectOne("
            SELECT COUNT(*) AS total
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ", [$table, $name]);

        if ((int) ($exists->total ?? 0) === 0) {
            DB::connection(self::CONNECTION)->statement("ALTER TABLE {$table} ADD {$definition}");
        }
    }
};
