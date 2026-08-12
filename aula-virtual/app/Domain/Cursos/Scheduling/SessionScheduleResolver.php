<?php

declare(strict_types=1);

namespace App\Domain\Cursos\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class SessionScheduleResolver
{
    /**
     * Resuelve la sesion seleccionada y normaliza estados segun calendario.
     */
    public function resolve(Collection $sessions, ?int $sessionId): array
    {
        $session = null;

        if ($sessions->isNotEmpty()) {
            $sessions = $this->attachParsedDates($sessions);
            $nextScheduled = $this->findNextScheduledSession($sessions);

            if ($nextScheduled) {
                $sessions = $this->applyScheduleStates($sessions, $nextScheduled);
            } elseif (!$sessionId) {
                $session = $sessions->first();
            }
        }

        if ($sessionId) {
            $session = $sessions->firstWhere('id', $sessionId);
        }

        if (!$session) {
            $session = $sessions->firstWhere('state', 'current') ?? $sessions->first();
        }

        return [$sessions, $session];
    }

    private function attachParsedDates(Collection $sessions): Collection
    {
        // Normaliza fechas y horas para permitir ordenamiento y comparacion.
        $parseSessionDate = function ($item): ?Carbon {
            $dateValue = $item->date ?? null;
            if (empty($dateValue)) {
                return null;
            }

            $formats = [
                'Y-m-d H:i:s',
                'Y-m-d',
                'd/m/Y H:i',
                'd/m/Y',
                'Y-m-d\TH:i:s',
                'Y-m-d\TH:i:sP',
            ];

            $parsed = null;
            foreach ($formats as $format) {
                try {
                    $parsed = Carbon::createFromFormat($format, $dateValue);
                    break;
                } catch (\Exception $e) {
                }
            }

            if (!$parsed) {
                try {
                    $parsed = Carbon::parse($dateValue);
                } catch (\Exception $e) {
                    return null;
                }
            }

            $startTime = $item->start_time ?? null;
            if (!empty($startTime)) {
                try {
                    $timeOnly = Carbon::parse($startTime);
                    $parsed = $parsed->setTime($timeOnly->hour, $timeOnly->minute, 0);
                } catch (\Exception $e) {
                }
            }

            return $parsed;
        };

        return $sessions->map(function ($item) use ($parseSessionDate) {
            $item->date_parsed = $parseSessionDate($item);
            return $item;
        });
    }

    private function findNextScheduledSession(Collection $sessions): ?object
    {
        // Identifica la proxima sesion programada a partir de estado y fecha.
        return $sessions
            ->filter(function ($item) {
                $rawState = strtolower((string) ($item->raw_state ?? ''));
                $isScheduled = ($item->state ?? null) === 'future'
                    || in_array($rawState, ['programada', 'programado'], true);
                return $item->date_parsed && $isScheduled;
            })
            ->sortBy('date_parsed')
            ->first();
    }

    private function applyScheduleStates(Collection $sessions, object $nextScheduled): Collection
    {
        // Ajusta estados relativos tomando la proxima sesion como referencia.
        $cutoff = $nextScheduled->date_parsed;

        return $sessions->map(function ($item) use ($cutoff, $nextScheduled) {
            if (!empty($item->date_parsed) && $item->date_parsed instanceof Carbon) {
                if ($item->id == $nextScheduled->id) {
                    $item->state = 'current';
                } elseif ($item->date_parsed->gt($cutoff)) {
                    $item->state = 'future';
                } elseif ($item->date_parsed->lt($cutoff)) {
                    $item->state = 'past';
                }
            }
            return $item;
        });
    }
}
