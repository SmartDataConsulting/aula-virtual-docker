<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql_cursos');
        if ($schema->hasTable('aula_audit_logs')) {
            return;
        }

        $schema->create('aula_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('actor_name', 255)->nullable();
            $table->string('actor_email', 255)->nullable()->index();
            $table->string('actor_role', 50)->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('entity_type', 100)->index();
            $table->string('entity_id', 100)->nullable();
            $table->unsignedBigInteger('curso_edicion_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_cursos')->dropIfExists('aula_audit_logs');
    }
};
