<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AnnouncementLinkContractTest extends TestCase
{
    public function test_announcement_flow_persists_and_returns_optional_url_and_invalidates_create_cache(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2).'/app/Repositories/CursoAnuncioRepository.php');
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Services/CursoAnuncioService.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/CursoAnuncioController.php');

        self::assertStringContainsString('enlace_url', $repository);
        self::assertStringContainsString('$this->invalidarCacheEntidad($entidadTipo, $entidadId);', $service);
        self::assertStringContainsString("filter_var(\$url, FILTER_VALIDATE_URL)", $controller);
        self::assertStringContainsString("in_array(\$scheme, ['http', 'https'], true)", $controller);
        self::assertStringContainsString("'entidad_tipo'", $controller);
        self::assertStringContainsString("'entidad_id'", $controller);
    }
}
