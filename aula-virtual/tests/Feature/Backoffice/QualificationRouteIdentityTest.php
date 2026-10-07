<?php

namespace Tests\Feature\Backoffice;

use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QualificationRouteIdentityTest extends TestCase
{
    public function test_review_controls_are_read_only_for_teacher_and_writable_for_admin_and_operator(): void
    {
        foreach (['docente' => false, 'operador' => true, 'admin' => true] as $profile => $canWrite) {
            session([AuthSessionKeys::USER_ROLE => 'operador', AuthSessionKeys::AULA_ROLE => $profile,
                AuthSessionKeys::USER_EMAIL => 'fixture@example.invalid']);
            $html = view('backoffice.qualifications.evaluate', [
                'courseId' => 34, 'evaluationId' => 6, 'evaluation' => ['nombre' => 'Fixture work'],
                'criteria' => collect([['label' => 'Criterion', 'max_score' => 20]]),
                'participants' => collect(), 'summary' => [], 'search' => '', 'error' => null, 'reviewError' => null,
                'selectedDeliveryId' => 2, 'selectedParticipant' => ['name' => 'Fixture student', 'delivery_id' => 2],
                'nextParticipant' => ['delivery_id' => 3], 'previousParticipant' => null,
                'review' => ['rubric' => ['criteria' => [['id' => 1, 'name' => 'Criterion', 'max_score' => 20]]]],
            ])->render();
            if ($canWrite) {
                self::assertStringContainsString('name="save_action"', $html);
                self::assertStringContainsString('Guardar y siguiente', $html);
                self::assertStringNotContainsString('<fieldset disabled', $html);
            } else {
                self::assertStringNotContainsString('name="save_action"', $html);
                self::assertStringNotContainsString('Guardar y siguiente', $html);
                self::assertStringContainsString('<fieldset disabled', $html);
            }
        }
    }

    public function test_administrative_operator_can_save_review_and_legacy_teacher_can_read(): void
    {
        config(['services.api_servicios.base_url' => 'https://api.test', 'services.api_servicios.token' => 'synthetic-test-token']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.test/v1/evaluaciones/6/entregas/2/revision' => Http::response([], 200),
            'https://api.test/v1/calificaciones/cursos/34' => Http::response(['course' => ['id' => 34, 'name' => 'Fixture'], 'evaluations' => []]),
        ]);
        $this->withSession([
            AuthSessionKeys::LOGGED_IN => true, AuthSessionKeys::USER_ID => 1,
            AuthSessionKeys::USER_ROLE => 'operador', AuthSessionKeys::AULA_ROLE => 'operador',
            AuthSessionKeys::USER_EMAIL => 'operator@example.invalid',
        ])->postJson('/backoffice/qualifications/34/6/deliveries/2/review', [
            'save_action' => 'stay', 'criteria' => [1 => ['level' => 5, 'comment' => 'Fixture']],
        ])->assertOk()->assertJsonPath('ok', true);
        $this->withSession([
            AuthSessionKeys::LOGGED_IN => true, AuthSessionKeys::USER_ROLE => 'docente',
            AuthSessionKeys::AULA_ROLE => 'docente', AuthSessionKeys::USER_EMAIL => 'legacy@example.invalid',
        ])->get('/backoffice/qualifications/34')->assertOk();
    }

    public function test_legacy_relation_id_redirects_to_real_evaluation_id_and_normalizes_delivery(): void
    {
        Cache::flush();
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);
        Http::fake([
            'https://api.test/v1/calificaciones/cursos/34' => Http::response([
                'course' => ['id' => 34, 'name' => 'Curso'],
                'evaluations' => [[
                    'curso_sesion_evaluacion_id' => 3,
                    'evaluacion_id' => 6,
                    'nombre' => 'Trabajo practico',
                    'tipo_param_id' => 3,
                    'is_work' => true,
                ]],
            ]),
        ]);

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ID => 1,
            AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::AULA_ROLE => 'admin',
        ])->get('/backoffice/qualifications/34/3?entregaId=2,');

        $response->assertRedirect('/backoffice/qualifications/34/6?entregaId=2');
    }

    public function test_saving_without_a_next_delivery_stays_on_the_current_review(): void
    {
        Cache::flush();
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'token-123',
        ]);
        Http::fake([
            'https://api.test/v1/evaluaciones/6/entregas/2/revision' => Http::response([], 200),
        ]);

        $response = $this->withSession([
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ID => 1,
            AuthSessionKeys::USER_EMAIL => 'admin@example.com',
            AuthSessionKeys::USER_ROLE => 'admin',
            AuthSessionKeys::AULA_ROLE => 'admin',
        ])->postJson('/backoffice/qualifications/34/6/deliveries/2/review', [
            'save_action' => 'next',
            'next_delivery_id' => 0,
            'criteria' => [
                1 => ['level' => 5, 'comment' => 'Cumple el criterio.'],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Calificacion guardada correctamente.')
            ->assertJsonPath('saved_delivery_id', 2)
            ->assertJsonPath('redirect_delivery_id', 2)
            ->assertJsonPath('redirect_url', url('/backoffice/qualifications/34/6?entregaId=2'));
    }

    public function test_ajax_review_form_does_not_use_the_global_loader(): void
    {
        $template = file_get_contents(resource_path('views/backoffice/qualifications/evaluate.blade.php'));
        $script = file_get_contents(resource_path('js/backoffice-qualifications-evaluate.js'));

        $this->assertStringContainsString('data-no-global-loader', $template);
        $this->assertStringContainsString('window.hideGlobalLoader?.();', $script);
    }
}
