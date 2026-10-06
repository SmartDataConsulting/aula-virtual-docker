<?php

namespace Tests\Unit;

use App\Services\AuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditTableIsolationTest extends TestCase
{
    private array $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = config('database.connections.mysql_cursos');
        config(['database.connections.mysql_cursos' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('mysql_cursos');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_cursos');
        config(['database.connections.mysql_cursos' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_new_migration_and_service_leave_populated_legacy_audit_table_intact(): void
    {
        $schema = Schema::connection('mysql_cursos');
        $schema->create('audit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('table_name', 100);
            $table->integer('record_id');
            $table->string('action', 50);
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
            $table->string('changed_by', 255);
            $table->timestamp('changed_at')->useCurrent();
        });
        DB::connection('mysql_cursos')->table('audit_logs')->insert([
            [
                'table_name' => 'legacy_courses',
                'record_id' => 10,
                'action' => 'UPDATE',
                'old_data' => '{"name":"before"}',
                'new_data' => '{"name":"after"}',
                'changed_by' => 'legacy-user',
            ],
            [
                'table_name' => 'legacy_students',
                'record_id' => 20,
                'action' => 'INSERT',
                'old_data' => null,
                'new_data' => '{"status":"active"}',
                'changed_by' => 'legacy-user',
            ],
        ]);

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_05_100000_create_aula_audit_logs_table.php';
        $migration->up();

        self::assertTrue($schema->hasTable('audit_logs'));
        self::assertTrue($schema->hasTable('aula_audit_logs'));
        self::assertSame(2, DB::connection('mysql_cursos')->table('audit_logs')->count());
        self::assertSame('legacy-user', DB::connection('mysql_cursos')->table('audit_logs')->orderBy('id')->value('changed_by'));

        $request = Request::create('/anuncios/5', 'PUT', [], [], [], [
            'HTTP_X_USER_NAME' => 'Aula Teacher',
            'HTTP_X_USER_EMAIL' => 'teacher@example.test',
            'HTTP_X_USER_ROL' => 'docente',
            'HTTP_X_CORRELATION_ID' => 'audit-isolation-test',
        ]);
        (new AuditService())->record(
            $request,
            [
                'action' => 'announcement.updated',
                'entity_type' => 'announcement',
                'entity_id' => 5,
                'curso_edicion_id' => 3,
                'session_id' => null,
            ],
            ['id' => 5, 'titulo' => 'Anterior'],
            ['id' => 5, 'titulo' => 'Nuevo']
        );

        self::assertSame(2, DB::connection('mysql_cursos')->table('audit_logs')->count());
        self::assertSame(1, DB::connection('mysql_cursos')->table('aula_audit_logs')->count());
        self::assertSame(
            'announcement.updated',
            DB::connection('mysql_cursos')->table('aula_audit_logs')->value('action')
        );
    }
}
