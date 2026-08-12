<?php

namespace App\Http\Controllers\MisCursos;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {
    }

    public function index(Request $request)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return redirect()->route('login');
        }

        $result = $this->paymentService->listByEmail($correo);

        if (!$result->ok()) {
            Log::error('PaymentController@index error', [
                'correo' => $correo,
                'error' => $result->error(),
            ]);

            abort(500, 'No se pudo cargar la información de pagos');
        }

        return view('mis-cursos.payments.index', [
            'payments' => $result->data()['payments'] ?? [],
        ]);
    }

    public function list(Request $request)
    {
        $correo = (string) $request->session()->get(AuthSessionKeys::USER_EMAIL, '');

        if ($correo === '') {
            return response()->json([
                'message' => 'No autorizado',
            ], 401);
        }

        $result = $this->paymentService->listByEmail($correo);

        if (!$result->ok()) {
            return response()->json([
                'message' => $this->resolveApiErrorMessage(
                    $result->error(),
                    'No se pudieron obtener los pagos'
                ),
            ], $result->status() ?? 500);
        }

        return response()->json($result->data(), 200);
    }

    private function resolveApiErrorMessage(mixed $error, string $fallback): string
    {
        $payload = is_array($error) ? $error : (array) $error;
        $body = $payload['body'] ?? null;

        if (is_string($body)) {
            $decoded = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload['message']
            ?? $payload['error']
            ?? $payload['body']
            ?? $fallback;
    }
}