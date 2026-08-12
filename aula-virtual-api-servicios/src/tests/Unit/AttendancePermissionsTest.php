<?php

namespace Tests\Unit;

use App\Repositories\AttendanceRepository;
use App\Repositories\MeetingRepository;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceService;
use App\Services\MeetingService;
use App\Services\ZoomReportClient;
use Tests\TestCase;

class AttendancePermissionsTest extends TestCase
{
    public function test_teacher_cannot_override_a_teacher_attendance_record(): void
    {
        $repository = $this->createMock(AttendanceRepository::class);
        $repository->method('findAttendance')->willReturn((object) [
            'id' => 9,
            'sesion_id' => 44,
            'curso_edicion_id' => 10,
            'tipo_participante' => 'docente',
        ]);
        $repository->method('teacherAssignedToCourse')->willReturn(true);
        $repository->expects(self::never())->method('manualOverride');

        $service = new AttendanceService(
            $repository,
            $this->createMock(MeetingRepository::class),
            $this->createMock(MeetingService::class),
            $this->createMock(AttendanceCalculator::class),
            $this->createMock(ZoomReportClient::class)
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('teacher_attendance_admin_only');
        $service->override(9, 'presente', 'Correccion valida', 'docente', 'teacher@example.com', 44);
    }

    public function test_teacher_cannot_start_manual_zoom_reconciliation(): void
    {
        $repository = $this->createMock(AttendanceRepository::class);
        $repository->method('sessionContext')->willReturn((object) [
            'id' => 44, 'curso_edicion_id' => 10, 'fecha' => '2026-08-01',
            'hora_inicio_prog' => '19:00:00', 'hora_fin_prog' => '22:00:00',
        ]);
        $repository->method('teacherAssignedToCourse')->willReturn(true);

        $service = new AttendanceService(
            $repository,
            $this->createMock(MeetingRepository::class),
            $this->createMock(MeetingService::class),
            $this->createMock(AttendanceCalculator::class),
            $this->createMock(ZoomReportClient::class)
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('attendance_sync_admin_only');
        $service->syncSession(44, 'docente', 'teacher@example.com');
    }
}
