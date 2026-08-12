<?php

namespace App\Repositories;

use App\Helpers\DbSafe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SgaDiplomaRepository
{
    private array $columnCache = [];

    public function listarDiplomasPorCurso(int $cursoEdicionId, array $curso, array $participantes): array
    {
        if (!$this->tableExists('mysql_sga', 'tabla_de_alumnos')) {
            return [
                'available' => false,
                'message' => 'La tabla SGA tabla_de_alumnos no existe.',
                'items' => [],
                'config' => $this->buscarConfiguracion($cursoEdicionId),
            ];
        }

        $config = $this->buscarConfiguracion($cursoEdicionId);
        $columns = $this->columns('mysql_sga', 'tabla_de_alumnos');
        $select = $this->buildDiplomaSelect($columns);
        $where = [];
        $params = [];

        if (isset($columns['course_id']) && !empty($config['course_id'])) {
            $where[] = 'course_id = ?';
            $params[] = (string) $config['course_id'];
        }

        $courseName = $this->normalizeText($curso['nombre'] ?? $curso['curso'] ?? '');
        $edition = $this->normalizeText($this->extractEdition($curso));

        if (isset($columns['curso']) && $courseName !== '') {
            $where[] = 'LOWER(TRIM(curso)) = LOWER(TRIM(?))';
            $params[] = $courseName;
        }

        if (isset($columns['edicion']) && $edition !== '') {
            $where[] = 'LOWER(TRIM(edicion)) = LOWER(TRIM(?))';
            $params[] = $edition;
        }

        if (empty($where) && isset($columns['email'])) {
            $emails = array_values(array_filter(array_map(function ($participante) {
                return $this->normalizeEmail($participante->CORREO_PERSONAL ?? '');
            }, $participantes)));

            if (!empty($emails)) {
                $placeholders = implode(',', array_fill(0, count($emails), '?'));
                $where[] = "LOWER(TRIM(email)) IN ({$placeholders})";
                $params = array_merge($params, $emails);
            }
        }

        if (empty($where)) {
            return [
                'available' => true,
                'message' => null,
                'items' => [],
                'config' => $config,
            ];
        }

        $sql = "
            SELECT {$select}
            FROM tabla_de_alumnos
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";

        try {
            $rows = DbSafe::select('mysql_sga', $sql, $params);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron leer diplomas SGA', [
                'curso_edicion_id' => $cursoEdicionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'message' => 'No se pudo consultar SGA.',
                'items' => [],
                'config' => $config,
            ];
        }

        return [
            'available' => true,
            'message' => null,
            'items' => array_map(fn ($row) => $this->mapDiploma($row), $rows),
            'config' => $config,
        ];
    }

    private function buscarConfiguracion(int $cursoEdicionId): array
    {
        foreach (['mysql_cursos', 'mysql_sga'] as $connection) {
            if (!$this->tableExists($connection, 'configuracion_diplomas')) {
                continue;
            }

            $rows = DbSafe::select($connection, "
                SELECT *
                FROM configuracion_diplomas
                WHERE curso_edicion_id = ?
                ORDER BY id DESC
                LIMIT 1
            ", [$cursoEdicionId]);

            if (!empty($rows)) {
                return (array) $rows[0];
            }
        }

        return [];
    }

    private function buildDiplomaSelect(array $columns): string
    {
        $fields = [
            'id',
            'nombre',
            'curso',
            'edicion',
            'url',
            'imagen',
            'url_web',
            'codigo',
            'tipo',
            'course_id',
            'email',
            'estado',
            'fecha_envio',
            'created_at',
        ];

        return implode(', ', array_map(function (string $field) use ($columns) {
            return isset($columns[$field])
                ? $field
                : "NULL AS {$field}";
        }, $fields));
    }

    private function mapDiploma(object $row): array
    {
        $publicUrl = $this->normalizeUrl($row->url_web ?? null);
        $imageUrl = $this->normalizeUrl($row->imagen ?? null);
        $fileUrl = $this->normalizeUrl($row->url ?? null);
        $sentAt = trim((string) ($row->fecha_envio ?? ''));
        $estadoRaw = trim((string) ($row->estado ?? ''));
        $hasAsset = $publicUrl !== null || $imageUrl !== null || $fileUrl !== null;
        $isSent = $sentAt !== '' || $estadoRaw === '1' || strtolower($estadoRaw) === 'enviado';

        return [
            'source' => 'sga_diplomas',
            'diploma_id' => (int) ($row->id ?? 0),
            'student_name' => trim((string) ($row->nombre ?? '')),
            'student_email' => $this->normalizeEmail($row->email ?? ''),
            'course_name' => trim((string) ($row->curso ?? '')),
            'edition' => trim((string) ($row->edicion ?? '')),
            'code' => trim((string) ($row->codigo ?? '')),
            'type' => trim((string) ($row->tipo ?? '')),
            'course_id' => trim((string) ($row->course_id ?? '')),
            'image_url' => $imageUrl,
            'file_url' => $fileUrl,
            'public_url' => $publicUrl ?: $imageUrl ?: $fileUrl,
            'sent_at' => $sentAt !== '' ? $sentAt : null,
            'created_at' => trim((string) ($row->created_at ?? '')) ?: null,
            'status' => $isSent ? 'enviado' : ($hasAsset ? 'generado' : 'requiere_revision'),
        ];
    }

    private function tableExists(string $connection, string $table): bool
    {
        try {
            $database = DB::connection($connection)->getDatabaseName();
            $rows = DbSafe::select($connection, "
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_NAME = ?
                LIMIT 1
            ", [$database, $table]);

            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columns(string $connection, string $table): array
    {
        $cacheKey = "{$connection}.{$table}";

        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        $database = DB::connection($connection)->getDatabaseName();
        $rows = DbSafe::select($connection, "
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
        ", [$database, $table]);

        return $this->columnCache[$cacheKey] = array_fill_keys(array_map(
            fn ($row) => (string) $row->COLUMN_NAME,
            $rows
        ), true);
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            $base = rtrim((string) env('SGA_PUBLIC_BASE_URL', ''), '/');

            if ($base !== '' && preg_match('#/sga(?:-web)?/(.+)$#i', $url, $matches)) {
                return $base . '/' . ltrim($matches[1], '/');
            }

            return $url;
        }

        $base = rtrim((string) env('SGA_PUBLIC_BASE_URL', ''), '/');

        return $base !== '' ? $base . '/' . ltrim($url, '/') : $url;
    }

    private function extractEdition(array $curso): string
    {
        foreach (['edicion', 'edition', 'codigo'] as $key) {
            $value = trim((string) ($curso[$key] ?? ''));

            if ($value !== '') {
                return preg_replace('/^CE[-\s]*/i', '', $value) ?: $value;
            }
        }

        return '';
    }

    private function normalizeEmail(mixed $email): string
    {
        return strtolower(trim((string) ($email ?? '')));
    }

    private function normalizeText(mixed $text): string
    {
        return trim((string) ($text ?? ''));
    }
}
