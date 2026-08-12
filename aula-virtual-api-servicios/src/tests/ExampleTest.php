<?php

namespace Tests;

class ExampleTest extends TestCase
{
    public function test_login_requires_email(): void
    {
        $this->post('/v1/login', []);

        $this->assertResponseStatus(400);
        $this->seeJson([
            'error' => 'email requerido',
        ]);
    }

    public function test_courses_requires_email_for_student_role(): void
    {
        $token = env('INTERNAL_SERVICE_TOKEN', 'change-me');

        $this->get('/v1/cursos', [
            'X-INTERNAL-SERVICE-TOKEN' => $token,
            'X-USER-ROL' => 'alumno',
        ]);

        $this->assertResponseStatus(400);
        $this->seeJson([
            'error' => 'correo requerido',
        ]);
    }
}
