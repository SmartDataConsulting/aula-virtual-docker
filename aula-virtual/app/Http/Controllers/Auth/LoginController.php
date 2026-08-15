<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    protected $redirectTo = '/mis-cursos';

    public function __construct(private readonly AuthService $authService)
    {
    }

    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $wpAuthBypass = filter_var(env('WP_AUTH_BYPASS', false), FILTER_VALIDATE_BOOLEAN);

        $request->validate([
            'username' => ['required', 'string'],
            'password' => [$wpAuthBypass ? 'nullable' : 'required'],
        ]);

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password', '');

        Log::debug('Login attempt started.', [
            'username_type' => str_contains($username, '@') ? 'email' : 'username',
        ]);

        $lockSeconds = $this->getRemainingLockSeconds($username);
        if ($lockSeconds > 0) {
            return back()->withErrors([
                'username' => $this->formatLockMessage($lockSeconds),
            ])->onlyInput('username');
        }

        if ($wpAuthBypass) {
            return $this->loginWithBypass($request, $username, $password);
        }

        if (str_contains($username, '@')) {
            $coreResponse = $this->tryCoreLogin($request, $username, $password);

            if ($coreResponse !== null) {
                return $coreResponse;
            }
        }

        return $this->loginWithWordpress($request, $username, $password);
    }

    public function logout(Request $request)
    {
        $request->session()->forget(AuthSessionKeys::all());
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function loginWithBypass(Request $request, string $username, string $password)
    {
        $coreResult = $this->authService->authenticateWithCore($username, $password);

        Log::debug('Core login bypass result.', [
            'status' => $coreResult->status(),
            'ok' => $coreResult->ok(),
        ]);

        if ($coreResult->ok()) {
            $payload = (array) $coreResult->data();
            $role = $this->resolveCoreRole($payload);

            if ($role === null) {
                Log::warning('Login failed (core role missing).', [
                    'ip' => $request->ip(),
                    'status' => $coreResult->status(),
                    'role_id' => $payload['role_id'] ?? null,
                ]);

                return back()->withErrors([
                    'username' => 'Tu usuario no tiene un rol configurado. Contacta a soporte.',
                ])->onlyInput('username');
            }

            $this->clearLoginAttempts($username);
            $this->storeSessionData($request, $payload, $role, null);

            Log::info('Login succeeded (core bypass).', [
                'ip' => $request->ip(),
                'role' => $role,
            ]);

            return redirect($this->redirectForRole($role));
        }

        if ($coreResult->status() !== 404) {
            $this->registerFailedLogin($username);

            return back()->withErrors([
                'username' => $this->invalidCredentialsMessage(),
            ])->onlyInput('username');
        }

        $payload = [
            'email' => $username,
            'nombre' => $username,
            'token' => 'bypassed-token',
        ];

        $this->clearLoginAttempts($username);
        $this->storeSessionData($request, $payload, 'alumno', $payload['token']);

        Log::info('Login bypassed as student.', [
            'ip' => $request->ip(),
        ]);

        return redirect('/mis-cursos');
    }

    private function tryCoreLogin(Request $request, string $username, string $password): mixed
    {
        $coreResult = $this->authService->authenticateWithCore($username, $password);

        Log::debug('Core login result.', [
            'status' => $coreResult->status(),
            'ok' => $coreResult->ok(),
        ]);

        if ($coreResult->ok()) {
            $payload = (array) $coreResult->data();
            $role = $this->resolveCoreRole($payload);

            if ($role === null) {
                Log::warning('Login failed (core role missing).', [
                    'ip' => $request->ip(),
                    'status' => $coreResult->status(),
                    'role_id' => $payload['role_id'] ?? null,
                ]);

                return back()->withErrors([
                    'username' => 'Tu usuario no tiene un rol configurado. Contacta a soporte.',
                ])->onlyInput('username');
            }

            $this->clearLoginAttempts($username);
            $this->storeSessionData($request, $payload, $role, null);

            Log::info('Login succeeded (core).', [
                'ip' => $request->ip(),
                'role' => $role,
            ]);

            return redirect($this->redirectForRole($role));
        }

        if ($coreResult->status() === 404) {
            return null;
        }

        $this->registerFailedLogin($username);

        Log::warning('Login failed (core).', [
            'ip' => $request->ip(),
            'status' => $coreResult->status(),
        ]);

        return back()->withErrors([
            'username' => $this->invalidCredentialsMessage(),
        ])->onlyInput('username');
    }

    private function loginWithWordpress(Request $request, string $username, string $password)
    {
        $result = $this->authService->authenticateWithWordpress($username, $password);

        if ($result->ok()) {
            $payload = (array) $result->data();

            if (empty($payload['token'])) {
                return back()->withErrors([
                    'username' => 'No se pudo iniciar sesion. Verifica tus credenciales.',
                ])->onlyInput('username');
            }

            $this->clearLoginAttempts($username);
            $this->storeSessionData($request, $payload, 'alumno', $payload['token']);

            Log::info('Login succeeded (wordpress).', [
                'ip' => $request->ip(),
            ]);

            return redirect('/mis-cursos');
        }

        Log::warning('Login failed (wordpress).', [
            'ip' => $request->ip(),
            'status' => $result->status(),
        ]);

        $this->registerFailedLogin($username);

        $lockSeconds = $this->getRemainingLockSeconds($username);
        if ($lockSeconds > 0) {
            return back()->withErrors([
                'username' => $this->formatLockMessage($lockSeconds),
            ])->onlyInput('username');
        }

        return back()->withErrors([
            'username' => $this->resolveAuthError($result->status()),
        ])->onlyInput('username');
    }

    private function storeSessionData(Request $request, array $payload, string $role, ?string $token = null): void
    {
        $role = $this->normalizeRoleName($role);

        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put(AuthSessionKeys::LOGGED_IN, true);
        $request->session()->put(AuthSessionKeys::USER_ID, $payload['id'] ?? $payload['user_id'] ?? null);
        $request->session()->put(AuthSessionKeys::USER_EMAIL, $payload['email'] ?? $payload['user_email'] ?? '');
        $request->session()->put(AuthSessionKeys::USER_NAME, $payload['nombre'] ?? $payload['user_display_name'] ?? null);
        $request->session()->put(AuthSessionKeys::JWT_TOKEN, $token);
        $request->session()->put(AuthSessionKeys::USER_ROLE, $role);
    }

    private function redirectForRole(string $role): string
    {
        return $this->normalizeRoleName($role) === 'alumno' ? '/mis-cursos' : '/backoffice/courses';
    }

    private function resolveCoreRole(array $payload): ?string
    {
        $role = $this->normalizeRoleName((string) ($payload['rol'] ?? $payload['role'] ?? ''));

        if (in_array($role, ['admin', 'operador', 'docente', 'alumno'], true)) {
            return $role;
        }

        $roleId = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;

        return match ($roleId) {
            1 => 'admin',
            2 => 'operador',
            3 => 'docente',
            4 => 'alumno',
            5 => 'admin',
            default => null,
        };
    }

    private function normalizeRoleName(string $role): string
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'administrador' => 'admin',
            'profesor' => 'docente',
            default => $role,
        };
    }

    private function resolveAuthError(int $status): string
    {
        if (in_array($status, [0, 500, 502, 503, 504], true)) {
            return 'No se puede iniciar sesion en este momento. Intenta mas tarde.';
        }

        if (in_array($status, [401, 403], true)) {
            return $this->invalidCredentialsMessage();
        }

        return 'No se pudo iniciar sesion. Verifica tus credenciales.';
    }

    private function invalidCredentialsMessage(): string
    {
        return 'El usuario y/o la contrasena son incorrectos.';
    }

    private function getRemainingLockSeconds(string $username): int
    {
        $lockKey = $this->lockKey($username);
        $unlockAt = Cache::get($lockKey);

        if (!is_int($unlockAt)) {
            return 0;
        }

        $seconds = $unlockAt - time();
        if ($seconds <= 0) {
            Cache::forget($lockKey);
            return 0;
        }

        return $seconds;
    }

    private function registerFailedLogin(string $username): void
    {
        $attemptKey = $this->attemptKey($username);
        $maxAttempts = (int) config('auth.gateway.lockout.max_attempts', 5);
        $windowSeconds = (int) config('auth.gateway.lockout.window_seconds', 180);

        RateLimiter::hit($attemptKey, $windowSeconds);

        if (!RateLimiter::tooManyAttempts($attemptKey, $maxAttempts)) {
            return;
        }

        $penaltyKey = $this->penaltyKey($username);
        $penalty = (int) Cache::get($penaltyKey, 0);
        $initialPenalty = (int) config('auth.gateway.lockout.penalty_initial_seconds', 180);
        $repeatPenalty = (int) config('auth.gateway.lockout.penalty_repeat_seconds', 600);
        $penaltyTtl = (int) config('auth.gateway.lockout.penalty_ttl', 86400);
        $lockSeconds = $penalty === 0 ? $initialPenalty : $repeatPenalty;

        Cache::put($penaltyKey, $penalty + 1, $penaltyTtl);
        Cache::put($this->lockKey($username), time() + $lockSeconds, $lockSeconds);
        RateLimiter::clear($attemptKey);
    }

    private function clearLoginAttempts(string $username): void
    {
        RateLimiter::clear($this->attemptKey($username));
        Cache::forget($this->lockKey($username));
    }

    private function formatLockMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return "Demasiados intentos. Intenta en {$minutes} minuto".($minutes === 1 ? '' : 's').'.';
    }

    private function attemptKey(string $username): string
    {
        return 'login:attempts:'.mb_strtolower(trim($username));
    }

    private function lockKey(string $username): string
    {
        return 'login:lock:'.mb_strtolower(trim($username));
    }

    private function penaltyKey(string $username): string
    {
        return 'login:penalty:'.mb_strtolower(trim($username));
    }
}
