<?php

namespace Tests\Feature;

use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_email_login_falls_back_to_wordpress_when_core_user_does_not_exist(): void
    {
        config([
            'services.api_servicios.base_url' => 'https://api.test',
            'services.api_servicios.token' => 'internal-token',
            'services.wordpress.base_url' => 'https://wp.test',
            'services.wordpress.jwt_token_path' => '/token',
        ]);

        Http::fake([
            'https://api.test/v1/login' => Http::response([
                'error' => 'usuario no encontrado',
                'reason' => 'not_found',
            ], 404),
            'https://wp.test/token' => Http::response([
                'token' => 'jwt-token',
                'user_email' => 'alumno@test.com',
                'user_display_name' => 'Alumno Test',
            ], 200),
        ]);

        $response = $this->post('/login', [
            'username' => 'alumno@test.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/mis-cursos');
        $response->assertSessionHas(AuthSessionKeys::LOGGED_IN, true);
        $response->assertSessionHas(AuthSessionKeys::USER_EMAIL, 'alumno@test.com');
        $response->assertSessionHas(AuthSessionKeys::USER_ROLE, 'alumno');
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
            'El usuario y/o la contrasena son incorrectos.',
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
