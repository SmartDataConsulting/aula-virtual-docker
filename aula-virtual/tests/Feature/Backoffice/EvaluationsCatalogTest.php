<?php

namespace Tests\Feature\Backoffice;

use App\Http\Controllers\Backoffice\CoursesController;
use App\Services\AnnouncementService;
use App\Services\AttendanceService;
use App\Services\ChatService;
use App\Services\CursoService;
use App\Services\MaterialService;
use App\Services\SesionService;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class EvaluationsCatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_empty_query_returns_a_six_item_paginator(): void
    {
        $data = $this->runCatalog();

        $this->assertInstanceOf(LengthAwarePaginator::class, $data['cursos']);
        $this->assertSame(6, $data['cursos']->perPage());
        $this->assertSame(7, $data['cursos']->total());
        $this->assertCount(6, $data['cursos']->items());
        $this->assertSame('', $data['search']);
    }

    public function test_search_returns_only_matching_courses(): void
    {
        $data = $this->runCatalog(['search' => 'azure']);

        $this->assertSame(2, $data['cursos']->total());
        $this->assertSame([1, 7], collect($data['cursos']->items())->pluck('curso_id')->all());
        $this->assertSame('azure', $data['search']);
    }

    public function test_search_without_matches_returns_an_empty_paginator(): void
    {
        $data = $this->runCatalog(['search' => 'sin-coincidencias']);

        $this->assertInstanceOf(LengthAwarePaginator::class, $data['cursos']);
        $this->assertSame(0, $data['cursos']->total());
        $this->assertTrue($data['cursos']->isEmpty());
        $this->assertSame(0, $data['totalCursos']);
    }

    public function test_second_page_and_search_are_preserved_by_the_paginator(): void
    {
        $paginator = $this->runCatalog(['search' => 'curso', 'page' => 2])['cursos'];

        $this->assertSame(2, $paginator->currentPage());
        $this->assertCount(1, $paginator->items());
        $this->assertSame(7, $paginator->first()['curso_id']);
        $this->assertSame(['search' => 'curso'], $paginator->getOptions()['query']);
        $this->assertStringContainsString('search=curso', $paginator->url(1));
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function runCatalog(array $query = []): array
    {
        $courseService = Mockery::mock(CursoService::class);
        $courseService->shouldReceive('listarCursosParaEvaluaciones')
            ->once()
            ->andReturn(ServiceResult::success(['cursos' => $this->courses()]));

        $controller = new CoursesController(
            $courseService,
            Mockery::mock(SesionService::class),
            Mockery::mock(MaterialService::class),
            Mockery::mock(AnnouncementService::class),
            Mockery::mock(ChatService::class),
            Mockery::mock(AttendanceService::class)
        );

        $request = Request::create('/backoffice/evaluations', 'GET', $query);
        $session = $this->app['session']->driver();
        $session->flush();
        $session->put([
            AuthSessionKeys::USER_EMAIL => 'admin@local',
            AuthSessionKeys::USER_ROLE => 'admin',
        ]);
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return $controller->evaluaciones($request)->getData();
    }

    /** @return array<int, array<string, mixed>> */
    private function courses(): array
    {
        return [
            ['curso_id' => 1, 'nombre' => 'Curso Azure', 'edicion' => '1', 'docente' => 'Ana'],
            ['curso_id' => 2, 'nombre' => 'Curso Docker', 'edicion' => '2', 'docente' => 'Bruno'],
            ['curso_id' => 3, 'nombre' => 'Curso Laravel', 'edicion' => '3', 'docente' => 'Carla'],
            ['curso_id' => 4, 'nombre' => 'Curso PHP', 'edicion' => '4', 'docente' => 'Diego'],
            ['curso_id' => 5, 'nombre' => 'Curso Redis', 'edicion' => '5', 'docente' => 'Elena'],
            ['curso_id' => 6, 'nombre' => 'Curso Nginx', 'edicion' => '6', 'docente' => 'Fabio'],
            ['curso_id' => 7, 'nombre' => 'Curso Azure Avanzado', 'edicion' => '7', 'docente' => 'Gabriela'],
        ];
    }
}
