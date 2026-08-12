<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MeetingDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            throw new \RuntimeException('MeetingDevelopmentSeeder is only available in local environments.');
        }

        $connection = DB::connection('mysql_cursos');
        $session = $connection->selectOne(<<<'SQL'
            SELECT
                s.id,
                s.nro_sesion,
                s.fecha,
                s.hora_inicio_prog,
                s.dur_min,
                ce.curso,
                ce.edicion,
                COALESCE(zh.email, ce.cta_zoom) AS host_zoom
            FROM curso_edicion_sesiones s
            INNER JOIN curso_edicion ce ON ce.id = s.curso_edicion_id
            LEFT JOIN zoom_hosts zh ON zh.id = s.zoom_host_id
            WHERE s.fecha >= CURDATE()
            ORDER BY s.fecha, s.hora_inicio_prog
            LIMIT 1
        SQL);
        $userId = $connection->table('users')->orderBy('id')->value('id');

        if (!$session || !$userId) {
            throw new \RuntimeException('A future course session and a users record are required.');
        }

        $startsAt = trim($session->fecha.' '.$session->hora_inicio_prog);

        $connection->table('meetings')->updateOrInsert(
            ['zoom_meeting_id' => 'LOCAL-DEMO-'.$session->id],
            [
                'user_id' => $userId,
                'title' => 'Sesion '.$session->nro_sesion.'-'.$session->curso,
                'date' => $startsAt,
                'host_zoom' => $session->host_zoom ?: 'local@example.test',
                'emails' => 'alumno@example.test',
                'duration' => max(1, (int) ceil(((int) $session->dur_min) / 60)),
                'calendar_event_id' => null,
                'status' => 'activo',
                'sesion' => $session->nro_sesion,
                'edicion' => $session->edicion,
                'url' => 'https://zoom.us/j/00000000000',
                'id_reunion' => '00000000000',
                'codigo_acceso' => 'local',
            ]
        );
    }
}
