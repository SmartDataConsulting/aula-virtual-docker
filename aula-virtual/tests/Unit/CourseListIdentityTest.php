<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CursoService;
use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CourseListIdentityTest extends TestCase
{
    public function test_course_edition_is_recovered_from_the_api_code(): void
    {
        Cache::flush();

        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('listarCursosParaCalificaciones')
            ->once()
            ->andReturn(ServiceResult::success([
                [
                    'id' => 32,
                    'curso_id' => 32,
                    'codigo' => 'Edicion 7',
                    'nombre' => 'Ciberseguridad en Azure',
                    'docente' => 'Quispe, Carlos',
                    'total_sesiones' => 15,
                    'sesiones_realizadas' => 3,
                    'alumnos_inscritos' => 8,
                ],
            ]));

        $result = (new CursoService($client))->listarCursosParaCalificaciones();
        $course = $result->data()['cursos']->first();

        $this->assertSame('7', $course['edition']);
        $this->assertSame('3 de 15', $course['progress_label']);
        $this->assertSame(8, $course['students_count']);
    }

    public function test_certificate_courses_keep_the_same_identification_data(): void
    {
        Cache::flush();

        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('listarCursosParaCalificaciones')
            ->once()
            ->andReturn(ServiceResult::success([
                [
                    'id' => 32,
                    'curso_id' => 32,
                    'codigo' => 'Edicion 7',
                    'nombre' => 'Ciberseguridad en Azure',
                    'docente' => 'Quispe, Carlos',
                    'total_sesiones' => 15,
                    'sesiones_realizadas' => 3,
                    'alumnos_inscritos' => 8,
                ],
            ]));

        $result = (new CursoService($client))->listarCursosParaCertificados();
        $course = $result->data()['cursos']->first();

        $this->assertSame('7', $course['edition']);
        $this->assertSame('3 de 15', $course['progress_label']);
        $this->assertSame(8, $course['students_count']);
    }
}
