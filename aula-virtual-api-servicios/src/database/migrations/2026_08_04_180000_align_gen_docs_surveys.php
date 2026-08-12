<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONNECTION = 'mysql_cursos';

    public function up(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        if (!$schema->hasTable('encuesta_preguntas')) {
            return;
        }
        if (!$schema->hasColumn('encuesta_preguntas', 'activo')) {
            $schema->table('encuesta_preguntas', function (Blueprint $table) {
                $table->boolean('activo')->default(true)->after('requerido');
            });
        }

        $this->addIndex('encuesta_links', 'idx_el_sesion_formulario', 'INDEX idx_el_sesion_formulario (curso_edicion_sesion_id, formulario_id)');
        $this->addIndex('encuesta_respuestas', 'idx_er_sesion_formulario', 'INDEX idx_er_sesion_formulario (curso_edicion_sesion_id, formulario_id)');
        $this->addIndex('encuesta_respuestas', 'idx_er_curso_formulario', 'INDEX idx_er_curso_formulario (curso_edicion_id, formulario_id)');
    }

    public function down(): void
    {
        // La evidencia de encuestas y su estructura compartida no se eliminan en rollback.
    }

    private function addIndex(string $table, string $name, string $definition): void
    {
        if (!Schema::connection(self::CONNECTION)->hasTable($table)) {
            return;
        }
        $exists = DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $name]
        );
        if ((int) ($exists->total ?? 0) === 0) {
            DB::connection(self::CONNECTION)->statement("ALTER TABLE `$table` ADD $definition");
        }
    }
};
