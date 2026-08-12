<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('mysql_cursos')->hasTable('curso_edicion_sesion_video_upload')) {
            return;
        }

        Schema::connection('mysql_cursos')->create('curso_edicion_sesion_video_upload', function (Blueprint $table) {
            $table->bigIncrements('curso_edicion_sesion_video_upload_id');
            $table->unsignedBigInteger('curso_edicion_sesion_id');
            $table->text('upload_url')->nullable();
            $table->string('filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedBigInteger('bytes_uploaded')->default(0);
            $table->string('drive_file_id', 255)->nullable();
            $table->string('status', 50)->default('uploading');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['curso_edicion_sesion_id', 'status'], 'idx_video_upload_sesion_status');
            $table->index('drive_file_id', 'idx_video_upload_drive_file');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('curso_edicion_sesion_video_upload');
    }
};
