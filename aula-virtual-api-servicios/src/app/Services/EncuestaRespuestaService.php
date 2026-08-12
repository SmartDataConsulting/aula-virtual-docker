<?php

namespace App\Services;

use App\Enums\TipoEncuesta;
use App\Repositories\EncuestaRepository;
use App\Repositories\EncuestaRespuestaRepository;
use InvalidArgumentException;

class EncuestaRespuestaService
{
    public function __construct(
        private readonly EncuestaRespuestaRepository $repo,
        private readonly EncuestaRepository $encuestaRepo
    ) {
    }

    public function generarHashAlumno(string $correo): string
    {
        // Keep compatibility with existing answers. The UI treats this as
        // confidential/pseudonymous data, not as irreversible anonymity.
        return hash('sha256', strtolower(trim($correo)));
    }

    public function alumnoYaRespondioEncuesta(array $data): bool
    {
        $correo = trim((string) ($data['correo'] ?? ''));
        $sesionId = $data['sesion_id'] ?? null;
        $encuestaId = $data['encuesta_id'] ?? null;
        $cursoId = $data['curso_id'] ?? null;

        if ($correo === '') {
            throw new InvalidArgumentException('correo requerido');
        }

        $hashCorreo = $this->generarHashAlumno($correo);

        if ($encuestaId !== null && !is_numeric($encuestaId)) {
            throw new InvalidArgumentException('encuesta_id inválido');
        }

        if ($sesionId !== null && !is_numeric($sesionId)) {
            throw new InvalidArgumentException('sesion_id inválido');
        }

        if ($cursoId !== null && !is_numeric($cursoId)) {
            throw new InvalidArgumentException('curso_id inválido');
        }

        if ($sesionId) {
            return $this->repo->alumnoYaRespondioEncuestaSesion(
                (int) $sesionId,
                $hashCorreo,
                $encuestaId ? (int) $encuestaId : null
            );
        }

        if ($cursoId) {
            $encuesta = $encuestaId
                ? $this->encuestaRepo->obtener((int) $encuestaId)
                : $this->encuestaRepo->obtenerEncuestaActivaPorTipo(TipoEncuesta::CURSO);

            if (!$encuesta || !TipoEncuesta::esCurso((int) $encuesta->tipo)) {
                throw new InvalidArgumentException('encuesta final no disponible');
            }

            return $this->repo->alumnoYaRespondioEncuestaCursoPorCurso(
                (int) $encuesta->id,
                (int) $cursoId,
                $hashCorreo
            );
        }

        throw new InvalidArgumentException('sesion_id o curso_id requerido');
    }

