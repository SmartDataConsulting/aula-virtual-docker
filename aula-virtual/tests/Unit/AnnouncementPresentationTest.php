<?php

namespace Tests\Unit;

use Tests\TestCase;

class AnnouncementPresentationTest extends TestCase
{
    public function test_announcement_component_renders_safe_optional_link(): void
    {
        $this->withViewErrors([]);

        $view = $this->view('components.announcements-list', [
            'course' => (object) ['id' => 10],
            'session' => (object) ['id' => 20],
            'announcements' => collect([(object) [
                'id' => 1,
                'title' => 'Material adicional',
                'content' => 'Revisar antes de la clase.',
                'url' => 'https://example.com/resource',
                'type' => 'informativo',
            ]]),
            'mode' => 'view',
        ]);

        $view->assertSee('Abrir enlace');
        $view->assertSee('target="_blank"', false);
        $view->assertSee('rel="noopener noreferrer"', false);
    }
}
