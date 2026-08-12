<?php

namespace Tests\Unit;

use Tests\TestCase;

class SessionMeetingPresentationTest extends TestCase
{
    public function test_student_meeting_component_does_not_render_access_code(): void
    {
        $session = (object) [
            'meeting' => (object) [
                'scheduled' => true,
                'availability' => 'open',
                'can_join' => true,
                'join_url' => 'https://zoom.us/j/123',
                'meeting_id' => null,
                'access_code' => null,
            ],
        ];

        $view = $this->view('components.session-meeting', [
            'session' => $session,
            'privileged' => false,
        ]);

        $view->assertSee('Ingresar a la clase');
        $view->assertDontSee('Copiar acceso');
        $view->assertDontSee('data-copy-meeting', false);
    }

    public function test_upcoming_meeting_explains_when_access_opens(): void
    {
        $session = (object) [
            'meeting' => (object) [
                'scheduled' => true,
                'availability' => 'upcoming',
                'can_join' => false,
                'join_url' => null,
                'starts_at' => '2026-08-05T19:00:00-05:00',
            ],
        ];

        $view = $this->view('components.session-meeting', [
            'session' => $session,
            'privileged' => false,
        ]);

        $view->assertSee('Disponible 15 minutos antes');
        $view->assertDontSee('href=', false);
    }
}
