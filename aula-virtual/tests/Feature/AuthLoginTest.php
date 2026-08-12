<?php

namespace Tests\Feature;

use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pruebas de autenticacion y sesion.
 */
class AuthLoginTest extends TestCase
{
    public function test_login_success_sets_session(): void
    {
        config([
            'services.wordpress.base_url' => 'https://wp.test',
            'services.wordpress.jwt_token_path' => '/token',
        ]);

        Http::fake([
            'https://wp.test/token' => Http::response([
                'token' => 'jwt-token',
                'user_email' => 'user@test.com',
                'user_display_name' => 'User Test',
            ], 200),
        ]);

        $response = $this->post('/login', [
            'username' => 'wordpress-user',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/mis-cursos');
        $response->assertSessionHas(AuthSessionKeys::LOGGED_IN, true);
        $response->assertSessionHas(AuthSessionKeys::USER_EMAIL, 'user@test.com');
        $response->assertSessionHas(AuthSessionKeys::JWT_TOKEN, 'jwt-token');
    }

    public function test_login_failure_returns_neutral_message(): void
    {
        config([
            'services.wordpress.base_url' => 'https://wp.test',
            'services.wordpress.jwt_token_path' => '/token',
        ]);

        Http::fake([
            'https://wp.test/token' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $response = $this->from('/login')->post('/login', [
            'username' => 'wordpress-user',
            'password' => 'bad-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
        $this->assertSame(
            'El usuario y/o la contraseña son incorrectos.',
            session('errors')->first('username')
        );
    }

    public function test_login_lockout_after_max_attempts(): void
    {
        config([
            'services.wordpress.base_url' => 'https://wp.test',
            'services.wordpress.jwt_token_path' => '/token',
            'auth.gateway.lockout.max_attempts' => 2,
            'auth.gateway.lockout.window_seconds' => 60,
            'auth.gateway.lockout.penalty_initial_seconds' => 60,
            'auth.gateway.lockout.penalty_repeat_seconds' => 60,
            'auth.gateway.lockout.penalty_ttl' => 300,
        ]);

        Http::fake([
            'https://wp.test/token' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $this->post('/login', [
            'username' => 'locked-user',
            'password' => 'bad',
        ]);

        $response = $this->from('/login')->post('/login', [
            'username' => 'locked-user',
            'password' => 'bad',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString(
            'Demasiados intentos',
            session('errors')->first('username')
        );
    }
}
