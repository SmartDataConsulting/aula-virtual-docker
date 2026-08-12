<?php

namespace App\Support;

use Carbon\Carbon;

final class SessionPresentation
{
    private const TIMEZONE = 'America/Lima';

    public static function dateTimeLabel(object $session): string
    {
        $date = self::dateLabel($session->date ?? null);
        $time = self::timeRange($session->start_time ?? null, $session->end_time ?? null);

        return implode(' · ', array_filter([$date, $time]));
    }

    public static function dateLabel(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            $date = Carbon::parse($value, self::TIMEZONE)->locale('es');

            if ($date->isToday()) {
                return 'Hoy, '.$date->isoFormat('D [de] MMMM');
            }

            if ($date->isTomorrow()) {
                return 'Mañana, '.$date->isoFormat('D [de] MMMM');
            }

            return ucfirst($date->isoFormat('ddd D [de] MMMM'));
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function timeRange(mixed $start, mixed $end): string
    {
        $startLabel = self::timeLabel($start);
        $endLabel = self::timeLabel($end);

        if ($startLabel !== '' && $endLabel !== '') {
            return $startLabel.' - '.$endLabel;
        }

        return $startLabel ?: $endLabel;
    }

    public static function lifecycle(object $session, ?Carbon $now = null): string
    {
        $state = strtolower(trim((string) ($session->state ?? '')));
        if (in_array($state, ['cancelada', 'cancelado', 'cancelled', 'canceled'], true)) {
            return 'cancelled';
        }

        $bounds = self::scheduleBounds($session);
        if ($bounds !== null) {
            [$start, $end] = $bounds;
            $now ??= Carbon::now(self::TIMEZONE);

            if ($now->lt($start)) {
                return 'upcoming';
            }

            if ($now->betweenIncluded($start, $end)) {
                return 'in_progress';
            }

            return 'finished';
        }

        return match ($state) {
            'current', 'in_progress', 'en_curso' => 'in_progress',
            'future', 'scheduled', 'programada' => 'upcoming',
            default => 'finished',
        };
    }

    public static function stateLabel(object $session): string
    {
        return match (self::lifecycle($session)) {
            'cancelled' => 'Cancelada',
            'in_progress' => 'En curso',
            'upcoming' => 'Próxima',
            default => 'Finalizada',
        };
    }

    public static function requiresAttention(object $session, ?Carbon $now = null): bool
    {
        return in_array(self::lifecycle($session, $now), ['in_progress', 'finished'], true)
            && !self::isComplete($session);
    }

    public static function isUpcoming(object $session, ?Carbon $now = null): bool
    {
        return self::lifecycle($session, $now) === 'upcoming';
    }

    public static function missingTasks(object $session): array
    {
        return array_values(array_filter([
            !self::hasVideo($session) ? ['panel' => 'video', 'label' => 'Video pendiente'] : null,
            self::materialPending($session) ? ['panel' => 'materials', 'label' => 'Material pendiente'] : null,
        ]));
    }

    public static function isComplete(object $session): bool
    {
        return self::missingTasks($session) === [];
    }

    public static function materialPending(object $session): bool
    {
        return (bool) ($session->material_pending ?? $session->falta_material ?? false);
    }

    public static function hasEvaluation(object $session): bool
    {
        return (bool) ($session->has_evaluation ?? $session->tiene_evaluacion ?? false);
    }

    public static function hasVideo(object $session): bool
    {
        return ($session->video_status ?? null) === 'ready'
            && !empty($session->video_drive_file_id);
    }

    private static function scheduleBounds(object $session): ?array
    {
        $date = $session->date ?? null;
        if (empty($date)) {
            return null;
        }

        try {
            $start = Carbon::parse($date.' '.(($session->start_time ?? null) ?: '00:00:00'), self::TIMEZONE);
            $end = Carbon::parse($date.' '.(($session->end_time ?? null) ?: '23:59:59'), self::TIMEZONE);
            if ($end->lte($start)) {
                $end->addDay();
            }

            return [$start, $end];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function timeLabel(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            $time = Carbon::parse($value);
            return $time->format('g:i').' '.($time->format('A') === 'AM' ? 'a.m.' : 'p.m.');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
