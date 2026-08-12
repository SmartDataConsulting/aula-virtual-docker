<?php

namespace Tests\Feature;

use Tests\TestCase;

class LayoutMetadataTest extends TestCase
{
    public function test_login_has_a_concise_meta_description(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $description = $this->extractDescription($response->getContent());

        $this->assertNotSame('', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
        $this->assertStringContainsString('Aula Virtual Smart Data', $description);
    }

    public function test_main_layout_has_a_concise_default_meta_description(): void
    {
        $html = view('layouts.main')->render();
        $description = $this->extractDescription($html);

        $this->assertNotSame('', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
        $this->assertStringContainsString('Plataforma de aprendizaje Smart Data', $description);
    }

    private function extractDescription(string $html): string
    {
        preg_match('/<meta\s+name="description"\s+content="([^"]*)">/i', $html, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
