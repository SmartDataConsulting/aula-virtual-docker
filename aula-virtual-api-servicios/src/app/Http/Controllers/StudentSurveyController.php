<?php

namespace App\Http\Controllers;

use App\Services\GenDocsSurveyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StudentSurveyController extends Controller
{
    public function __construct(private readonly GenDocsSurveyService $service)
    {
    }

    public function show(Request $request, $course, $session, $link)
    {
        $email = trim((string) $request->header('X-USER-EMAIL', ''));
        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'Correo requerido.'], 400);
        }
        $key = sprintf('survey:form:%d:%d:%d:%s', $course, $session, $link, md5(strtolower($email)));
        $result = Cache::remember($key, 10, fn () => $this->service->form((int) $course, (int) $session, (int) $link, $email));
        return response()->json($result, (int) ($result['status'] ?? 200));
    }

    public function store(Request $request, $course, $session, $link)
    {
        $email = trim((string) $request->header('X-USER-EMAIL', ''));
        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'Correo requerido.'], 400);
        }
        $result = $this->service->submit((int) $course, (int) $session, (int) $link, $email, (array) $request->all());
        if ($result['ok'] ?? false) {
            Cache::forget(sprintf('survey:form:%d:%d:%d:%s', $course, $session, $link, md5(strtolower($email))));
        }
        return response()->json($result, (int) ($result['status'] ?? 200));
    }
}
