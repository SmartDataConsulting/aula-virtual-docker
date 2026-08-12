<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EvaluationParticipantQueryContractTest extends TestCase
{
    public function test_participant_query_uses_the_real_evaluation_id_and_deduplicates_enrollments(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Repositories/EvaluacionRepository.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('WHERE cse.evaluacion_id = ?', $source);
        $this->assertStringContainsString('cse.id AS curso_sesion_evaluacion_id', $source);
        $this->assertStringContainsString('$participantsByEmail', $source);
        $this->assertStringContainsString("strtolower(trim((string) (\$row->CORREO_PERSONAL ?? '')))", $source);
        $this->assertStringNotContainsString('WHERE cse.id = ?', $source);
    }
}
