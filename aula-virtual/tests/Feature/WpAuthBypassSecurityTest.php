<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WpAuthBypassSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'internal-token',
            'services.api_servicios.retry_times' => 1,
            'services.wordpress.base_url' => 'https://wp.test',
            'services.wordpress.jwt_token_path' => '/token',
            'services.wordpress.retry_times' => 1,
        ]);
    }

    public function test_local_without_bypass_requires_password_and_uses_normal_wordpress_login(): void
    {
        $this->configureBypass('local', false);

        Http::fake([
            'https://wp.test/token' => Http::response([
                'token' => 'jwt-token',
                'user_email' => 'local@test.com',
                'user_display_name' => 'Local Test',
            ]),
        ]);

        $this->post('/login', ['username' => 'local-user'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing(AuthSessionKeys::LOGGED_IN);

        Http::assertNothingSent();

        $this->post('/login', [
            'username' => 'local-user',
            'password' => 'secret',
        ])
            ->assertRedirect('/mis-cursos')
            ->assertSessionHas(AuthSessionKeys::LOGGED_IN, true)
            ->assertSessionHas(AuthSessionKeys::USER_ROLE, 'alumno');
    }

    public function test_local_bypass_on_core_404_creates_only_student_session_and_student_headers(): void
    {
        $this->configureBypass('local', true);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.test/v1/login') {
                return Http::response(['reason' => 'not_found'], 404);
            }

            if (str_starts_with($request->url(), 'https://api.test/v1/alumno/resumen')) {
                return Http::response(['courses' => []], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->post('/login', [
            'username' => 'bypass.student@test.com',
            'role' => 'admin',
        ])
            ->assertRedirect('/mis-cursos')
            ->assertSessionHas(AuthSessionKeys::LOGGED_IN, true)
            ->assertSessionHas(AuthSessionKeys::USER_EMAIL, 'bypass.student@test.com')
            ->assertSessionHas(AuthSessionKeys::USER_NAME, 'bypass.student@test.com')
            ->assertSessionHas(AuthSessionKeys::USER_ROLE, 'alumno')
            ->assertSessionHas(AuthSessionKeys::JWT_TOKEN, 'bypassed-token');

        $this->get('/mis-cursos')->assertOk();

        Http::assertSent(fn (Request $request) =>
            str_starts_with($request->url(), 'https://api.test/v1/alumno/resumen')
            && $request->hasHeader('X-USER-ROL', 'alumno')
            && $request->hasHeader('X-USER-EMAIL', 'bypass.student@test.com')
        );
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://wp.test/'));
    }

    public function test_local_bypass_fails_closed_for_core_401(): void
    {
        $this->assertLocalBypassRejectsCoreStatus(401);
    }

    public function test_local_bypass_fails_closed_for_core_403(): void
    {
        $this->assertLocalBypassRejectsCoreStatus(403);
    }

    public function test_local_bypass_fails_closed_for_core_5xx(): void
    {
        $this->assertLocalBypassRejectsCoreStatus(503);
    }

    public function test_production_rejects_requested_bypass_during_boot(): void
    {
        $this->assertUnsafeEnvironmentIsRejected('production');
    }

    public function test_staging_rejects_requested_bypass_during_boot(): void
    {
        $this->assertUnsafeEnvironmentIsRejected('staging');
    }

    public function test_testing_accepts_requested_bypass_as_effective(): void
    {
        $this->configureBypass('testing', true);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('auth.gateway.wordpress_bypass.requested'));
        $this->assertTrue(config('auth.gateway.wordpress_bypass.effective'));
    }

    public function test_inconsistent_effective_bypass_configuration_is_rejected(): void
    {
        $this->configureBypass('production', false);
        config(['auth.gateway.wordpress_bypass.effective' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('estado efectivo de WP_AUTH_BYPASS es inconsistente');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_without_bypass_starts_and_keeps_normal_staff_login(): void
    {
        $this->configureBypass('production', false);
        (new AppServiceProvider($this->app))->boot();

        Http::fake([
            'https://api.test/v1/login' => Http::response([
                'id' => 10,
                'nombre' => 'Admin Seguro',
                'email' => 'admin@test.com',
                'role_id' => 1,
                'aula_role' => 'admin',
            ], 200),
        ]);

        $this->post('/login', [
            'username' => 'admin@test.com',
            'password' => 'correct-password',
        ])
            ->assertRedirect('/backoffice/courses')
            ->assertSessionHas(AuthSessionKeys::LOGGED_IN, true)
            ->assertSessionHas(AuthSessionKeys::USER_ROLE, 'admin');
    }

    public function test_wrong_student_password_in_production_never_creates_a_bypass_session(): void
    {
        $this->configureBypass('production', false);

        Http::fake([
            'https://api.test/v1/login' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $this->from('/login')->post('/login', [
            'username' => 'student@test.com',
            'password' => 'wrong-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username')
            ->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
            ->assertSessionMissing(AuthSessionKeys::USER_ROLE);

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://wp.test/'));
    }

    public function test_unknown_student_in_production_never_creates_a_bypass_session(): void
    {
        $this->configureBypass('production', false);

        Http::fake([
            'https://api.test/v1/login' => Http::response(['reason' => 'not_found'], 404),
            'https://wp.test/token' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $this->from('/login')->post('/login', [
            'username' => 'unknown@test.com',
            'password' => 'wrong-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username')
            ->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
            ->assertSessionMissing(AuthSessionKeys::USER_ROLE);
    }

    public function test_wrong_staff_password_still_fails_without_changing_role(): void
    {
        $this->configureBypass('production', false);

        Http::fake([
            'https://api.test/v1/login' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $this->from('/login')->post('/login', [
            'username' => 'admin@test.com',
            'password' => 'wrong-password',
            'role' => 'alumno',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username')
            ->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
            ->assertSessionMissing(AuthSessionKeys::USER_ROLE);
    }

    public function test_wordpress_failures_never_create_a_session_without_effective_bypass(): void
    {
        $this->configureBypass('production', false);

        $failures = [
            'unauthorized' => Http::response(['message' => 'Invalid'], 401),
            'forbidden' => Http::response(['message' => 'Forbidden'], 403),
            'timeout' => Http::failedConnection('cURL error 28: Operation timed out'),
            'unavailable' => Http::failedConnection('Connection refused'),
            'missing-token' => Http::response(['user_email' => 'missing@test.com'], 200),
            'invalid-response' => Http::response('not-json', 200, ['Content-Type' => 'text/plain']),
        ];

        foreach ($failures as $case => $failure) {
            Http::fake(['https://wp.test/token' => $failure]);

            $this->from('/login')->post('/login', [
                'username' => "{$case}-user",
                'password' => 'secret',
            ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors('username')
                ->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
                ->assertSessionMissing(AuthSessionKeys::USER_ROLE);
        }
    }

    public function test_login_view_uses_effective_bypass_for_password_required_attribute(): void
    {
        $this->configureBypass('local', false);
        $requiredContent = $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]+id="password"[^>]+required/', $requiredContent);

        $this->configureBypass('local', true);
        $optionalContent = $this->get('/login')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="password"[^>]+required/', $optionalContent);
    }

    private function assertLocalBypassRejectsCoreStatus(int $status): void
    {
        $this->configureBypass('local', true);

        Http::fake([
            'https://api.test/v1/login' => Http::response(['message' => 'Rejected'], $status),
        ]);

        $this->from('/login')->post('/login', [
            'username' => "student-{$status}@test.com",
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username')
            ->assertSessionMissing(AuthSessionKeys::LOGGED_IN)
            ->assertSessionMissing(AuthSessionKeys::USER_ROLE);

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://wp.test/'));
    }

    private function assertUnsafeEnvironmentIsRejected(string $environment): void
    {
        $this->configureBypass($environment, true);
        Http::fake();

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail("{$environment} accepted WP_AUTH_BYPASS=true.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('WP_AUTH_BYPASS', $exception->getMessage());
            $this->assertStringContainsString('local/testing', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function configureBypass(string $environment, bool $requested): void
    {
        $allowedEnvironments = ['local', 'testing'];

        config([
            'app.env' => $environment,
            'auth.gateway.wordpress_bypass.requested' => $requested,
            'auth.gateway.wordpress_bypass.effective' => $requested
                && in_array($environment, $allowedEnvironments, true),
            'auth.gateway.wordpress_bypass.allowed_environments' => $allowedEnvironments,
        ]);
    }
}
