<?php

namespace Tests\Unit;

use App\Support\Navigation;
use PHPUnit\Framework\TestCase;

class NavigationRoleLabelTest extends TestCase
{
    public function test_administrator_course_label_describes_the_full_catalog(): void
    {
        $this->assertSame('Cursos', Navigation::coursesLabel('admin'));
        $this->assertSame('Cursos', Navigation::coursesLabel('administrador'));
    }

    public function test_non_administrator_course_label_keeps_personal_context(): void
    {
        $this->assertSame('Mis Cursos', Navigation::coursesLabel('operador'));
        $this->assertSame('Mis Cursos', Navigation::coursesLabel('alumno'));
    }
}
