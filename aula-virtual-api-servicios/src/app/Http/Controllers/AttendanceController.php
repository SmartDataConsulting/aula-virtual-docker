<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Laravel\Lumen\Routing\Controller;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    public function join(Request $request, $courseId, $sessionId)
    {
        try {
            $result = $this->service->joinAttempt(
                (int) $courseId,
                (int) $sessionId,
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            );
            return response()->json(['ok' => true] + $result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 503);
        }
    }

    public function course(Request $request, $courseId)
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->listCourse(
                (int) $courseId,
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            )]);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function summaries(Request $request)
    {
        return response()->json(['ok' => true, 'data' => $this->service->listCourseSummaries(
            (string) $request->header('X-USER-ROL'),
            (string) $request->header('X-USER-EMAIL')
        )]);
    }

    public function courseSummary(Request $request, $courseId)
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->listCourseSessionSummaries(
                (int) $courseId,
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            )]);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function session(Request $request, $courseId, $sessionId)
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->listSession(
                (int) $courseId,
                (int) $sessionId,
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            )]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function student(Request $request, $courseId)
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->listStudent(
                (int) $courseId,
                (string) $request->header('X-USER-EMAIL')
            )]);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function update(Request $request, $sessionId, $attendanceId)
    {
        try {
            $record = $this->service->override(
                (int) $attendanceId,
                trim((string) $request->input('status')),
                trim((string) $request->input('reason')),
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL'),
                (int) $sessionId
            );
            return response()->json(['ok' => true, 'data' => $record]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function identify(Request $request, $sessionId)
    {
        try {
            $record = $this->service->identify(
                (int) $sessionId,
                (int) $request->input('event_id'),
                (int) $request->input('attendance_id'),
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            );
            return response()->json(['ok' => true, 'data' => $record]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    public function sync(Request $request, $sessionId)
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->syncSession(
                (int) $sessionId,
                (string) $request->header('X-USER-ROL'),
                (string) $request->header('X-USER-EMAIL')
            )]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'attendance_sync_failed'], 502);
        }
    }
}
