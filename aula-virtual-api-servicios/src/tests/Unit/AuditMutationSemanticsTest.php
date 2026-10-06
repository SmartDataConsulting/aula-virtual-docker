<?php

namespace Tests\Unit;

use App\Http\Middleware\AuditMutation;
use App\Services\AuditService;
use App\Services\AuditSnapshotService;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditMutationSemanticsTest extends TestCase
{
    public function test_create_records_null_before_and_persisted_after_snapshot(): void
    {
        $recorder = new RecordingAuditService();
        $snapshots = new FixedAuditSnapshotService(null, ['id' => 81, 'titulo' => 'Nuevo']);
        $middleware = new AuditMutation($recorder, $snapshots);
        $request = Request::create('/sesiones/10/materiales', 'POST');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true, 'id' => 81], 201));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $recorder->calls);
        self::assertSame('material.created', $recorder->calls[0]['event']['action']);
        self::assertNull($recorder->calls[0]['before']);
        self::assertSame(['id' => 81, 'titulo' => 'Nuevo'], $recorder->calls[0]['after']);
    }

    public function test_update_records_previous_and_new_values(): void
    {
        $recorder = new RecordingAuditService();
        $snapshots = new FixedAuditSnapshotService(
            ['id' => 81, 'titulo' => 'Anterior'],
            ['id' => 81, 'titulo' => 'Nuevo']
        );
        $middleware = new AuditMutation($recorder, $snapshots);
        $request = Request::create('/sesiones/10/materiales/81', 'PUT');

        $middleware->handle($request, fn () => response()->json(['ok' => true]));

        self::assertCount(1, $recorder->calls);
        self::assertSame('Anterior', $recorder->calls[0]['before']['titulo']);
        self::assertSame('Nuevo', $recorder->calls[0]['after']['titulo']);
    }

    public function test_delete_records_previous_snapshot_and_null_after(): void
    {
        $recorder = new RecordingAuditService();
        $snapshots = new FixedAuditSnapshotService(['id' => 81, 'titulo' => 'Anterior'], null);
        $middleware = new AuditMutation($recorder, $snapshots);
        $request = Request::create('/sesiones/10/materiales/81', 'DELETE');

        $middleware->handle($request, fn () => response()->json(['ok' => true]));

        self::assertCount(1, $recorder->calls);
        self::assertSame('material.deleted', $recorder->calls[0]['event']['action']);
        self::assertSame(['id' => 81, 'titulo' => 'Anterior'], $recorder->calls[0]['before']);
        self::assertNull($recorder->calls[0]['after']);
    }

    public function test_required_mutation_is_not_recorded_with_an_incomplete_snapshot(): void
    {
        $recorder = new RecordingAuditService();
        $middleware = new AuditMutation(
            $recorder,
            new FixedAuditSnapshotService(null, ['id' => 81, 'titulo' => 'Nuevo'])
        );

        $middleware->handle(
            Request::create('/sesiones/10/materiales/81', 'PUT'),
            fn () => response()->json(['ok' => true])
        );

        self::assertCount(0, $recorder->calls);
    }

    /**
     * @dataProvider auditedMutationProvider
     */
    public function test_required_mutations_are_mapped_to_allowlisted_snapshot_entities(
        string $method,
        string $path,
        string $action,
        string $entityType
    ): void {
        $recorder = new RecordingAuditService();
        $middleware = new AuditMutation(
            $recorder,
            new FixedAuditSnapshotService(['id' => 1], ['id' => 1])
        );

        $middleware->handle(Request::create($path, $method), fn () => response()->json(['ok' => true]));

        self::assertCount(1, $recorder->calls);
        self::assertSame($action, $recorder->calls[0]['event']['action']);
        self::assertSame($entityType, $recorder->calls[0]['event']['entity_type']);
    }

    public static function auditedMutationProvider(): array
    {
        return [
            'announcement' => ['PUT', '/anuncios/5', 'announcement.updated', 'announcement'],
            'evaluation assignment' => ['POST', '/sesiones/10/evaluacion', 'evaluation.assigned', 'evaluation'],
            'attendance' => ['PATCH', '/sesiones/10/asistencias/9', 'attendance.updated', 'attendance'],
            'video' => ['POST', '/sesiones/10/video/status-updated', 'video.status_updated', 'video'],
        ];
    }

    public function test_snapshot_allowlist_excludes_credentials_tokens_urls_and_unknown_fields(): void
    {
        $service = new AuditService();
        $request = Request::create('/anuncios/5', 'PUT', [], [], [], [
            'HTTP_X_USER_EMAIL' => 'teacher@example.test',
        ]);
        $event = ['action' => 'announcement.updated', 'entity_type' => 'announcement', 'entity_id' => 5];

        $record = $service->buildRecord($request, $event, [
            'id' => 5,
            'titulo' => 'Permitido',
            'password' => 'blocked',
            'private_key' => 'blocked',
            'access_token' => 'blocked',
            'INTERNAL_SERVICE_TOKEN' => 'blocked',
            'Authorization' => 'blocked',
            'upload_url' => 'blocked',
            'binary' => 'blocked',
        ], ['id' => 5, 'titulo' => 'Nuevo']);

        self::assertSame(['id' => 5, 'titulo' => 'Permitido'], json_decode($record['before_data'], true));
        self::assertSame(['id' => 5, 'titulo' => 'Nuevo'], json_decode($record['after_data'], true));
    }
}

class RecordingAuditService extends AuditService
{
    public array $calls = [];

    public function record(Request $request, array $event, ?array $before, ?array $after): void
    {
        $this->calls[] = compact('event', 'before', 'after');
    }
}

class FixedAuditSnapshotService extends AuditSnapshotService
{
    public function __construct(private ?array $before, private ?array $after)
    {
    }

    public function captureBefore(Request $request, array $event): ?array
    {
        return $this->before;
    }

    public function captureAfter(Request $request, array &$event, $response): ?array
    {
        if ($event['entity_id'] === null && is_array($this->after) && isset($this->after['id'])) {
            $event['entity_id'] = $this->after['id'];
        }

        return $this->after;
    }
}
