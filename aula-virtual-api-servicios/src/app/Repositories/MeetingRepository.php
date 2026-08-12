<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class MeetingRepository
{
    public function obtenerPorZoomId(string $zoomMeetingId): ?object
    {
        $rows = DbSafe::select('mysql_cursos', <<<'SQL'
            SELECT id, title, date, host_zoom, duration, zoom_meeting_id, sesion, edicion,
                   url, id_reunion, codigo_acceso, status
            FROM meetings
            WHERE status = 'activo' AND (zoom_meeting_id = ? OR id_reunion = ?)
            ORDER BY id DESC LIMIT 1
        SQL, [$zoomMeetingId, $zoomMeetingId]);

        return $rows[0] ?? null;
    }

    public function listarActivasPorEdicion(string $edicion): array
    {
        if ($edicion === '') {
            return [];
        }

        return DbSafe::select('mysql_cursos', <<<'SQL'
            SELECT
                id,
                title,
                date,
                host_zoom,
                duration,
                zoom_meeting_id,
                sesion,
                edicion,
                url,
                id_reunion,
                codigo_acceso
            FROM meetings
            WHERE status = 'activo'
              AND edicion = ?
              AND url IS NOT NULL
              AND TRIM(url) <> ''
            ORDER BY date ASC, id ASC
        SQL, [$edicion]);
    }
}
