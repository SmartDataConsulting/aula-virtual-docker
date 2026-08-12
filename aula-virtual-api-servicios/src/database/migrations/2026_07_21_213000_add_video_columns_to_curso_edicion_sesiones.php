<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_sesiones')) {
            return;
        }

        Schema::connection('mysql_cursos')->table('curso_edicion_sesiones', function (Blueprint $table) {
            if (!Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', 'video_drive_file_id')) {
                $table->string('video_drive_file_id', 255)->nullable()->after('estado_sesion');
            }

            if (!Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', 'video_titulo')) {
                $table->string('video_titulo', 255)->nullable()->after('video_drive_file_id');
            }

            if (!Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', 'video_status')) {
                $table->string('video_status', 50)->nullable()->after('video_titulo');
            }

            if (!Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', 'video_filesize')) {
                $table->unsignedBigInteger('video_filesize')->nullable()->after('video_status');
            }

            if (!Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', 'video_uploaded_at')) {
                $table->timestamp('video_uploaded_at')->nullable()->after('video_filesize');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('mysql_cursos')->hasTable('curso_edicion_sesiones')) {
            return;
        }

        Schema::connection('mysql_cursos')->table('curso_edicion_sesiones', function (Blueprint $table) {
            foreach ([
                'video_uploaded_at',
                'video_filesize',
                'video_status',
                'video_titulo',
                'video_drive_file_id',
            ] as $column) {
                if (Schema::connection('mysql_cursos')->hasColumn('curso_edicion_sesiones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
