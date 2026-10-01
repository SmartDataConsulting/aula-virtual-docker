<?php

namespace Tests\Feature\Backoffice;

use App\Http\Controllers\Backoffice\CertificatesController;
use App\Services\CursoService;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class CertificatesSearchTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_empty_query_keeps_all_courses(): void
    {
        $data = $this->runSearch('');

        $this->assertSame('', $data['search']);
        $this->assertSame(3, $data['courses']->total());
    }

    public function test_one_character_query_does_not_filter_courses(): void
    {
        $this->assertSame(3, $this->runSearch('A')['courses']->total());
    }

    public function test_two_character_query_activates_filtering(): void
    {
        $courses = $this->runSearch('AZ')['courses'];

        $this->assertSame(1, $courses->total());
        $this->assertSame('Azure Fundamentals', $courses->first()['title']);
    }

    public function test_search_returns_the_matching_course(): void
    {
        $courses = $this->runSearch('DOC-20')['courses'];

        $this->assertSame(1, $courses->total());
        $this->assertSame(20, $courses->first()['id']);
    }

    public function test_search_without_matches_returns_an_empty_paginator(): void
    {
        $courses = $this->runSearch('sin-coincidencias')['courses'];

        $this->assertInstanceOf(LengthAwarePaginator::class, $courses);
        $this->assertSame(0, $courses->total());
        $this->assertTrue($courses->isEmpty());
    }

    /** @return array<string, mixed> */
    private function runSearch(string $search): array
    {
        $service = Mockery::mock(CursoService::class);
        $service->shouldReceive('listarCursosParaCertificados')
            ->once()
            ->andReturn(ServiceResult::success(['cursos' => $this->courses()]));

        $request = Request::create('/backoffice/certificates', 'GET', ['search' => $search]);
        $session = $this->app['session']->driver();
        $session->flush();
        $session->put([
            AuthSessionKeys::USER_EMAIL => 'admin@local',
            AuthSessionKeys::USER_ROLE => 'admin',
        ]);
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return (new CertificatesController($service))->index($request)->getData();
    }

    /** @return array<int, array<string, mixed>> */
    private function courses(): array
    {
        return [
            ['id' => 10, 'title' => 'Azure Fundamentals', 'code' => 'AZ-10', 'edition' => '1', 'teacher' => 'Ana'],
            ['id' => 20, 'title' => 'Docker Profesional', 'code' => 'DOC-20', 'edition' => '2', 'teacher' => 'Bruno'],
            ['id' => 30, 'title' => 'Laravel Aplicado', 'code' => 'LAR-30', 'edition' => '3', 'teacher' => 'Carla'],
        ];
    }
}
