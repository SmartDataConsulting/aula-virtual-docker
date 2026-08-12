<?php

namespace Tests\Unit;

use App\Repositories\MeetingRepository;
use App\Services\MeetingService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MeetingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MEETINGS_INTEGRATION_ENABLED=true');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 18:50:00', 'America/Lima'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        putenv('MEETINGS_INTEGRATION_ENABLED');
        parent::tearDown();
    }

    public function test_student_receives_url_only_inside_access_window(): void
    {
        $service = new MeetingService($this->repositoryWith([$this->meeting()]));
        $session = $service->attachToSession($this->session(), 'alumno');

        self::assertTrue($session->meeting['scheduled']);
        self::assertTrue($session->meeting['can_join']);
        self::assertSame('https://zoom.us/j/123456789', $session->meeting['join_url']);
        self::assertNull($session->meeting['meeting_id']);
        self::assertNull($session->meeting['access_code']);
    }

    public function test_student_url_is_removed_before_access_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 18:44:59', 'America/Lima'));
        $service = new MeetingService($this->repositoryWith([$this->meeting()]));
        $session = $service->attachToSession($this->session(), 'alumno');

        self::assertSame('upcoming', $session->meeting['availability']);
        self::assertFalse($session->meeting['can_join']);
        self::assertNull($session->meeting['join_url']);
    }

    public function test_privileged_role_receives_access_details(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'America/Lima'));
        $service = new MeetingService($this->repositoryWith([$this->meeting()]));
        $session = $service->attachToSession($this->session(), 'docente');

        self::assertTrue($session->meeting['can_join']);
        self::assertSame('123456789', $session->meeting['meeting_id']);
        self::assertSame('abc123', $session->meeting['access_code']);
    }

    public function test_ambiguous_candidates_do_not_expose_a_url(): void
    {
        $service = new MeetingService($this->repositoryWith([
            $this->meeting(1),
            $this->meeting(2),
        ]));
        $session = $service->attachToSession($this->session(), 'admin');

        self::assertFalse($session->meeting['scheduled']);
        self::assertNull($session->meeting['join_url']);
    }

    private function repositoryWith(array $meetings): MeetingRepository
    {
        return new class($meetings) extends MeetingRepository {
            public function __construct(private array $meetings)
            {
            }

            public function listarActivasPorEdicion(string $edicion): array
            {
                return $this->meetings;
            }
        };
    }

    private function session(): object
    {
        return (object) [
            'id' => 2654,
            'curso_edicion_id' => 39,
            'numero' => 1,
            'fecha' => '2026-08-05',
            'hora_inicio' => '19:00:00',
            'curso_nombre' => 'Ciencia de Datos',
            'curso_edicion' => '12',
            'zoom_host_email' => 'host@example.test',
        ];
    }

    private function meeting(int $id = 1): object
    {
        return (object) [
            'id' => $id,
            'title' => 'Sesion 1-Ciencia de Datos',
            'date' => '2026-08-05 19:00:00',
            'host_zoom' => 'host@example.test',
            'duration' => 3,
            'zoom_meeting_id' => '123456789',
            'sesion' => 1,
            'edicion' => '12',
            'url' => 'https://zoom.us/j/123456789',
            'id_reunion' => '123456789',
            'codigo_acceso' => 'abc123',
        ];
    }
}
