<?php

namespace Tests\Feature;

use App\Services\VideoService;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AulaProfileSecurityTest extends TestCase
{
    private function teacherSession(): array
    {
        return [AuthSessionKeys::LOGGED_IN => true, AuthSessionKeys::USER_ROLE => 'operador',
            AuthSessionKeys::AULA_ROLE => 'docente', AuthSessionKeys::USER_EMAIL => 'teacher@example.invalid'];
    }

    public function test_all_old_profiles_require_html_relogin_and_json_chunk_is_401_before_video(): void
    {
        $this->mock(VideoService::class, fn ($mock) => $mock->shouldNotReceive('uploadChunk'));
        foreach (['admin', 'alumno', 'operador'] as $role) {
            $session = [AuthSessionKeys::LOGGED_IN => true, AuthSessionKeys::USER_ROLE => $role,
                AuthSessionKeys::USER_EMAIL => 'old@example.invalid'];
            $this->withSession($session)->get('/backoffice/courses')->assertRedirect('/login')
                ->assertSessionMissing(AuthSessionKeys::USER_ROLE);
            $this->withSession($session)->postJson('/backoffice/courses/10/sessions/100/video/upload-chunk')
                ->assertUnauthorized()->assertJsonPath('code', 'reauthentication_required')
                ->assertSessionMissing(AuthSessionKeys::USER_ROLE);
        }
    }

    public function test_valid_core_credentials_with_unresolved_profile_show_access_error_without_wp_fallback(): void
    {
        config(['services.api_servicios.base_url' => 'https://api.example.invalid', 'services.api_servicios.token' => 'synthetic-test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://api.example.invalid/v1/login' => Http::response([
            'reason' => 'aula_profile_unresolved', 'error' => 'No fue posible determinar el perfil de acceso al Aula Virtual.',
        ], 403)]);
        $this->from('/login')->post('/login', ['username' => 'missing@example.invalid', 'password' => 'fixture-password'])
            ->assertRedirect('/login')->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
            ->assertSessionHasErrors(['username' => 'No fue posible determinar el perfil de acceso al Aula Virtual.']);
        Http::assertSentCount(1);
    }

    public function test_login_keeps_system_operator_separate_from_effective_teacher(): void
    {
        config(['services.api_servicios.base_url' => 'https://api.example.invalid', 'services.api_servicios.token' => 'synthetic-test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://api.example.invalid/v1/login' => Http::response([
            'id' => 1, 'nombre' => 'Fixture Teacher', 'email' => 'teacher@example.invalid',
            'rol' => 'operador', 'role_id' => 2, 'colaborador_id' => 1, 'aula_role' => 'docente',
        ])]);
        $this->post('/login', ['username' => 'teacher@example.invalid', 'password' => 'fixture-password'])
            ->assertRedirect('/backoffice/courses')
            ->assertSessionHas(AuthSessionKeys::USER_ROLE, 'operador')
            ->assertSessionHas(AuthSessionKeys::AULA_ROLE, 'docente');
    }

    public function test_core_login_without_verified_profile_does_not_create_staff_session(): void
    {
        config(['services.api_servicios.base_url' => 'https://api.example.invalid', 'services.api_servicios.token' => 'synthetic-test-token']);
        Http::fake(['https://api.example.invalid/v1/login' => Http::response(['rol' => 'operador', 'email' => 'teacher@example.invalid'])]);
        $this->from('/login')->post('/login', ['username' => 'teacher@example.invalid', 'password' => 'fixture-password'])
            ->assertRedirect('/login')->assertSessionMissing(AuthSessionKeys::LOGGED_IN);
    }

    public function test_effective_teacher_has_read_controls_but_no_video_management(): void
    {
        $this->session($this->teacherSession());
        $html = view('backoffice.courses.partials.session-video', [
            'course' => (object) ['id' => 10], 'session' => (object) ['id' => 100,
                'video_status' => 'ready', 'video_drive_file_id' => 'fixture-video', 'video_chat_drive_file_id' => 'fixture-chat'],
        ])->render();
        foreach (['uploadVideoBtn', 'videoChatInput', 'deleteVideoBtn', 'data-delete-video-chat', 'data-video-management'] as $control) {
            self::assertStringNotContainsString($control, $html);
        }
        foreach (['Ver grabación', 'Ver chat', 'Descargar TXT'] as $label) {
            self::assertStringContainsString($label, $html);
        }
    }

    public function test_effective_teacher_video_writes_are_denied_before_services_or_drive(): void
    {
        $this->mock(VideoService::class, function ($mock) {
            foreach (['startUpload', 'uploadChunk', 'finalizeUpload', 'cancelUpload', 'uploadChatTranscript', 'deleteVideo', 'deleteChatTranscript'] as $method) {
                $mock->shouldNotReceive($method);
            }
        });
        foreach (['start-upload', 'upload-chunk', 'finalize-upload', 'cancel-upload', 'chat'] as $suffix) {
            $this->withSession($this->teacherSession())->postJson('/backoffice/courses/10/sessions/100/video/'.$suffix)->assertForbidden();
        }
        foreach (['', '/chat'] as $suffix) {
            $this->withSession($this->teacherSession())->deleteJson('/backoffice/courses/10/sessions/100/video'.$suffix)->assertForbidden();
        }
    }

    public function test_effective_teacher_cannot_mutate_evaluations_attendance_or_legacy_grading(): void
    {
        foreach ([
            ['POST', '/backoffice/evaluations/10'],
            ['POST', '/backoffice/attendance/sessions/100/sync'],
            ['POST', '/backoffice/attendance/sessions/100/identify'],
            ['PATCH', '/backoffice/attendance/sessions/100/records/1'],
            ['POST', '/backoffice/qualifications/10/notes/subsanation'],
            ['PUT', '/backoffice/qualifications/10/notes/subsanation'],
            ['POST', '/backoffice/qualifications/10/30/deliveries/1/review'],
        ] as [$method, $url]) {
            $this->withSession($this->teacherSession())->json($method, $url)->assertForbidden();
        }
    }

    public function test_old_operator_session_requires_relogin_instead_of_inheriting_write_privileges(): void
    {
        $old = $this->teacherSession();
        unset($old[AuthSessionKeys::AULA_ROLE]);
        $this->mock(VideoService::class, fn ($mock) => $mock->shouldNotReceive('deleteVideo'));
        $this->withSession($old)->delete('/backoffice/courses/10/sessions/100/video')
            ->assertRedirect('/login')->assertSessionMissing(AuthSessionKeys::USER_ROLE);
    }

    public function test_effective_teacher_unrelated_course_is_403_not_an_empty_success_page(): void
    {
        $this->mock(\App\Services\SesionService::class, function ($mock) {
            $mock->shouldReceive('listarSesionesCurso')->once()->with(10, 'docente')
                ->andReturn(\App\Services\Support\ServiceResult::failure(['message' => 'Denied'], 403));
        });
        $this->withSession($this->teacherSession())->get('/backoffice/courses/10/101')->assertForbidden();
    }

    public function test_teacher_session_reads_revalidate_scope_instead_of_reusing_cached_result(): void
    {
        $this->session($this->teacherSession());
        $client = \Mockery::mock(\App\Services\Http\ApiServiciosClient::class);
        $client->shouldReceive('listarSesionesCurso')->once()->with(10, 'docente')
            ->andReturn(\App\Services\Support\ServiceResult::success([]));
        $client->shouldReceive('listarSesionesCurso')->once()->with(10, 'docente')
            ->andReturn(\App\Services\Support\ServiceResult::failure(['message' => 'Denied'], 403));
        $service = new \App\Services\SesionService($client);
        self::assertTrue($service->listarSesionesCurso(10, 'operador')->ok());
        self::assertSame(403, $service->listarSesionesCurso(10, 'operador')->status());
    }

    public function test_stale_operator_profile_is_revalidated_before_drive_and_failure_is_closed(): void
    {
        config(['services.api_servicios.base_url' => 'https://api.example.invalid', 'services.api_servicios.token' => 'synthetic-test-token', 'services.api_servicios.retry_times' => 1]);
        Http::preventStrayRequests();
        $this->mock(VideoService::class, fn ($mock) => $mock->shouldNotReceive('deleteVideo'));
        $session = $this->teacherSession();
        $session[AuthSessionKeys::AULA_ROLE] = 'operador';
        Http::fake(['https://api.example.invalid/v1/aula/identity' => Http::response(['aula_role' => 'docente'])]);
        $this->withSession($session)->deleteJson('/backoffice/courses/10/sessions/100/video')->assertForbidden()
            ->assertSessionHas(AuthSessionKeys::AULA_ROLE, 'docente');
        Http::fake(['https://api.example.invalid/v1/aula/identity' => Http::response(['message' => 'Unavailable'], 503)]);
        $this->withSession($session)->deleteJson('/backoffice/courses/10/sessions/100/video')->assertForbidden();
    }
}
