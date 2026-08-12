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

        if (!$schema->hasTable('meetings')) {
            $schema->create('meetings', function (Blueprint $table) {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
                $table->increments('id');
                $table->integer('user_id');
                $table->string('title');
                $table->dateTime('date');
                $table->string('host_zoom', 100);
                $table->text('emails');
                $table->integer('duration');
                $table->string('zoom_meeting_id')->nullable();
                $table->string('calendar_event_id')->nullable();
                $table->enum('status', ['activo', 'eliminado', 'postergado'])->default('activo');
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->integer('sesion')->nullable();
                $table->string('edicion')->nullable();
                $table->text('url')->nullable();
                $table->string('id_reunion', 100)->nullable();
                $table->string('codigo_acceso', 100)->nullable();
                $table->string('recurrence_days', 50)->nullable();
                $table->string('recurrence_group', 100)->nullable();
                $table->string('survey_url', 1000)->nullable();

                $table->index('user_id', 'user_id');
                $table->index(['status', 'date'], 'idx_meetings_status_created');
                $table->index(
                    ['status', 'edicion', 'sesion', 'date', 'host_zoom'],
                    'idx_meetings_session_lookup'
                );
            });

            if ($schema->hasTable('users')) {
                $schema->table('meetings', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            }

            return;
        }

        $columns = [
            'sesion' => fn (Blueprint $table) => $table->integer('sesion')->nullable(),
            'edicion' => fn (Blueprint $table) => $table->string('edicion')->nullable(),
            'url' => fn (Blueprint $table) => $table->text('url')->nullable(),
            'id_reunion' => fn (Blueprint $table) => $table->string('id_reunion', 100)->nullable(),
            'codigo_acceso' => fn (Blueprint $table) => $table->string('codigo_acceso', 100)->nullable(),
            'recurrence_days' => fn (Blueprint $table) => $table->string('recurrence_days', 50)->nullable(),
            'recurrence_group' => fn (Blueprint $table) => $table->string('recurrence_group', 100)->nullable(),
            'survey_url' => fn (Blueprint $table) => $table->string('survey_url', 1000)->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (!$schema->hasColumn('meetings', $column)) {
                $schema->table('meetings', $definition);
            }
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE meetings MODIFY status ENUM('activo','eliminado','postergado') DEFAULT 'activo'"
        );
        DB::connection($this->connection)->statement(
            'ALTER TABLE meetings MODIFY created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
        );

        $this->addIndexIfMissing(['status', 'date'], 'idx_meetings_status_created');
        $this->addIndexIfMissing(
            ['status', 'edicion', 'sesion', 'date', 'host_zoom'],
            'idx_meetings_session_lookup'
        );
    }

    public function down(): void
    {
        // Compatibility migration: rolling back must not discard production meeting data.
        if ($this->indexExists('idx_meetings_session_lookup')) {
            Schema::connection($this->connection)->table('meetings', function (Blueprint $table) {
                $table->dropIndex('idx_meetings_session_lookup');
            });
        }
    }

    private function addIndexIfMissing(array $columns, string $name): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        Schema::connection($this->connection)->table('meetings', function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    private function indexExists(string $name): bool
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['meetings', $name]
        );

        return (int) ($rows[0]->total ?? 0) > 0;
    }
};
