<?php

namespace Tests\Feature\Backoffice;

use App\Services\MaterialService;
use App\Services\Support\ServiceResult;
use App\Support\AuthSessionKeys;
use Tests\TestCase;

class StudentMaterialRoutesTest extends TestCase
{
    public function test_student_preview_and_download_reach_material_service(): void
    {
        // A sentinel error proves Portal permission passes without faking a download.
        $this->mock(MaterialService::class, function ($mock) {
            $mock->shouldReceive('descargarMaterial')->with(100)->times(4)
                ->andReturn(ServiceResult::failure(['message' => 'authorization-test-sentinel'], 418));
        });
        foreach (['alumno', 'student'] as $role) {
            foreach (['preview', 'download'] as $action) {
                $this->withSession([
                    AuthSessionKeys::LOGGED_IN => true,
                    AuthSessionKeys::USER_ID => 37,
                    AuthSessionKeys::USER_EMAIL => 'student@example.invalid',
                    AuthSessionKeys::USER_NAME => 'Test Student',
                    AuthSessionKeys::USER_ROLE => $role,
                    AuthSessionKeys::AULA_ROLE => $role,
                    AuthSessionKeys::JWT_TOKEN => null,
                ])->get('/backoffice/materials/100/'.$action)->assertStatus(418);
            }
        }
    }
}
