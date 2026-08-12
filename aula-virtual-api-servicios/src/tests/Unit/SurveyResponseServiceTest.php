<?php

namespace Tests\Unit;

use App\Repositories\EncuestaRepository;
use App\Repositories\EncuestaRespuestaRepository;
use App\Services\EncuestaRespuestaService;
use Tests\TestCase;

class SurveyResponseServiceTest extends TestCase
{
    public function test_multiple_choice_answer_is_validated_and_persisted(): void
    {
        $responses = $this->createMock(EncuestaRespuestaRepository::class);
        $surveys = $this->createMock(EncuestaRepository::class);

        $surveys->method('obtener')->with(5)->willReturn((object) ['id' => 5, 'tipo' => 1]);
        $responses->method('obtenerContextoSesion')->with(44)->willReturn((object) [
            'curso_edicion_id' => 10,
        ]);
        $responses->method('alumnoInscritoEnCurso')->with(10, 'student@example.com')->willReturn(true);
        $responses->method('preguntasEncuesta')->with(5)->willReturn([
            (object) ['id' => 8, 'tipo_respuesta' => 3, 'obligatoria' => 1],
        ]);
        $responses->method('opcionPertenecePregunta')->with(21, 8)->willReturn(true);
        $responses->method('alumnoYaRespondioEncuestaSesion')->willReturn(false);
        $responses->expects(self::once())
            ->method('guardarEncuestaCompleta')
            ->with(self::callback(function (array $payload): bool {
                return $payload['scope_type'] === 'session'
                    && $payload['scope_id'] === 44
                    && $payload['respuestas'][8]['opcion_id'] === 21;
            }))
            ->willReturn(101);

        $result = (new EncuestaRespuestaService($responses, $surveys))->registrarEncuesta([
            'encuesta_id' => 5,
            'curso_id' => 10,
            'sesion_id' => 44,
            'correo' => 'student@example.com',
            'respuestas' => [8 => ['opcion_id' => 21]],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(101, $result['respuesta_id']);
    }

    public function test_rejects_an_option_from_another_question(): void
    {
        $responses = $this->createMock(EncuestaRespuestaRepository::class);
        $surveys = $this->createMock(EncuestaRepository::class);

        $surveys->method('obtener')->willReturn((object) ['id' => 5, 'tipo' => 1]);
        $responses->method('obtenerContextoSesion')->willReturn((object) ['curso_edicion_id' => 10]);
        $responses->method('alumnoInscritoEnCurso')->willReturn(true);
        $responses->method('preguntasEncuesta')->willReturn([
            (object) ['id' => 8, 'tipo_respuesta' => 3, 'obligatoria' => 1],
        ]);
        $responses->method('opcionPertenecePregunta')->willReturn(false);
        $responses->expects(self::never())->method('guardarEncuestaCompleta');

        $result = (new EncuestaRespuestaService($responses, $surveys))->registrarEncuesta([
            'encuesta_id' => 5,
            'curso_id' => 10,
            'sesion_id' => 44,
            'correo' => 'student@example.com',
            'respuestas' => [8 => ['opcion_id' => 99]],
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(422, $result['status']);
    }

    public function test_final_survey_check_uses_course_scope_without_a_session(): void
    {
        $responses = $this->createMock(EncuestaRespuestaRepository::class);
        $surveys = $this->createMock(EncuestaRepository::class);

        $surveys->method('obtenerEncuestaActivaPorTipo')->with(2)->willReturn((object) [
            'id' => 9,
            'tipo' => 2,
        ]);
        $responses->expects(self::once())
            ->method('alumnoYaRespondioEncuestaCursoPorCurso')
            ->with(9, 10, hash('sha256', 'student@example.com'))
            ->willReturn(true);

        $answered = (new EncuestaRespuestaService($responses, $surveys))->alumnoYaRespondioEncuesta([
            'correo' => 'student@example.com',
            'curso_id' => 10,
        ]);

        self::assertTrue($answered);
    }
}
