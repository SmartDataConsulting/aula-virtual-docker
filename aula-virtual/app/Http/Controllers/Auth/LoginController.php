<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\AuthSessionKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Config;

/**
 * Gestiona el login/logout usando autenticacion WordPress.
 */
class LoginController extends Controller
{
    protected $redirectTo = '/mis-cursos';

    public function __construct(private readonly AuthService $authService)
    {
        // En este prototipo no usamos el middleware de Auth del framework
        // $this->middleware('guest')->except('logout');
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
        ]);

        $username = trim((string) $request->input('username'));

        $request->validate([
            'password' => [$wpAuthBypass ? 'nullable' : 'required'],
        ]);

        $password = (string) $request->input('password', '');

        Log::info('LOGIN TRY', ['username' => $username]);

        $lockSeconds = $this->getRemainingLockSeconds($username);
        if ($lockSeconds > 0) {
            return back()->withErrors([
                'username' => $this->formatLockMessage($lockSeconds),
            ])->onlyInput('username');
        }

        if ($wpAuthBypass) {
            $coreResult = $this->authService->authenticateWithCore($username, $password);

            Log::info('CORE RESULT DEBUG', [
                'ok' => $coreResult->ok(),
                'status' => $coreResult->status(),
                'data' => $coreResult->data(),
                'error' => $coreResult->error(),
                'bypass_enabled' => true,
            ]);

            if ($coreResult->ok()) {
                $payload = $coreResult->data();

                if (!isset($payload['rol'])) {
                    throw new \RuntimeException('Rol no enviado por CORE');
                }

                $this->clearLoginAttempts($username);
                $this->storeSessionData($request, $payload, $payload['rol'], null);

                Log::info('Login succeeded (core)', [
                    'username' => $username,
                    'ip' => $request->ip(),
                    'bypass_enabled' => true,
                ]);

                return redirect('/backoffice/courses');
            }

            if ($coreResult->status() !== 404) {
                $this->registerFailedLogin($username);

                Log::warning('Login failed (core)', [
                    'username' => $username,
                    'ip' => $request->ip(),
                    'status' => $coreResult->status(),
                    'bypass_enabled' => true,
                ]);

                return back()->withErrors([
                    'username' => 'El usuario y/o la contraseña son incorrectos.',
                ])->onlyInput('username');
            }

            $payload = [
                'email' => $username,
                'nombre' => $username,
                'token' => 'bypassed-token',
            ];

            $this->clearLoginAttempts($username);
            $this->storeSessionData($request, $payload, 'alumno', $payload['token']);

            Log::info('Login bypassed (wordpress)', [
                'username' => $username,
                'email' => $payload['email'],
                'ip' => $request->ip(),
            ]);

            return redirect('/mis-cursos');
        }

        // Detectar si es usuario interno
        $isEmail = str_contains($username, '@');

        /*
        |--------------------------------------------------------------------------
        | 1. Usuarios internos (CORE)
        |--------------------------------------------------------------------------
        */

        if ($isEmail) {

            Log::info('Login routed to CORE', [
                'username' => $username
            ]);

            $coreResult = $this->authService->authenticateWithCore($username, $password);

            Log::info('CORE RESULT DEBUG', [
                'ok' => $coreResult->ok(),
                'status' => $coreResult->status(),
                'data' => $coreResult->data(),
                'error' => $coreResult->error()
            ]);

            if ($coreResult->ok()) {

                $payload = $coreResult->data();
                
                if (!isset($payload['rol'])) {
                    throw new \RuntimeException('Rol no enviado por CORE');
                }

                $role = $payload['rol'];

                $this->clearLoginAttempts($username);

                $this->storeSessionData($request, $payload, $role, null);

                Log::info('Login succeeded (core)', [
                    'username' => $username,
                    'ip' => $request->ip(),
                ]);
                
                return redirect('/backoffice/courses');
            }

            $this->registerFailedLogin($username);

            Log::warning('Login failed (core)', [
                'username' => $username,
                'ip' => $request->ip(),
            ]);

            return back()->withErrors([
                'username' => 'El usuario y/o la contraseña son incorrectos.',
            ])->onlyInput('username');
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Usuarios alumnos (WordPress)
    |--------------------------------------------------------------------------
    */

    $result = $this->authService->authenticateWithWordpress($username, $password);

    if ($result->ok()) {
        $payload = $result->data();
        if (empty($payload['token'])) {
            return back()->withErrors([
                'username' => 'No se pudo iniciar sesión. Verifica tus credenciales.',
            ])->onlyInput('username');
        }
        $this->clearLoginAttempts($username);
        $this->storeSessionData($request, $payload, 'alumno', $payload['token'] ?? null);
        Log::info('Login succeeded (wordpress)', [
            'username' => $username,
            'ip' => $request->ip(),
        ]);
        return redirect('/mis-cursos');
    }

    Log::warning('Login failed (wordpress)', [
        'username' => $username,
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
        'username' => $this->resolveAuthError($result->error(), $result->status()),
    ])->onlyInput('username');

    }


    /**
     * Cierra la sesion actual.
     */
    public function logout(Request $request)
    {
        $request->session()->forget(AuthSessionKeys::all()); 
        $request->session()->invalidate(); 
        $request->session()->regenerateToken(); 
        return redirect()->route('login');
    }

    /**
     * Redirige según el usuario después del login.
     */
    protected function authenticated(Request $request, $user)
    {

    }

    /**
     * Almacena datos de sesión y regenera la sesión.
     */
    private function storeSessionData(Request $request, array $payload, string $role, ?string $token = null): void
    {
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put(AuthSessionKeys::LOGGED_IN, true);
        $request->session()->put(AuthSessionKeys::USER_ID, $payload['id'] ?? $payload['user_id'] ?? null);
        $request->session()->put(AuthSessionKeys::USER_EMAIL, $payload['email'] ?? $payload['user_email'] ?? '');
        $request->session()->put(AuthSessionKeys::USER_NAME, $payload['nombre'] ?? $payload['user_display_name'] ?? null);
        $request->session()->put(AuthSessionKeys::JWT_TOKEN, $token);
        $request->session()->put(AuthSessionKeys::USER_ROLE, $role);
    }

    private function resolveAuthError(array $payload, int $status): string
    {
        if ($status === 0 || $status === 500 || $status === 502 || $status === 503 || $status === 504) {
            return 'No se puede iniciar sesión en este momento. Intenta más tarde.';
        }

        if (in_array($status, [401, 403], true)) {
            return 'El usuario y/o la contraseña son incorrectos.';
        }

        return 'No se pudo iniciar sesión. Verifica tus credenciales.';
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
