<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap/app.php';

$apply = in_array('--apply', $argv, true);
$connection = DB::connection('mysql_cursos');

$summary = $connection->selectOne(<<<'SQL'
    SELECT
        COUNT(*) AS total_active,
        SUM(edicion IS NULL OR TRIM(edicion) = '') AS missing_edition,
        SUM(sesion IS NULL OR sesion < 1) AS missing_session,
        SUM(url IS NULL OR TRIM(url) = '') AS missing_url
    FROM meetings
    WHERE status = 'activo'
SQL);

$candidates = $connection->select(<<<'SQL'
    SELECT
        m.id AS meeting_id,
        m.sesion AS stored_session,
        MIN(s.nro_sesion) AS resolved_session,
        COUNT(DISTINCT s.id) AS candidate_count
    FROM meetings m
    INNER JOIN curso_edicion ce
        ON ce.edicion = m.edicion
    INNER JOIN curso_edicion_sesiones s
        ON s.curso_edicion_id = ce.id
       AND ABS(TIMESTAMPDIFF(MINUTE, m.date, CONCAT(s.fecha, ' ', s.hora_inicio_prog))) <= 5
    LEFT JOIN zoom_hosts zh
        ON zh.id = s.zoom_host_id
    WHERE m.status = 'activo'
      AND m.edicion IS NOT NULL
      AND TRIM(m.edicion) <> ''
      AND LOWER(TRIM(m.host_zoom)) = LOWER(TRIM(COALESCE(zh.email, ce.cta_zoom)))
    GROUP BY m.id, m.sesion
SQL);

$unique = array_values(array_filter($candidates, fn (object $row): bool => (int) $row->candidate_count === 1));
$ambiguous = array_values(array_filter($candidates, fn (object $row): bool => (int) $row->candidate_count > 1));
$updates = array_values(array_filter(
    $unique,
    fn (object $row): bool => (int) ($row->stored_session ?? 0) !== (int) $row->resolved_session
));

printf("Meetings reconciliation (%s)\n", $apply ? 'APPLY' : 'DRY RUN');
printf("Active: %d\n", (int) ($summary->total_active ?? 0));
printf("Missing edition: %d\n", (int) ($summary->missing_edition ?? 0));
printf("Missing session: %d\n", (int) ($summary->missing_session ?? 0));
printf("Missing URL: %d\n", (int) ($summary->missing_url ?? 0));
printf("Unique date/host matches: %d\n", count($unique));
printf("Ambiguous matches: %d\n", count($ambiguous));
printf("Session corrections: %d\n", count($updates));

if ($ambiguous !== []) {
    echo 'Ambiguous meeting IDs: '.implode(', ', array_map(
        fn (object $row): string => (string) $row->meeting_id,
        $ambiguous
    )).PHP_EOL;
}

if (!$apply || $updates === []) {
    exit(0);
}

$connection->transaction(function () use ($connection, $updates): void {
    foreach ($updates as $row) {
        $connection->table('meetings')
            ->where('id', $row->meeting_id)
            ->where('status', 'activo')
            ->update(['sesion' => (int) $row->resolved_session]);
    }
});

echo "Unique session associations updated successfully.\n";
