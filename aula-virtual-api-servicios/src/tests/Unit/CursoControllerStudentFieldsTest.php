<?php

namespace Tests\Unit;

use App\Http\Controllers\CursoController;
use App\Services\CursoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class CursoControllerStudentFieldsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_maps_canonical_student_fields(): void
    {
        $student = $this->mapStudent((object) [
            'id' => 15,
            'NOMBRES' => 'Ana',
            'APELLIDOS' => 'Torres',
            'alumno' => 'Ana Torres',
            'CORREO_PERSONAL' => 'ana@example.test',
            'correo_corporativo' => 'ana@corp.test',
            'TELEFONO' => '999111222',
            'DNI' => '12345678',
            'estado_pago' => 'PAGADO',
            'foto_url' => '/foto/15',
            'solicitud_contacto_estado' => 'ACEPTADA',
            'contacto_publico' => 0,
        ]);

        $this->assertSame(15, $student['id']);
        $this->assertSame('Ana', $student['nombres']);
        $this->assertSame('Torres', $student['apellidos']);
        $this->assertSame('ana@example.test', $student['correo_personal']);
        $this->assertSame('ana@corp.test', $student['correo_corporativo']);
        $this->assertSame('available', $student['contact_status']);
    }

    public function test_maps_supported_lowercase_and_uppercase_variants(): void
    {
        $student = $this->mapStudent((object) [
            'id' => 20,
            'nombres' => 'Bruno',
            'apellidos' => 'Diaz',
            'correo_personal' => 'bruno@example.test',
            'CORREO_CORPORATIVO' => 'bruno@corp.test',
            'telefono' => '988777666',
            'dni' => '87654321',
            'ESTADO_PAGO' => 'PENDIENTE',
            'solicitud_contacto_estado' => 'PENDIENTE',
        ]);

        $this->assertSame('Bruno', $student['nombres']);
        $this->assertSame('Diaz', $student['apellidos']);
        $this->assertSame('bruno@example.test', $student['correo_personal']);
        $this->assertSame('bruno@corp.test', $student['correo_corporativo']);
        $this->assertSame('988777666', $student['telefono']);
        $this->assertSame('87654321', $student['dni']);
        $this->assertSame('PENDIENTE', $student['estado_pago']);
        $this->assertSame('pending', $student['contact_status']);
    }

    public function test_missing_optional_fields_use_current_defaults_without_notices(): void
    {
        $student = $this->mapStudent((object) []);

        $this->assertSame(0, $student['id']);
        $this->assertSame('', $student['nombres']);
        $this->assertSame('', $student['apellidos']);
        $this->assertSame('Participante', $student['alumno']);
        $this->assertNull($student['correo_personal']);
        $this->assertNull($student['correo_corporativo']);
        $this->assertNull($student['telefono']);
        $this->assertNull($student['dni']);
        $this->assertNull($student['estado_pago']);
        $this->assertNull($student['foto_url']);
        $this->assertSame('private', $student['contact_status']);
        $this->assertSame('Contacto privado', $student['contact_status_label']);
    }

    public function test_canonical_keys_take_precedence_when_both_variants_exist(): void
    {
        $student = $this->mapStudent((object) [
            'NOMBRES' => 'Canonico',
            'nombres' => 'Alternativo',
            'APELLIDOS' => 'Principal',
            'apellidos' => 'Secundario',
            'CORREO_PERSONAL' => 'canonical@example.test',
            'correo_personal' => 'alternate@example.test',
            'correo_corporativo' => 'preferred@corp.test',
            'CORREO_CORPORATIVO' => 'fallback@corp.test',
            'TELEFONO' => '111',
            'telefono' => '222',
            'DNI' => '333',
            'dni' => '444',
            'estado_pago' => 'CANONICO',
            'ESTADO_PAGO' => 'ALTERNATIVO',
        ]);

        $this->assertSame('Canonico', $student['nombres']);
        $this->assertSame('Principal', $student['apellidos']);
        $this->assertSame('canonical@example.test', $student['correo_personal']);
        $this->assertSame('preferred@corp.test', $student['correo_corporativo']);
        $this->assertSame('111', $student['telefono']);
        $this->assertSame('333', $student['dni']);
        $this->assertSame('CANONICO', $student['estado_pago']);
    }

    /** @return array<string, mixed> */
    private function mapStudent(object $row): array
    {
        $service = Mockery::mock(CursoService::class);
        $service->shouldReceive('listarAlumnosCurso')
            ->once()
            ->with(45, 'requester@example.test')
            ->andReturn([$row]);

        $request = Request::create('/cursos/45/alumnos', 'GET', [], [], [], [
            'HTTP_X_USER_EMAIL' => 'requester@example.test',
        ]);
        $this->app->instance('request', $request);

        $response = (new CursoController($service))->listarAlumnosCurso(45);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $payload);

        return $payload[0];
    }
}
