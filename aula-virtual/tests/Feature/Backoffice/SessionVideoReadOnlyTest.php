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
            AuthSessionKeys::AULA_ROLE => $role,
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
        foreach (['docente', 'profesor', 'alumno', 'student'] as $role) {
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

    public function test_student_panel_shares_read_only_content_and_chat_without_recording(): void
    {
        $this->session($this->authSession('alumno'));
        $html = view('mis-cursos.partials.panels.video', [
            'course' => (object) ['id' => 39],
            'session' => (object) ['id' => 1528, 'video_status' => 'none', 'video_chat_drive_file_id' => 'fixture-chat'],
        ])->render();
        $this->assertNoWriteControls($html);
        self::assertStringContainsString('Aún no hay grabación disponible', $html);
        self::assertStringContainsString('Chat de la clase', $html);
        self::assertStringContainsString('Ver chat', $html);
        self::assertStringContainsString('Descargar TXT', $html);
        self::assertStringContainsString('/courses/sessions/1528/video/chat/preview', $html);
        self::assertStringContainsString('data-video-content-url=', $html);
    }

    public function test_read_only_initializer_never_restores_upload_state(): void
    {
        $source = file_get_contents(resource_path('js/video.js'));
        self::assertSame(1, preg_match('/function initVideoPanel\(\).*?\n}/s', $source, $matches));
        $initializer = $matches[0];
        self::assertSame(1, preg_match('/if \(container.dataset.canWriteVideo !== \'1\'\) \{.*?return;\s*}/s', $initializer, $guard));
        self::assertStringContainsString('waitForVideoReady()', $guard[0]);
        self::assertLessThan(strpos($initializer, 'restoreUploadStateOnLoad()'), strpos($initializer, $guard[0]));
        self::assertStringContainsString(': container.dataset.videoContentUrl;', $source);
    }

    public function test_assets_use_vite_entries_and_cached_html_is_partitioned_by_viewer(): void
    {
        foreach (['backoffice/courses/show.blade.php', 'mis-cursos/show.blade.php'] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));
            self::assertStringContainsString('@vite(', $source);
            self::assertStringContainsString("'resources/js/video.js'", $source);
            self::assertStringNotContainsString('/build/assets/video-', $source);
        }
        $layout = file_get_contents(resource_path('views/backoffice/courses/partials/layout.blade.php'));
        self::assertStringContainsString('data-workspace-viewer="{{ hash(', $layout);
        $workspace = file_get_contents(resource_path('js/course-workspace.js'));
        self::assertStringContainsString('course-workspace:v3:${root.dataset.workspaceViewer', $workspace);
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
            self::assertSame(1, preg_match('/const '.$fragment.' = canWriteVideo[^\n]*\n\s*\? `[^`]*'.preg_quote($control, '/').'[^`]*`\s*: \'\';/s', $renderer, $guard));
            $renderer = str_replace($guard[0], '', $renderer);
            self::assertStringNotContainsString($control, $renderer, 'No unguarded '.$control);
            self::assertStringContainsString('${'.$fragment.'}', $renderer);
        }
        self::assertSame(1, preg_match('/const managementHtml = canWriteVideo.*?\n    container.classList/s', $renderer, $uploadBranch));
        foreach (['videoChatInput', 'uploadVideoChatBtn', 'Agregar chat'] as $control) {
            self::assertStringContainsString($control, $uploadBranch[0]);
            self::assertStringNotContainsString($control, str_replace($uploadBranch[0], '', $renderer));
        }
        self::assertStringContainsString('Ver grabación', $renderer);
        self::assertStringContainsString('Ver chat', $renderer);
        self::assertStringContainsString('Descargar TXT', $renderer);
        self::assertStringNotContainsString('dataset.role', $renderer);
        self::assertStringNotContainsString("role ===", $renderer);
    }

    public function test_resource_combinations_and_management_are_semantically_separated(): void
    {
        foreach (['admin', 'operador', 'docente', 'alumno', 'student'] as $role) {
            foreach (['missing', 'processing', 'ready', 'error', 'uploading'] as $status) {
                foreach ([false, true] as $chat) {
                    $html = $this->renderPanel($role, $status, $chat);
                    $dom = new \DOMDocument();
                    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                    $xpath = new \DOMXPath($dom);
                    self::assertSame($status === 'ready' ? 1 : 0, $xpath->query('//*[@data-video-resource="recording"]')->length);
                    self::assertSame($chat ? 1 : 0, $xpath->query('//*[@data-video-resource="chat"]')->length);
                    self::assertSame(0, $xpath->query('//*[@data-video-resources]//*[@id="deleteVideoBtn" or @data-delete-video-chat or @type="file"]')->length);
                    $writer = in_array($role, ['admin', 'operador'], true);
                    self::assertSame($writer ? 1 : 0, $xpath->query('//*[@data-video-management]')->length);
                    if (!$writer) {
                        self::assertSame(0, $xpath->query('//*[@data-video-maintenance] | //input[@type="file"] | //button[@id="deleteVideoBtn" or @data-delete-video-chat]')->length);
                    }
                    if ($writer && in_array($status, ['processing', 'uploading'], true)) {
                        self::assertSame(0, $xpath->query('//*[@data-video-maintenance]')->length);
                    }
                    self::assertSame(1, $xpath->query('//*[@id="videoStatus" and @aria-live="polite"]')->length);
                }
            }
        }
    }

    public function test_admin_video_chat_matrix_does_not_offer_duplicate_chat_upload(): void
    {
        foreach ([false, true] as $video) {
            foreach ([false, true] as $chat) {
                $html = $this->renderPanel('admin', $video ? 'ready' : 'missing', $chat);
                $dom = new \DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $xpath = new \DOMXPath($dom);
                self::assertSame($video ? 0 : 1, $xpath->query('//*[@id="uploadVideoBtn"]')->length);
                self::assertSame($video ? 1 : 0, $xpath->query('//*[@data-video-resource="recording"]')->length);
                self::assertSame($chat ? 1 : 0, $xpath->query('//*[@data-video-resource="chat"]')->length);
                self::assertSame($chat ? 0 : 1, $xpath->query('//input[@id="videoChatInput"]')->length);
                self::assertSame($chat ? 0 : 1, $xpath->query('//label[@for="videoChatInput"]')->length);
                self::assertSame($video && !$chat ? 1 : 0, $xpath->query('//*[@id="uploadVideoChatBtn"]')->length);
                if ($chat) {
                    self::assertStringNotContainsString('Adjuntar chat', $html);
                    self::assertStringNotContainsString('Agregar chat', $html);
                }
            }
        }
    }

    public function test_management_note_is_selected_by_caller_and_students_keep_independent_resources(): void
    {
        $note = 'Los recursos de esta sesión son gestionados por administración.';
        self::assertStringContainsString($note, $this->renderPanel('docente'));
        foreach ([false, true] as $video) {
            foreach ([false, true] as $chat) {
                $this->session($this->authSession('alumno'));
                $html = view('mis-cursos.partials.panels.video', [
                    'course' => (object) ['id' => 39],
                    'session' => (object) ['id' => 1528, 'video_status' => $video ? 'ready' : 'none',
                        'video_drive_file_id' => $video ? 'fixture-video' : null,
                        'video_chat_drive_file_id' => $chat ? 'fixture-chat' : null],
                ])->render();
                self::assertStringNotContainsString($note, $html);
                self::assertStringContainsString('data-show-video-management-note="0"', $html);
                self::assertSame($video, str_contains($html, 'Ver grabación'));
                self::assertSame($chat, str_contains($html, 'Ver chat'));
                self::assertSame($chat, str_contains($html, 'Descargar TXT'));
                self::assertStringContainsString('Repasa la clase y consulta los recursos disponibles.', $html);
            }
        }
        $source = file_get_contents(resource_path('js/video.js'));
        self::assertStringContainsString("container.dataset.showVideoManagementNote === '1'", $source);
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
        config(['services.api_servicios.base_url' => 'https://api.example.invalid', 'services.api_servicios.token' => 'synthetic-test-token']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://api.example.invalid/v1/aula/identity' =>
            \Illuminate\Support\Facades\Http::response(['aula_role' => 'admin'])]);
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
