<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CursoService;
use App\Services\SesionService;
use App\Services\Http\ApiServiciosClient;
use App\Services\Support\ServiceResult;
use Mockery;
use Tests\TestCase;

/**
 * Pruebas de normalizacion de cursos y sesiones.
 */
class CursoServiceTest extends TestCase
{
    public function test_normalizes_sessions_payload(): void
    {
        $client = Mockery::mock(ApiServiciosClient::class);
        $client->shouldReceive('listarSesionesCurso')
            ->once()
            ->with(10, 'alumno')
            ->andReturn(ServiceResult::success([[
                'id' => 10,
                'numero' => 2,
                'fecha' => '2026-02-10',
                'estado' => 'programada',
                'duracion' => 135,
                'hora_inicio' => '08:00',
                'hora_fin' => '10:15',
            ]]));

        $service = new SesionService($client);
        $result = $service->listarSesionesCurso(10, 'alumno');

        $this->assertTrue($result->ok());
        $sessions = $result->data()['sessions'];
        $this->assertCount(1, $sessions);

        $session = $sessions->first();
        $this->assertSame(10, $session->id);
        $this->assertSame(2, $session->number);
        $this->assertSame('Sesión 2', $session->title);
        $this->assertSame('programada', $session->state);
        $this->assertSame(135, $session->duration);
        $this->assertSame('08:00', $session->start_time);
        $this->assertSame('10:15', $session->end_time);
    }

    public function test_normalizes_anuncios_payload(): void
    {
        $client = Mockery::mock(ApiServiciosClient::class);

        $client->shouldReceive('listarAnunciosCurso')
            ->once()
            ->with(10)
            ->andReturn(ServiceResult::success([
                [
                    'id' => 1,
                    'titulo' => 'Cambio de horario – Sesión 14',
                    'contenido' => 'La clase se moverá a las 20:00',
                    'tipo' => 'importante',
                    'creado_por' => 1,
                    'creado_en' => '2026-02-13 12:39:19',
                    'actualizado_en' => '2026-02-13 12:39:19',
                ],
            ]));

        $service = new CursoService($client);
        $result = $service->listarAnunciosCurso(10);

        $this->assertTrue($result->ok());

        $anuncios = $result->data()['anuncios'];
        $this->assertCount(1, $anuncios);

        $anuncio = $anuncios->first();

        $this->assertSame(1, $anuncio['id']);
        $this->assertSame('Cambio de horario – Sesión 14', $anuncio['titulo']);
        $this->assertSame('La clase se moverá a las 20:00', $anuncio['contenido']);
        $this->assertSame('importante', $anuncio['tipo']);
    }

}
