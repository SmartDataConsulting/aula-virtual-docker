<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if (!$schema->hasTable('curso_edicion_sesiones')) {
            return;
        }

        $schema->table('curso_edicion_sesiones', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('curso_edicion_sesiones', 'video_chat_drive_file_id')) {
                $table->string('video_chat_drive_file_id', 255)->nullable()->after('video_uploaded_at');
            }

            if (!$schema->hasColumn('curso_edicion_sesiones', 'video_chat_titulo')) {
                $table->string('video_chat_titulo', 255)->nullable()->after('video_chat_drive_file_id');
            }

            if (!$schema->hasColumn('curso_edicion_sesiones', 'video_chat_filesize')) {
                $table->unsignedBigInteger('video_chat_filesize')->nullable()->after('video_chat_titulo');
            }

            if (!$schema->hasColumn('curso_edicion_sesiones', 'video_chat_uploaded_at')) {
                $table->timestamp('video_chat_uploaded_at')->nullable()->after('video_chat_filesize');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql_cursos');

        if (!$schema->hasTable('curso_edicion_sesiones')) {
            return;
        }

        $schema->table('curso_edicion_sesiones', function (Blueprint $table) use ($schema) {
            foreach ([
                'video_chat_uploaded_at',
                'video_chat_filesize',
                'video_chat_titulo',
                'video_chat_drive_file_id',
            ] as $column) {
                if ($schema->hasColumn('curso_edicion_sesiones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