    public function registrarEncuesta(array $data): array
    {
        $encuestaId = (int) ($data['encuesta_id'] ?? 0);
        $cursoId = (int) ($data['curso_id'] ?? 0);
        $sesionId = (int) ($data['sesion_id'] ?? 0);
        $correo = trim((string) ($data['correo'] ?? ''));

        $encuesta = $this->encuestaRepo->obtener($encuestaId);
        if (!$encuesta) {
            return $this->failure('Encuesta no encontrada');
        }

        $contexto = $this->repo->obtenerContextoSesion($sesionId);
        if (!$contexto || (int) $contexto->curso_edicion_id !== $cursoId) {
            return $this->failure('La sesión no pertenece al curso indicado');
        }

        if (!$this->repo->alumnoInscritoEnCurso($cursoId, $correo)) {
            return $this->failure('El alumno no pertenece a este curso', 403);
        }

        $validation = $this->validarRespuestas($encuestaId, (array) ($data['respuestas'] ?? []));
        if (!$validation['ok']) {
            return $validation;
        }

        $hash = $this->generarHashAlumno($correo);
        $isCourseSurvey = TipoEncuesta::esCurso((int) $encuesta->tipo);
        $scopeType = $isCourseSurvey ? 'course' : 'session';
        $scopeId = $isCourseSurvey ? $cursoId : $sesionId;

        $alreadyAnswered = $isCourseSurvey
            ? $this->repo->alumnoYaRespondioEncuestaCursoPorCurso($encuestaId, $cursoId, $hash)
            : $this->repo->alumnoYaRespondioEncuestaSesion($sesionId, $hash, $encuestaId);

        if ($alreadyAnswered) {
            return $this->failure('La encuesta ya fue respondida', 409);
        }

        try {
            $respuestaId = $this->repo->guardarEncuestaCompleta([
                'encuesta_id' => $encuestaId,
                'curso_edicion_sesion_id' => $sesionId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'alumno_hash' => $hash,
                'respuestas' => $validation['respuestas'],
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return $this->failure('La encuesta ya fue respondida', 409);
            }

            throw $e;
        }

        return [
            'ok' => true,
            'respuesta_id' => $respuestaId,
        ];
    }

    public function puedeConsultarCurso(int $cursoId, string $rol, string $correo): bool
    {
        if (in_array(strtolower($rol), ['admin', 'administrador'], true)) {
            return true;
        }

        return $correo !== '' && $this->repo->usuarioGestionaCurso($cursoId, $correo);
    }

    public function puedeConsultarSesion(int $sesionId, string $rol, string $correo): bool
    {
        $contexto = $this->repo->obtenerContextoSesion($sesionId);

        return $contexto
            ? $this->puedeConsultarCurso((int) $contexto->curso_edicion_id, $rol, $correo)
            : false;
    }

    public function alumnoPuedeAcceder(int $cursoId, ?int $sesionId, string $correo): bool
    {
        if (!$this->repo->alumnoInscritoEnCurso($cursoId, $correo)) {
            return false;
        }

        if ($sesionId === null) {
            return true;
        }

        $contexto = $this->repo->obtenerContextoSesion($sesionId);

        return $contexto && (int) $contexto->curso_edicion_id === $cursoId;
    }

    public function contarRespondidasSesion(int $sesionId): int
    {
        return $this->repo->contarRespondidasSesion($sesionId);
    }

    public function obtenerDetalleResultadosEncuestaPorSesion(int $cursoEdicionId): array
    {
        return $this->repo->obtenerDetalleResultadosEncuestaPorSesion($cursoEdicionId);
    }

    private function validarRespuestas(int $encuestaId, array $respuestas): array
    {
        $questions = collect($this->repo->preguntasEncuesta($encuestaId))->keyBy(fn ($item) => (int) $item->id);

        if ($questions->isEmpty()) {
            return $this->failure('La encuesta no tiene preguntas configuradas');
        }

        $normalized = [];

        foreach ($questions as $questionId => $question) {
            $answer = isset($respuestas[$questionId]) ? (array) $respuestas[$questionId] : [];
            $type = (int) $question->tipo_respuesta;
            $hasAnswer = false;
            $normalizedAnswer = [
                'valor_escala' => null,
                'opcion_id' => null,
                'texto_respuesta' => null,
            ];

            if ($type === 1 && isset($answer['valor_escala']) && $answer['valor_escala'] !== '') {
                $value = filter_var($answer['valor_escala'], FILTER_VALIDATE_INT);
                $min = (int) ($question->min_valor ?? 1);
                $max = (int) ($question->max_valor ?? 5);
                if ($value === false || $value < $min || $value > $max) {
                    return $this->failure("Respuesta inválida para la pregunta {$questionId}");
                }
                $normalizedAnswer['valor_escala'] = $value;
                $hasAnswer = true;
            } elseif ($type === 2) {
                $text = trim((string) ($answer['texto_respuesta'] ?? ''));
                if (mb_strlen($text) > 4000) {
                    return $this->failure("La respuesta de la pregunta {$questionId} es demasiado extensa");
                }
                if ($text !== '') {
                    $normalizedAnswer['texto_respuesta'] = $text;
                    $hasAnswer = true;
                }
            } elseif ($type === 3 && isset($answer['opcion_id']) && $answer['opcion_id'] !== '') {
                $optionId = filter_var($answer['opcion_id'], FILTER_VALIDATE_INT);
                if ($optionId === false || !$this->repo->opcionPertenecePregunta($optionId, $questionId)) {
                    return $this->failure("Opción inválida para la pregunta {$questionId}");
                }
                $normalizedAnswer['opcion_id'] = $optionId;
                $hasAnswer = true;
            }

            if ((bool) $question->obligatoria && !$hasAnswer) {
                return $this->failure("La pregunta {$questionId} es obligatoria");
            }

            if ($hasAnswer) {
                $normalized[$questionId] = $normalizedAnswer;
            }
        }

        $unexpected = array_diff(array_map('intval', array_keys($respuestas)), $questions->keys()->all());
        if ($unexpected !== []) {
            return $this->failure('La respuesta contiene preguntas que no pertenecen a la encuesta');
        }

        return ['ok' => true, 'respuestas' => $normalized];
    }

    private function failure(string $message, int $status = 422): array
    {
        return ['ok' => false, 'mensaje' => $message, 'status' => $status];
    }
}
