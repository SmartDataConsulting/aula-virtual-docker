<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    public function index(int $course)
    {
        $result = $this->attendance->student($course);
        return view('mis-cursos.attendance', [
            'courseId' => $course,
            'items' => collect($result->ok() ? $result->data() : []),
            'error' => $result->ok() ? null : 'No se pudo cargar tu asistencia.',
        ]);
    }
}
