<?php

namespace Tests\Feature\Backoffice;

use App\Services\Support\ServiceResult;
use App\Services\VideoService;
use App\Support\AuthSessionKeys;
use Tests\TestCase;

class SessionVideoReadOnlyTest extends TestCase
{
    private function authSession(string $role): array
    {
        return [
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ID => 37,
            AuthSessionKeys::USER_EMAIL => 'teacher@example.invalid',
            AuthSessionKeys::USER_NAME => 'Test User',
            AuthSessionKeys::JWT_TOKEN => null,
            AuthSessionKeys::USER_ROLE => $role,
        ];
    }

    private function renderPanel(string $role, string $status = 'ready', bool $chat = true): string
    {
        $this->session($this->authSession($role));
        return view('backoffice.courses.partials.session-video', [
            'course' => (object) ['id' => 39],
            'session' => (object) [
                'id' => 1528,
                'video_status' => $status,
                'video_drive_file_id' => 'fixture-video',
                'video_chat_drive_file_id' => $chat ? 'fixture-chat' : null,
            ],
        ])->render();
    }

    private function assertNoWriteControls(string $html): void
    {
        foreach (['deleteVideoBtn', 'data-delete-video-chat', 'uploadVideoBtn', 'uploadVideoChatBtn', 'videoChatInput', 'videoInput', 'cancelUploadBtn'] as $control) {
            self::assertStringNotContainsString($control, $html);
        }
    }

    public function test_teacher_ready_video_keeps_read_actions_without_write_controls(): void
    {
        foreach (['docente', 'profesor'] as $role) {
            $html = $this->renderPanel($role);
            self::assertStringContainsString('data-can-write-video="0"', $html);
            $this->assertNoWriteControls($html);
            self::assertStringContainsString('Ver grabación', $html);
            self::assertStringContainsString('Ver chat', $html);
            self::assertStringContainsString('Descargar TXT', $html);
        }
    }

    public function test_teacher_without_chat_or_video_never_receives_upload_controls(): void
    {
        $this->assertNoWriteControls($this->renderPanel('docente', 'ready', false));
        $this->assertNoWriteControls($this->renderPanel('docente', 'missing', false));
    }

    public function test_processing_container_retains_capability_for_polling_transition(): void
    {
        foreach (['docente' => '0', 'admin' => '1', 'operador' => '1'] as $role => $capability) {
            $html = $this->renderPanel($role, 'processing');
            self::assertStringContainsString('id="videoUploadContainer"', $html);
            self::assertStringContainsString('data-can-write-video="'.$capability.'"', $html);
        }
    }

    public function test_admin_and_operator_keep_video_and_chat_management(): void
    {
        foreach (['admin', 'operador'] as $role) {
            $html = $this->renderPanel($role);
            self::assertStringContainsString('data-can-write-video="1"', $html);
            self::assertStringContainsString('deleteVideoBtn', $html);
            self::assertStringContainsString('data-delete-video-chat', $html);
            $withoutChat = $this->renderPanel($role, 'ready', false);
            self::assertStringContainsString('uploadVideoChatBtn', $withoutChat);
            self::assertStringContainsString('videoChatInput', $withoutChat);
            $missing = $this->renderPanel($role, 'missing', false);
            self::assertStringContainsString('data-can-write-video="1"', $missing);
            self::assertStringContainsString('uploadVideoBtn', $missing);
        }
    }

    public function test_dynamic_render_contract_gates_every_write_control_on_server_capability(): void
    {
        // No JS test runner exists. Check the actual renderer's guarded fragments.
        $source = file_get_contents(resource_path('js/video.js'));
        self::assertSame(1, preg_match('/function renderVideoPlayer\(.*?\n}\n/s', $source, $matches));
        $renderer = $matches[0];
        self::assertStringContainsString("const canWriteVideo = container.dataset.canWriteVideo === '1';", $renderer);
        foreach (['deleteChatHtml' => 'data-delete-video-chat', 'deleteVideoHtml' => 'deleteVideoBtn'] as $fragment => $control) {
            self::assertSame(1, preg_match('/const '.$fragment.' = canWriteVideo\s*\? `[^`]*'.preg_quote($control, '/').'[^`]*`\s*: \'\';/s', $renderer, $guard));
            $renderer = str_replace($guard[0], '', $renderer);
            self::assertStringNotContainsString($control, $renderer, 'No unguarded '.$control);
            self::assertStringContainsString('${'.$fragment.'}', $renderer);
        }
        self::assertSame(1, preg_match('/: canWriteVideo \? `[^`]*` : \'\';/s', $renderer, $uploadBranch));
        foreach (['videoChatInput', 'uploadVideoChatBtn', 'Agregar chat'] as $control) {
            self::assertStringContainsString($control, $uploadBranch[0]);
            self::assertStringNotContainsString($control, str_replace($uploadBranch[0], '', $renderer));
        }
        self::assertStringContainsString('Ver grabación', $renderer);
        self::assertStringContainsString('Ver chat', $renderer);
        self::assertStringContainsString('Descargar TXT', $renderer);
        self::assertStringNotContainsString('docente', $renderer);
    }

    public function test_teacher_manual_delete_requests_are_blocked_before_video_service(): void
    {
        $this->mock(VideoService::class, function ($mock) {
            $mock->shouldNotReceive('deleteVideo');
            $mock->shouldNotReceive('deleteChatTranscript');
        });
        foreach (['', '/chat'] as $suffix) {
            $this->withSession($this->authSession('docente'))
                ->withHeader('Accept', 'application/json')
                ->delete('/backoffice/courses/39/sessions/1528/video'.$suffix)
                ->assertForbidden();
        }
    }

    public function test_admin_delete_chat_reaches_service_without_real_drive_deletion(): void
    {
        $this->mock(VideoService::class, function ($mock) {
            $mock->shouldReceive('deleteChatTranscript')->once()->with(1528)
                ->andReturn(ServiceResult::success(['status' => 'deleted']));
        });
        $this->withSession($this->authSession('admin'))
            ->withHeader('Accept', 'application/json')
            ->delete('/backoffice/courses/39/sessions/1528/video/chat')
            ->assertOk();
    }
}
