<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Cursos\Scheduling\SessionScheduleResolver;
use Tests\TestCase;

/**
 * Pruebas de seleccion de sesiones por calendario.
 */
class SessionScheduleResolverTest extends TestCase
{
    public function test_selects_next_scheduled_as_current(): void
    {
        $resolver = new SessionScheduleResolver();
        $sessions = collect([
            (object) ['id' => 1, 'date' => '2026-02-01', 'state' => 'past'],
            (object) ['id' => 2, 'date' => '2026-02-15', 'state' => 'future'],
            (object) ['id' => 3, 'date' => '2026-03-01', 'state' => 'future'],
        ]);

        [$normalized, $selected] = $resolver->resolve($sessions, null);

        $this->assertSame(2, $selected->id);
        $this->assertSame('current', $normalized->firstWhere('id', 2)->state);
        $this->assertSame('past', $normalized->firstWhere('id', 1)->state);
        $this->assertSame('future', $normalized->firstWhere('id', 3)->state);
    }

    public function test_prefers_explicit_session_id(): void
    {
        $resolver = new SessionScheduleResolver();
        $sessions = collect([
            (object) ['id' => 1, 'date' => '2026-02-01', 'state' => 'past'],
            (object) ['id' => 2, 'date' => '2026-02-15', 'state' => 'future'],
            (object) ['id' => 3, 'date' => '2026-03-01', 'state' => 'future'],
        ]);

        [, $selected] = $resolver->resolve($sessions, 3);

        $this->assertSame(3, $selected->id);
    }

    public function test_selects_first_when_no_future_and_no_session_id(): void
    {
        $resolver = new SessionScheduleResolver();
        $sessions = collect([
            (object) ['id' => 5, 'state' => 'past'],
            (object) ['id' => 6, 'state' => 'past'],
        ]);

        [, $selected] = $resolver->resolve($sessions, null);

        $this->assertSame(5, $selected->id);
    }
}
