<?php

namespace Tests\Feature\Backoffice;

use App\Services\GoogleDriveService;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SessionVideoChatAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.api_servicios.base_url' => 'https://api.example.invalid',
            'services.api_servicios.token' => 'synthetic-test-token',
            'services.api_servicios.retry_times' => 1,
        ]);
        Http::preventStrayRequests();
    }

    private function authSession(string $role): array
    {
        return [
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ID => 37,
            AuthSessionKeys::USER_EMAIL => 'reader@example.invalid',
            AuthSessionKeys::USER_NAME => 'Test Reader',
            AuthSessionKeys::USER_ROLE => $role,
            AuthSessionKeys::AULA_ROLE => $role,
        ];
    }

    public function test_student_and_teacher_preview_and_download_use_scoped_content_endpoint(): void
    {
        Http::fake(['https://api.example.invalid/v1/sesiones/10/video/content' => Http::response([
            'status' => 'ready', 'file_id' => 'fixture-video',
            'chat' => ['file_id' => 'fixture-chat', 'title' => 'class.txt'],
        ])]);
        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('downloadTextFile')->with('fixture-chat')->times(9)
                ->andReturn(['content' => 'Fixture class chat', 'filename' => 'class.txt']);
            $mock->shouldNotReceive('getVideoStatus');
            $mock->shouldNotReceive('deleteFile');
        });
        foreach (['alumno', 'student', 'docente'] as $role) {
            $this->withSession($this->authSession($role))->getJson('/courses/sessions/10/video/chat/preview')
                ->assertOk()->assertJsonPath('content', 'Fixture class chat');
            $this->withSession($this->authSession($role))->get('/courses/sessions/10/video/chat/preview')
                ->assertOk()->assertContent('Fixture class chat')
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
            $this->withSession($this->authSession($role))->get('/courses/sessions/10/video/chat/download')
                ->assertOk()->assertContent('Fixture class chat')
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
            Http::assertSent(fn ($request) => $request->url() === 'https://api.example.invalid/v1/sesiones/10/video/content'
                && $request->header('X-USER-ROL') === [$role]
                && $request->header('X-USER-EMAIL') === ['reader@example.invalid']
                && count($request->header('X-INTERNAL-SERVICE-TOKEN')) === 1);
        }
        Http::assertSentCount(9);
    }

    public function test_foreign_course_denial_is_propagated_without_reading_drive(): void
    {
        Http::fake(['https://api.example.invalid/v1/sesiones/20/video/content' => Http::response([
            'ok' => false, 'message' => 'No autorizado para este curso',
        ], 403)]);
        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldNotReceive('downloadTextFile');
        });
        foreach (['preview', 'download'] as $action) {
            $this->withSession($this->authSession('alumno'))
                ->get('/courses/sessions/20/video/chat/'.$action)->assertForbidden();
        }
    }

    public function test_content_poll_is_available_to_student_but_management_status_is_not(): void
    {
        Http::fake(['https://api.example.invalid/v1/sesiones/10/video/content' => Http::response([
            'status' => 'processing', 'file_id' => 'fixture-video', 'chat' => null,
        ])]);
        $this->withSession($this->authSession('alumno'))->getJson('/courses/sessions/10/video/content')
            ->assertOk()->assertJsonPath('status', 'processing');
        $this->withSession($this->authSession('alumno'))->getJson('/backoffice/courses/1/sessions/10/video/status')
            ->assertForbidden();
        Http::assertSentCount(1);
    }
}
