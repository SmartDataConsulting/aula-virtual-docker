<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecondTeacherCourseScopeTest extends TestCase
{
    private string $repositorySource;
    private string $middlewareSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositorySource = file_get_contents(dirname(__DIR__, 2).'/app/Repositories/CursoRepository.php');
        $this->middlewareSource = file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/CourseScopeMiddleware.php');
    }

    public function test_course_scope_includes_primary_secondary_and_session_teacher_without_join_duplicates(): void
    {
        self::assertStringContainsString('docente_usuario.colaborador_id = ce.docente_id_colaborador', $this->repositorySource);
        self::assertStringContainsString('docente_usuario.colaborador_id = ce.docente2_id_colaborador', $this->repositorySource);
        self::assertStringContainsString('sesion_docente.docente_id = docente_usuario.colaborador_id', $this->repositorySource);
        self::assertStringContainsString('EXISTS (', $this->repositorySource);
        self::assertStringNotContainsString('ON col.id_colaborador = ce.docente_id_colaborador', $this->repositorySource);
    }

    public function test_primary_teacher_is_an_approved_course_scope_source(): void
    {
        self::assertStringContainsString('u.colaborador_id = ce.docente_id_colaborador', $this->middlewareSource);
    }

    public function test_second_teacher_is_an_approved_course_scope_source(): void
    {
        self::assertStringContainsString('u.colaborador_id = ce.docente2_id_colaborador', $this->middlewareSource);
    }

    public function test_teacher_assigned_only_to_one_session_receives_course_wide_scope(): void
    {
        self::assertStringContainsString('sesion_docente.curso_edicion_id = ce.id', $this->repositorySource);
        self::assertStringContainsString('sesion_docente.docente_id = docente_usuario.colaborador_id', $this->repositorySource);
        self::assertStringContainsString('s.curso_edicion_id = ce.id', $this->middlewareSource);
        self::assertStringContainsString('s.docente_id = u.colaborador_id', $this->middlewareSource);
        self::assertStringNotContainsString('sesion_docente.id = ?', $this->repositorySource);
        self::assertStringNotContainsString('s.id = ? AND s.docente_id = u.colaborador_id', $this->middlewareSource);
    }

    public function test_unassigned_teacher_has_no_fallback_course_scope(): void
    {
        self::assertStringContainsString('WHERE LOWER(TRIM(docente_usuario.email)) = LOWER(TRIM(?))', $this->repositorySource);
        self::assertStringContainsString('WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))', $this->middlewareSource);
        self::assertStringNotContainsString('OR 1 = 1', $this->repositorySource);
        self::assertStringNotContainsString('OR 1 = 1', $this->middlewareSource);
    }
}
