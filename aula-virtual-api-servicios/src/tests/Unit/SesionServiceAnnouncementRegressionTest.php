<?php

namespace Tests\Unit;

use App\Repositories\SesionRepository;
use App\Services\CursoAnuncioService;
use App\Services\GenDocsSurveyService;
use App\Services\MeetingService;
use App\Services\SesionMaterialService;
use App\Services\SesionService;
use App\Services\SesionVideoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SesionServiceAnnouncementRegressionTest extends TestCase
{
    #[DataProvider('sessionIds')]
    public function test_backoffice_sessions_receive_announcements_through_the_existing_service_method(array $ids): void
    {
        $sessions = array_map(fn ($id) => (object) ['id' => (string) $id], $ids);
        $repository = $this->createMock(SesionRepository::class);
        $repository->expects(self::once())->method('listarPorCursoProfesor')
            ->with(100)->willReturn($sessions);
        $repository->expects(self::once())->method('obtenerEvaluacionesPorSesiones')
            ->with(array_map(fn ($id) => (string) $id, $ids))->willReturn([]);

        $meetings = $this->createMock(MeetingService::class);
        $meetings->expects(self::once())->method('attachToSessions')
            ->with($sessions, 'admin')->willReturn($sessions);

        $materials = $this->createMock(SesionMaterialService::class);
        $materials->expects(self::exactly(count($ids)))->method('listarPorSesion')
            ->willReturn([]);

        $expected = [];
        foreach ($ids as $id) {
            $expected[$id] = [(object) ['id' => $id + 1000, 'titulo' => 'Announcement '.$id]];
        }

        // PHPUnit mocks only declared methods; an obsolete listar() call raises an Error.
        $announcements = $this->createMock(CursoAnuncioService::class);
        $calledIds = [];
        $announcements->expects(self::exactly(count($ids)))->method('listarAnuncios')
            ->willReturnCallback(function (string $entityType, int $sessionId) use ($expected, &$calledIds) {
                self::assertSame('sesion', $entityType);
                self::assertArrayHasKey($sessionId, $expected);
                $calledIds[] = $sessionId;
                return $expected[$sessionId];
            });

        $service = new SesionService(
            $repository,
            $this->createMock(GenDocsSurveyService::class),
            $materials,
            $announcements,
            $this->createMock(SesionVideoService::class),
            $meetings
        );

        $result = $service->listarPorCursoProfesor(100, 'admin');

        self::assertSame($sessions, $result);
        self::assertSame($ids, $calledIds);
        foreach ($result as $session) {
            self::assertSame($expected[(int) $session->id], $session->anuncios);
            self::assertSame([], $session->evaluaciones);
            self::assertSame([], $session->materiales);
        }
    }

    public static function sessionIds(): array
    {
        return [
            'one session' => [[41]],
            'multiple sessions' => [[41, 42]],
        ];
    }
}
