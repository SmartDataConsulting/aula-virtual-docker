<?php

namespace Tests\Unit;

use App\Repositories\GenDocsSurveyRepository;
use App\Services\GenDocsSurveyService;
use Carbon\CarbonImmutable;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class GenDocsSurveyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['cache']->flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_summary_failure_does_not_break_course_sessions(): void
    {
        $repository = Mockery::mock(GenDocsSurveyRepository::class);
        $repository->shouldReceive('summariesForSessions')->once()->andThrow(new \RuntimeException('schema unavailable'));
        $service = new GenDocsSurveyService($repository);
        $session = (object) ['id' => 15];

        $result = $service->attachSummaries([$session], 'student@example.com');

        $this->assertSame([], $result[0]->surveys);
        $this->assertNull($result[0]->encuesta_id);
        $this->assertFalse($result[0]->encuesta_respondida);
    }

    public function test_final_rows_with_the_same_uuid_count_as_one_submission(): void
    {
        $repository = Mockery::mock(GenDocsSurveyRepository::class);
        $repository->shouldReceive('resultsForCourse')->once()->andReturn([
            $this->resultRow(1, 'submission-1', 7),
            array_replace($this->resultRow(2, 'submission-1', 7, 2), [
                'participant_key' => hash('sha256', 'student1@example.com'),
            ]),
            $this->resultRow(3, null, 8),
        ]);
        $repository->shouldReceive('enrolledStudentsCount')->once()->andReturn(10);
        $service = new GenDocsSurveyService($repository);

        $result = $service->results(34, 'admin');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(3, $result['response_rows']);
        $this->assertSame(20.0, $result['summary']['participation_percent']);
        $this->assertFalse($result['summary']['roster_mismatch']);
    }

    public function test_filters_update_summary_questions_and_responses_together(): void
    {
        $repository = Mockery::mock(GenDocsSurveyRepository::class);
        $repository->shouldReceive('resultsForCourse')->once()->andReturn([
            $this->resultRow(1, null, 1),
            $this->resultRow(2, null, 2),
        ]);
        $repository->shouldReceive('enrolledStudentsCount')->once()->andReturn(2);
        $service = new GenDocsSurveyService($repository);

        $result = $service->results(51, 'admin', '', ['session' => 2]);

        $this->assertSame(1, $result['summary']['submissions']);
        $this->assertSame(1, $result['summary']['sessions']);
        $this->assertCount(1, $result['questions']);
        $this->assertCount(1, $result['responses']['data']);
        $this->assertSame(2, $result['responses']['data'][0]['nro_sesion']);
    }

    public function test_teacher_segment_with_less_than_five_submissions_is_protected(): void
    {
        $repository = Mockery::mock(GenDocsSurveyRepository::class);
        $repository->shouldReceive('resultsForCourse')->once()->andReturn([
            $this->resultRow(1, null, 1),
            $this->resultRow(2, null, 2),
        ]);
        $repository->shouldReceive('enrolledStudentsCount')->once()->andReturn(10);
        $repository->shouldReceive('collaboratorIdByEmail')->once()->with('teacher@example.com')->andReturn(9);
        $service = new GenDocsSurveyService($repository);

        $result = $service->results(52, 'operador', 'teacher@example.com');

        $this->assertTrue($result['summary']['privacy_protected']);
        $this->assertSame([], $result['questions']);
        $this->assertSame([], $result['comments']);
        $this->assertSame([], $result['responses']['data']);
    }

    public function test_response_persistence_uses_an_explicit_lima_timestamp(): void
    {
        $connection = new class {
            public array $rows = [];

            public function table(string $table): object
            {
                return new class($this, $table) {
                    public function __construct(private object $connection, private string $table)
                    {
                    }

                    public function insertGetId(array $data): int
                    {
                        $this->connection->rows[$this->table][] = $data;

                        return 101;
                    }

                    public function insert(array $data): bool
                    {
                        $this->connection->rows[$this->table][] = $data;

                        return true;
                    }
                };
            }
        };
        $repository = Mockery::mock(GenDocsSurveyRepository::class);
        $repository->shouldReceive('connection')->twice()->andReturn($connection);
        $service = new GenDocsSurveyService($repository);
        $method = new ReflectionMethod($service, 'insertResponses');
        $method->setAccessible(true);

        $ids = $method->invoke($service, [
            'kind' => 'session',
            'course_id' => 34,
            'session_id' => 2349,
            'form_id' => 1,
            'session_number' => 1,
            'questions' => [[
                'id' => 10,
                'code' => 'satisfaccion',
                'type' => 'scale',
            ]],
        ], 'student@example.com', [
            'teacher_id' => 19,
            'answers' => ['satisfaccion' => 5],
            'teacher_answers' => [],
        ]);

        $response = $connection->rows['encuesta_respuestas'][0];
        $detail = $connection->rows['encuesta_respuesta_detalles'][0];

        $this->assertSame([101], $ids);
        $this->assertInstanceOf(CarbonImmutable::class, $response['submitted_at']);
        $this->assertSame('America/Lima', $response['submitted_at']->getTimezone()->getName());
        $this->assertSame($response['submitted_at'], $response['created_at']);
        $this->assertSame($response['created_at'], $detail['created_at']);
    }

    private function resultRow(int $id, ?string $submission, int $session, int $teacherId = 9): array
    {
        return [
            'respuesta_id' => $id,
            'submission_uuid' => $submission,
            'participant_key' => hash('sha256', 'student'.$id.'@example.com'),
            'nro_sesion' => $session,
            'kind' => $submission ? 'final' : 'session',
            'formulario_id' => 1,
            'formulario' => 'Encuesta',
            'formulario_version' => 1,
            'docente_id' => $teacherId,
            'docente' => 'Docente Uno',
            'respondent' => 'student'.$id.'@example.com',
            'answers' => [[
                'question_id' => 1,
                'code' => 'satisfaccion',
                'label' => 'Satisfaccion',
                'type' => 'scale',
                'scope' => 'course',
                'value' => 5,
            ]],
        ];
    }
}
