<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if ($schema->hasTable('evaluacion_intento_respuesta')) {
            DB::connection('mysql_cursos')->statement(
                'RENAME TABLE evaluacion_intento_respuesta TO evaluacion_rendicion_respuesta'
            );
        }

        if ($schema->hasTable('evaluacion_intento')) {
            DB::connection('mysql_cursos')->statement(
                'RENAME TABLE evaluacion_intento TO evaluacion_rendicion'
            );
        }

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion',
            'intento_id',
            'id'
        );

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion_respuesta',
            'respuesta_id',
            'id'
        );

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion_respuesta',
            'intento_id',
            'rendicion_id'
        );
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql_cursos');

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion_respuesta',
            'rendicion_id',
            'intento_id'
        );

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion_respuesta',
            'id',
            'respuesta_id'
        );

        $this->renameColumnPreservingDefinition(
            'evaluacion_rendicion',
            'id',
            'intento_id'
        );

        if ($schema->hasTable('evaluacion_rendicion_respuesta')) {
            DB::connection('mysql_cursos')->statement(
                'RENAME TABLE evaluacion_rendicion_respuesta TO evaluacion_intento_respuesta'
            );
        }

        if ($schema->hasTable('evaluacion_rendicion')) {
            DB::connection('mysql_cursos')->statement(
                'RENAME TABLE evaluacion_rendicion TO evaluacion_intento'
            );
        }
    }

    private function renameColumnPreservingDefinition(
        string $table,
        string $from,
        string $to
    ): void {
        $schema = Schema::connection('mysql_cursos');

        if (
            !$schema->hasTable($table)
            || !$schema->hasColumn($table, $from)
            || $schema->hasColumn($table, $to)
        ) {
            return;
        }

        $conn = DB::connection('mysql_cursos');
        $column = $conn->selectOne("SHOW COLUMNS FROM `{$table}` LIKE ?", [$from]);

        if (!$column) {
            return;
        }

        $definition = $this->buildColumnDefinition($conn, $column);

        $conn->statement(
            "ALTER TABLE `{$table}` CHANGE COLUMN `{$from}` `{$to}` {$definition}"
        );
    }

    private function buildColumnDefinition($conn, object $column): string
    {
        $definition = $column->Type;
        $definition .= $column->Null === 'YES' ? ' NULL' : ' NOT NULL';

        if ($column->Default !== null) {
            $default = (string) $column->Default;

            if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                $definition .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $definition .= ' DEFAULT ' . $conn->getPdo()->quote($default);
            }
        } elseif ($column->Null === 'YES') {
            $definition .= ' DEFAULT NULL';
        }

        if (!empty($column->Extra)) {
            $definition .= ' ' . $column->Extra;
        }

        return $definition;
    }
};
