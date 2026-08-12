@extends('layouts.main')

@section('title', 'Aula Virtual - Mis Pagos')
@section('body-class', 'bg-gray-50 min-h-screen text-gray-800')

@section('content')
  @php
    $payments = collect($payments ?? []);

    $isPaid = function ($payment): bool {
        $status = strtolower((string) ($payment['status'] ?? ''));

        return $status === 'pagado' || !empty($payment['paid_date']);
    };

    $pendingPayments = $payments
        ->reject(fn ($payment) => $isPaid($payment))
        ->values();

    $paidPayments = $payments
        ->filter(fn ($payment) => $isPaid($payment))
        ->values();

    $pendingTotals = $pendingPayments
        ->groupBy(fn ($payment) => (string) ($payment['currency'] ?? ''))
        ->map(fn ($items) => $items->sum(fn ($payment) => (float) ($payment['installment_amount'] ?? 0)))
        ->filter(fn ($amount) => $amount > 0)
        ->sortKeys();

    if ($pendingTotals->isEmpty()) {
        $pendingTotals = collect(['USD' => 0]);
    }

    $nextDuePayment = $pendingPayments
        ->filter(fn ($payment) => !empty($payment['due_date']))
        ->sortBy('due_date')
        ->first();

    $formatAmount = function ($amount): string {
        if ($amount === null || $amount === '') {
            return '-';
        }

        return number_format((float) $amount, 2, ',', '');
    };

    $formatDate = function ($date, bool $withTime = false): string {
        if (empty($date)) {
            return '-';
        }

        try {
            $value = \Carbon\Carbon::parse($date);
        } catch (\Throwable $exception) {
            return (string) $date;
        }

        $months = [
            1 => 'ene.',
            2 => 'feb.',
            3 => 'mar.',
            4 => 'abr.',
            5 => 'may.',
            6 => 'jun.',
            7 => 'jul.',
            8 => 'ago.',
            9 => 'sep.',
            10 => 'oct.',
            11 => 'nov.',
            12 => 'dic.',
        ];

        $formatted = $value->format('d').' '.$months[(int) $value->format('n')].' '.$value->format('Y');

        if ($withTime && preg_match('/\d{1,2}:\d{2}/', (string) $date)) {
            $formatted .= ' '.$value->format('H:i');
        }

        return $formatted;
    };

    $courseLabel = function ($payment): string {
        $course = (string) ($payment['course'] ?? '-');
        $edition = $payment['edition'] ?? null;

        if ($edition !== null && $edition !== '') {
            return '('.$edition.') '.$course;
        }

        return $course !== '' ? $course : '-';
    };

    $installmentLabel = function ($payment, string $fallback = '-'): string {
        $number = $payment['installment_number'] ?? null;
        $total = $payment['installments'] ?? null;

        if ($number !== null && $total !== null) {
            return $number.' de '.$total;
        }

        return $number !== null ? (string) $number : $fallback;
    };
  @endphp

  <div class="page-header payments-page-header">
    <h1>Mis Pagos</h1>
  </div>

  <div class="page-shell payments-shell">
    <section class="payments-summary card-shadow" aria-label="Resumen de pagos">
      <div class="payments-summary-item">
        <span class="payments-summary-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <rect x="3" y="6" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/>
            <path d="M3 10h18" stroke="currentColor" stroke-width="2"/>
            <path d="M7 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="payments-summary-content">
          <span class="payments-summary-label">Total pendiente</span>
          <span class="payments-summary-amounts">
            @foreach($pendingTotals as $currency => $amount)
              <strong>{{ $currency !== '' ? $currency : 'USD' }} {{ $formatAmount($amount) }}</strong>
            @endforeach
          </span>
        </span>
      </div>

      <div class="payments-summary-item">
        <span class="payments-summary-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <rect x="4" y="5" width="16" height="16" rx="2" stroke="currentColor" stroke-width="2"/>
            <path d="M8 3v4M16 3v4M4 10h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </span>
        <span>
          <span class="payments-summary-label">Pr&oacute;ximo vencimiento</span>
          <strong>{{ $formatDate($nextDuePayment['due_date'] ?? null) }}</strong>
        </span>
      </div>
    </section>

    <section class="payments-section">
      <h2>Cuotas Pendientes</h2>

      <div class="payments-table-wrap card-shadow">
        <table class="payments-table">
          <thead>
            <tr>
              <th>Curso</th>
              <th>Moneda</th>
              <th>Precio</th>
              <th>Cuota</th>
              <th>Importe Cuota</th>
              <th>Vence</th>
              <th class="payments-status-column"></th>
            </tr>
          </thead>
          <tbody>
            @forelse($pendingPayments as $payment)
              <tr>
                <td data-label="Curso">{{ $courseLabel($payment) }}</td>
                <td data-label="Moneda">{{ $payment['currency'] ?? '-' }}</td>
                <td data-label="Precio">{{ $formatAmount($payment['course_price'] ?? null) }}</td>
                <td data-label="Cuota">{{ $installmentLabel($payment) }}</td>
                <td data-label="Importe Cuota">{{ $formatAmount($payment['installment_amount'] ?? null) }}</td>
                <td data-label="Vence">{{ $formatDate($payment['due_date'] ?? null) }}</td>
                <td data-label="Estado" class="payments-status-cell">
                  <span class="payment-badge payment-badge-pending">Pendiente</span>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="payments-empty">No tienes cuotas pendientes.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section class="payments-section">
      <h2>Historial de Pagos</h2>

      <div class="payments-table-wrap card-shadow">
        <table class="payments-table">
          <thead>
            <tr>
              <th>Curso</th>
              <th>Moneda</th>
              <th>Precio</th>
              <th>Cuota Pagada</th>
              <th>Importe Pagado</th>
              <th>Fecha de Pago</th>
              <th class="payments-status-column"></th>
            </tr>
          </thead>
          <tbody>
            @forelse($paidPayments as $payment)
              <tr>
                <td data-label="Curso">{{ $courseLabel($payment) }}</td>
                <td data-label="Moneda">{{ $payment['currency'] ?? '-' }}</td>
                <td data-label="Precio">{{ $formatAmount($payment['course_price'] ?? null) }}</td>
                <td data-label="Cuota Pagada">{{ $installmentLabel($payment) }}</td>
                <td data-label="Importe Pagado">{{ $formatAmount($payment['installment_amount'] ?? null) }}</td>
                <td data-label="Fecha de Pago">{{ $formatDate($payment['paid_date'] ?? null, true) }}</td>
                <td data-label="Estado" class="payments-status-cell">
                  <span class="payment-badge payment-badge-paid">Pagado</span>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="payments-empty">A&uacute;n no tienes pagos registrados.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
@endsection
