<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PagoService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PagoController extends Controller
{
    protected PagoService $service;

    public function __construct(PagoService $service)
    {
        $this->service = $service;
    }

    public function listarPorCorreo(Request $request)
    {
        try {
            $email = $request->header('X-USER-EMAIL') ?: $request->query('email');

            if (empty($email)) {
                return response()->json([
                    'error' => 'email requerido'
                ], 400);
            }

            $rows = $this->service->listarPagosPorCorreo($email);

            $data = array_map(function ($r) {

                return [
                    'curso' => $r->curso,
                    'edicion' => isset($r->edicion)
                        ? (int) $r->edicion
                        : null,
                    'moneda' => $r->moneda,
                    'precio_curso' => isset($r->precio_curso)
                        ? (float) $r->precio_curso
                        : null,
                    'cuota' => isset($r->cuota)
                        ? (int) $r->cuota
                        : null,
                    'cantidad_cuotas' => isset($r->cantidad_cuotas)
                        ? (int) $r->cantidad_cuotas
                        : null,
                    'importe_cuota' => isset($r->importe_cuota)
                        ? (float) $r->importe_cuota
                        : null,
                    'estado' => $r->estado,
                    'fecha_vencimiento' => $r->fecha_vencimiento,
                    'fecha_pago' => $r->fecha_pago,
                ];

            }, $rows);

            return response()->json($data);

        } catch (\Throwable $e) {

            $correlationId = (string) Str::uuid();

            Log::error('Error en listar pagos', [
                'correlation_id' => $correlationId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error interno',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }
}
