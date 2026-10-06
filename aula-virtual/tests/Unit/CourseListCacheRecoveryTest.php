<?php

namespace Tests\Unit;

use App\Services\CursoService;
use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CourseListCacheRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session([AuthSessionKeys::USER_ROLE => 'admin']);
        PerformanceCache::forget(PerformanceCache::courseListKey('main', 'admin', ''));
    }

    private function key(): string
    {
        return PerformanceCache::NAMESPACE.PerformanceCache::courseListKey('main', 'admin', '');
    }

    public function test_service_recovers_empty_stale_using_summary_in_same_request(): void
    {
        Cache::put($this->key().':stale', ServiceResult::success(['courses' => collect()]), 3600);
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('resumenBackoffice')->once()->with('', 'admin')
            ->andReturn(ServiceResult::success(['ok' => true, 'courses' => [['id' => 7, 'nombre' => 'Recovered']]]));
        $client->shouldNotReceive('listarCursos');
        $result = (new CursoService($client))->listarCursos('');
        self::assertSame(7, $result->data()['courses']->first()['id']);
        self::assertSame($result, Cache::get($this->key()));
        self::assertSame($result, Cache::get($this->key().':stale'));
    }

    public function test_degraded_summary_and_error_payload_fallback_cannot_cache_empty_success(): void
    {
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('resumenBackoffice')->once()->andReturn(ServiceResult::failure(['message' => 'Unavailable'], 503));
        $client->shouldReceive('listarCursos')->once()->with('', false)
            ->andReturn(ServiceResult::success(['ok' => false, 'message' => 'Failure']));
        $result = (new CursoService($client))->listarCursos('');
        self::assertFalse($result->ok());
        self::assertSame(502, $result->status());
        self::assertFalse(Cache::has($this->key()));
        self::assertFalse(Cache::has($this->key().':stale'));
    }

    public function test_invalid_summary_uses_valid_empty_fallback(): void
    {
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('resumenBackoffice')->once()->andReturn(ServiceResult::success(['message' => 'Unexpected response']));
        $client->shouldReceive('listarCursos')->once()->with('', false)->andReturn(ServiceResult::success([]));
        $result = (new CursoService($client))->listarCursos('');
        self::assertTrue($result->ok());
        self::assertCount(0, $result->data()['courses']);
        self::assertSame($result, Cache::get($this->key()));
    }

    public function test_successful_empty_summary_does_not_use_fallback(): void
    {
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('resumenBackoffice')->once()->andReturn(ServiceResult::success(['ok' => true, 'courses' => []]));
        $client->shouldNotReceive('listarCursos');
        $result = (new CursoService($client))->listarCursos('');
        self::assertTrue($result->ok());
        self::assertCount(0, $result->data()['courses']);
        self::assertSame($result, Cache::get($this->key().':stale'));
    }

    public function test_student_fallback_preserves_suggestions_and_valid_courses(): void
    {
        session([AuthSessionKeys::USER_ROLE => 'alumno']);
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('resumenAlumno')->once()->with('student@example.test')->andReturn(ServiceResult::failure([], 503));
        $client->shouldReceive('listarCursos')->once()->with('student@example.test', true)
            ->andReturn(ServiceResult::success([['id' => 8, 'nombre' => 'Student course']]));
        $result = (new CursoService($client))->listarCursos('student@example.test');
        self::assertTrue($result->ok());
        self::assertSame(8, $result->data()['courses']->first()['id']);
    }
}
