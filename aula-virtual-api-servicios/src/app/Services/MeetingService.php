<?php

namespace App\Services;

use App\Repositories\MeetingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class MeetingService
{
    private const TIMEZONE = 'America/Lima';
    private const STUDENT_EARLY_ACCESS_MINUTES = 15;

    public function __construct(private MeetingRepository $repository)
    {
    }

    public function attachToSessions(array $sessions, string $role): array
    {
        if (!$this->enabled() || $sessions === []) {
            return $sessions;
        }

        $meetingsByEdition = [];

        foreach ($sessions as $session) {
            $edition = trim((string) ($session->curso_edicion ?? ''));

            if (!array_key_exists($edition, $meetingsByEdition)) {
                $meetingsByEdition[$edition] = $this->repository->listarActivasPorEdicion($edition);
            }

            $meeting = $this->match($session, $meetingsByEdition[$edition]);
            $session->meeting = $this->presentMeeting($meeting, $role);
        }

        return $sessions;
    }

    public function attachToSession(object $session, string $role): object
    {
        $items = $this->attachToSessions([$session], $role);

        return $items[0] ?? $session;
    }

    public function resolveMeeting(object $session): ?object
    {
        if (!$this->enabled()) {
            return null;
        }

        $edition = trim((string) ($session->curso_edicion ?? ''));

        return $this->match($session, $this->repository->listarActivasPorEdicion($edition));
    }

    public function presentMeeting(?object $meeting, string $role): array
    {
        return $this->present($meeting, $role);
    }

    private function match(object $session, array $meetings): ?object
    {
        if ($meetings === []) {
            return null;
        }

        $number = (int) ($session->numero ?? 0);
        $course = $this->normalize((string) ($session->curso_nombre ?? ''));
        $host = strtolower(trim((string) ($session->zoom_host_email ?? '')));
        $sessionStart = $this->sessionStart($session);

        $tiers = [
            fn (object $meeting) => (int) ($meeting->sesion ?? 0) === $number
                && $course !== ''
                && str_contains($this->normalize((string) $meeting->title), $course),
            fn (object $meeting) => $host !== ''
                && strtolower(trim((string) $meeting->host_zoom)) === $host
                && $sessionStart !== null
                && abs($sessionStart->diffInMinutes($this->meetingStart($meeting), false)) <= 5,
            fn (object $meeting) => (int) ($meeting->sesion ?? 0) === $number
                && $host !== ''
                && strtolower(trim((string) $meeting->host_zoom)) === $host,
        ];

        foreach ($tiers as $tier) {
            $matches = array_values(array_filter($meetings, $tier));

            if (count($matches) === 1) {
                return $matches[0];
            }

            if (count($matches) > 1) {
                $disambiguated = $this->disambiguateByScheduleAndHost($session, $matches);

                if ($disambiguated !== null) {
                    return $disambiguated;
                }

                Log::warning('Ambiguous Zoom meeting association.', [
                    'course_id' => (int) ($session->curso_edicion_id ?? 0),
                    'session_id' => (int) ($session->id ?? 0),
                    'candidate_count' => count($matches),
                ]);

                return null;
            }
        }

        return null;
    }

    private function disambiguateByScheduleAndHost(object $session, array $meetings): ?object
    {
        $host = strtolower(trim((string) ($session->zoom_host_email ?? '')));
        $sessionStart = $this->sessionStart($session);

        if ($host === '' || $sessionStart === null) {
            return null;
        }

        $matches = array_values(array_filter($meetings, function (object $meeting) use ($host, $sessionStart): bool {
            return strtolower(trim((string) $meeting->host_zoom)) === $host
                && abs($sessionStart->diffInMinutes($this->meetingStart($meeting), false)) <= 5;
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function present(?object $meeting, string $role): array
    {
        if (!$meeting || !$this->validZoomUrl((string) ($meeting->url ?? ''))) {
            return $this->emptyPresentation();
        }

        $startsAt = $this->meetingStart($meeting);
        $endsAt = $startsAt->addHours(max(1, (int) ($meeting->duration ?? 1)));
        $now = CarbonImmutable::now(self::TIMEZONE);
        $privileged = in_array(strtolower($role), [
            'admin', 'administrador', 'operador', 'docente', 'profesor',
        ], true);
        $studentWindowOpen = $now->betweenIncluded(
            $startsAt->subMinutes(self::STUDENT_EARLY_ACCESS_MINUTES),
            $endsAt
        );
        $canJoin = $privileged || $studentWindowOpen;
        $availability = $now->lt($startsAt->subMinutes(self::STUDENT_EARLY_ACCESS_MINUTES))
            ? 'upcoming'
            : ($now->lte($endsAt) ? 'open' : 'ended');

        return [
            'scheduled' => true,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'availability' => $availability,
            'can_join' => $canJoin,
            'join_url' => $canJoin ? trim((string) $meeting->url) : null,
            'meeting_id' => $privileged
                ? (string) ($meeting->id_reunion ?: $meeting->zoom_meeting_id ?: '')
                : null,
            'access_code' => $privileged ? (string) ($meeting->codigo_acceso ?? '') : null,
        ];
    }

    private function emptyPresentation(): array
    {
        return [
            'scheduled' => false,
            'starts_at' => null,
            'ends_at' => null,
            'availability' => 'unavailable',
            'can_join' => false,
            'join_url' => null,
            'meeting_id' => null,
            'access_code' => null,
        ];
    }

    private function sessionStart(object $session): ?CarbonImmutable
    {
        if (empty($session->fecha)) {
            return null;
        }

        try {
            $date = (string) $session->fecha;
            $time = (string) ($session->hora_inicio ?? '00:00:00');

            return CarbonImmutable::parse($date.' '.$time, self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function meetingStart(object $meeting): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $meeting->date, self::TIMEZONE);
    }

    private function validZoomUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && ($host === 'zoom.us' || str_ends_with($host, '.zoom.us'));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii === false ? $value : $ascii;

        return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    }

    private function enabled(): bool
    {
        $value = getenv('MEETINGS_INTEGRATION_ENABLED');

        if ($value === false) {
            $value = env('MEETINGS_INTEGRATION_ENABLED', false);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
