<?php

namespace App\Services;

use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\PerformanceCache;

class PaymentService
{
    public function __construct(
        private readonly ApiServiciosClient $client
    ) {
    }

    public function listByEmail(string $email): ServiceResult
    {
        return PerformanceCache::remember(
            PerformanceCache::paymentKey($email),
            PerformanceCache::COURSE_LIST_TTL,
            fn () => $this->listByEmailFresh($email)
        );
    }

    private function listByEmailFresh(string $email): ServiceResult
    {
        $result = $this->client->listarPagosPorCorreo($email);

        if (!$result->ok()) {
            return ServiceResult::failure(
                $result->error(),
                $result->status()
            );
        }

        $rows = collect($result->data())
            ->map(fn ($item) => $this->normalizePayment($item))
            ->values();

        return ServiceResult::success([
            'payments' => $rows,
        ]);
    }

    private function normalizePayment(mixed $item): array
    {
        $data = is_array($item) ? $item : (array) $item;

        return [
            'course' => $data['curso'] ?? '',
            'edition' => $data['edicion'] ?? null,
            'currency' => $data['moneda'] ?? '',
            'course_price' => isset($data['precio_curso'])
                ? (float) $data['precio_curso']
                : null,
            'installment_number' => isset($data['cuota'])
                ? (int) $data['cuota']
                : null,
            'installments' => isset($data['cantidad_cuotas'])
                ? (int) $data['cantidad_cuotas']
                : null,
            'installment_amount' => isset($data['importe_cuota'])
                ? (float) $data['importe_cuota']
                : null,
            'status' => $data['estado'] ?? '',
            'due_date' => $data['fecha_vencimiento'] ?? null,
            'paid_date' => $data['fecha_pago'] ?? null,
        ];
    }
}
