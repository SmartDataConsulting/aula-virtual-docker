<?php

    namespace App\Services\Http;

    use App\Services\Support\ServiceResult;
    use App\Support\ApiRequestMetrics;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Http\Request as HttpRequest;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use App\Support\AuthSessionKeys;


    /**
     * Cliente HTTP para consumir API de servicios internos.
     */
    class ApiServiciosClient
    {
        private string $baseUrl;
        private string $token;
        private int $timeout;
        private int $retryTimes;
        private int $retrySleep;
        private int $slowLogMs;
        private float $sampleRate;

        public function __construct()
        {
            $this->baseUrl    = config('services.api_servicios.base_url');
            $this->token      = config('services.api_servicios.token');
            $this->timeout    = (int) config('services.api_servicios.timeout', 3);
            $this->retryTimes = (int) config('services.api_servicios.retry_times', 1);
            $this->retrySleep = (int) config('services.api_servicios.retry_sleep', 200);
            $this->slowLogMs  = (int) config('services.api_servicios.slow_log_ms', 800);
            $this->sampleRate = (float) config('services.api_servicios.log_sample_rate', 0.0);
        }


        /**
         * Devuelve los headers comunes para todas las peticiones.
         */
        private function headers(): array
        {
            return [
                'X-INTERNAL-SERVICE-TOKEN' => $this->token,
                config('services.correlation.header', 'X-Correlation-ID') => $this->correlationId(),
            ];
        }


        /**
         * Valida la configuración base del cliente.
         */
        private function validateConfig(): ?ServiceResult
        {
            if (!$this->baseUrl) {
                return ServiceResult::failure(['message' => 'Missing API_SERVICIOS_BASE_URL value.']);
            }
            if (!$this->token) {
                return ServiceResult::failure(['message' => 'Missing INTERNAL_SERVICE_TOKEN value.']);
            }
            return null;
        }

        

        private function client()
    {
        return Http::timeout($this->timeout)
            ->retry($this->retryTimes, $this->retrySleep)
            ->acceptJson()
            ->withHeaders($this->headers())

            ->withMiddleware(function ($handler) {
                return function ($request, $options) use ($handler) {

                    $start = microtime(true);

                    return $handler($request, $options)->then(
                        function ($response) use ($request, $start) {

                            $contentType = $response->getHeaderLine('Content-Type');

                            $status = $response->getStatusCode();
                            $bodySize = $response->getBody()->getSize();
                            $durationMs = (int) round((microtime(true) - $start) * 1000);
                            ApiRequestMetrics::record($durationMs, $status);

                            $shouldLog = $status >= 400
                                || $durationMs >= $this->slowLogMs
                                || ((mt_rand() / mt_getrandmax()) < $this->sampleRate);

                            if (!$shouldLog) {
                                return $response;
                            }

                            $log = [
                                'method'       => $request->getMethod(),
                                'endpoint'     => $request->getUri()->getPath(),
                                'status'       => $status,
                                'duration_ms'  => $durationMs,
                                'content_type' => $contentType,
                                'body_bytes'   => $bodySize,
                                'correlation_id' => $this->correlationId(),
                            ];

                            if (
                                str_contains($contentType, 'application/json')
                                && ($status >= 400 || (bool) config('services.api_servicios.log_success_body', false))
                                && (bool) config('services.api_servicios.debug_body', false)
                            ) {
                                $log['body'] = (string) $response->getBody();
                            }

                            Log::log($status >= 400 ? 'warning' : 'info', 'API Servicios response', $log);

                            return $response;
                        }
                    );
                };
            });
    }

        /**
         * Maneja excepciones de conexión y genera un ServiceResult de error.
         */
        private function handleConnectionException(
            ConnectionException $e,
            string $endpoint,
            array $context = []
        ): ServiceResult {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out');

            Log::error('API Servicios connection exception', array_merge($context, [
                'endpoint'        => $endpoint,
                'timeout_config'  => $this->timeout,
                'retry_times'     => $this->retryTimes,
                'exception_class' => get_class($e),
                'error_message'   => $e->getMessage(),
                'is_timeout'      => $isTimeout,
            ]));

            return ServiceResult::failure([
                'message' => $isTimeout
                    ? 'API Servicios timeout.'
                    : 'API Servicios connection error.',
                'technical_detail' => $e->getMessage(),
            ], 503);
        }

        /**
         * Ejecuta una petición HTTP y maneja errores y logging.
         */
        private function execute(
            string $endpoint,
            callable $callback,
            array $context = [],
            array $validStatus = [200]
        ): ServiceResult {
            try {
                $response = $callback();
            } catch (ConnectionException $e) {
                return $this->handleConnectionException(
                    $e,
                    $endpoint,
                    $context
                );
            }

            if (!in_array($response->status(), $validStatus)) {
                $correlationId = $response->header('X-Correlation-ID');
                Log::error('API Servicios HTTP failure', array_merge($context, [
                    'endpoint'        => $endpoint,
                    'status'          => $response->status(),
                    'correlation_id'  => $correlationId,
                    'body'            => $response->body(),
                ]));
                return ServiceResult::failure([
                    'message'        => 'API Servicios error.',
                    'body'           => $response->body(),
                    'correlation_id' => $correlationId,
                ], $response->status());
            }

            return ServiceResult::success(
                $response->json(),
                $response->status()
            );
        }

    private function executeRaw(
        string $endpoint,
        callable $callback,
        array $context = [],
        array $validStatus = [200]
    ): ServiceResult {

        try {

            $response = $callback();

        } catch (ConnectionException $e) {

            return $this->handleConnectionException(
                $e,
                $endpoint,
                $context
            );
        }

        if (!in_array($response->status(), $validStatus)) {

            $correlationId = $response->header('X-Correlation-ID');

            Log::error('API Servicios HTTP failure (raw)', array_merge($context, [
                'endpoint'       => $endpoint,
                'status'         => $response->status(),
                'correlation_id' => $correlationId,
            ]));

            return ServiceResult::failure([
                'message'        => 'API Servicios error.',
                'correlation_id' => $correlationId,
            ], $response->status());
        }

        // 👇 aquí no tocamos el body
        return ServiceResult::success($response, $response->status());
    }

        /* ===================== CURSOS ===================== */

        public function listarCursos(string $correo, bool $includeSuggestions = false): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = '/v1/cursos';
            $query = ['correo' => $correo];

            if ($includeSuggestions) {
                $query['include_suggestions'] = 1;
            }

            Log::debug('API Servicios request (cursos).', [
                'correo' => $correo,
                'endpoint' => $endpoint,
                'include_suggestions' => $includeSuggestions,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get(
                        $this->buildUrl($endpoint),
                        $query
                    ),
                ['correo' => $correo],
                [200] // status válidos
            );
        }

        public function resumenAlumno(string $correo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = '/v1/alumno/resumen';

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint), ['correo' => $correo]),
                [],
                [200]
            );
        }

        public function resumenBackoffice(string $correo = '', string $role = 'admin'): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = '/v1/backoffice/resumen';

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => $role,
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint)),
                [],
                [200]
            );
        }

        public function listarAlumnosCurso(int $cursoEdicionId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/cursos/{$cursoEdicionId}/alumnos";

            Log::debug('API Servicios request (alumnos curso).', [
                'curso_edicion_id' => $cursoEdicionId,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'curso_edicion_id' => $cursoEdicionId,
                ],
                [200]
            );
        }

        public function obtenerPerfilPublicoAlumno(string $correo, ?int $cursoEdicionId = null, string $solicitanteCorreo = ''): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $solicitanteCorreo = strtolower(trim($solicitanteCorreo));
            $endpoint = '/v1/alumnos/perfil-publico';

            Log::debug('API Servicios request (perfil publico alumno).', [
                'correo' => $correo,
                'curso_edicion_id' => $cursoEdicionId,
                'endpoint' => $endpoint,
            ]);

            $query = array_filter([
                'correo' => $correo,
                'curso_edicion_id' => $cursoEdicionId,
                'solicitante_correo' => $solicitanteCorreo,
            ], fn ($value) => $value !== null && $value !== '');

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                    ])
                    ->get($this->buildUrl($endpoint), $query),
                [
                    'correo' => $correo,
                    'curso_edicion_id' => $cursoEdicionId,
                ],
                [200]
            );
        }

        public function obtenerAlumnoPorCorreo(string $correo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $endpoint = '/v1/alumno/perfil';

            Log::debug('API Servicios request (perfil alumno).', [
                'correo' => $correo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'correo' => $correo,
                ],
                [200]
            );
        }

        public function actualizarAlumnoPorCorreo(string $correo, array $data): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $endpoint = '/v1/alumno/perfil';

            Log::debug('API Servicios request (actualizar perfil alumno).', [
                'correo' => $correo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->put($this->buildUrl($endpoint), $data),
                [
                    'correo' => $correo,
                    'payload_keys' => array_keys($data),
                ],
                [200]
            );
        }

        public function actualizarAdjuntosPerfilAlumno(string $correo, array $archivos): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $endpoint = '/v1/alumno/perfil/adjuntos';

            Log::debug('API Servicios request (actualizar adjuntos perfil alumno).', [
                'correo' => $correo,
                'endpoint' => $endpoint,
                'adjuntos' => array_keys($archivos),
            ]);

            return $this->execute(
                $endpoint,
                function () use ($endpoint, $correo, $archivos) {
                    $request = $this->client()
                        ->asMultipart()
                        ->withHeaders([
                            'X-USER-ROL' => 'alumno',
                            'X-USER-EMAIL' => $correo,
                        ]);

                    foreach (['foto', 'cv'] as $tipo) {
                        $file = $archivos[$tipo] ?? null;

                        if (!$file instanceof \Illuminate\Http\UploadedFile) {
                            continue;
                        }

                        $request = $request->attach(
                            $tipo,
                            file_get_contents($file->getRealPath()),
                            $file->getClientOriginalName()
                        );
                    }

                    return $request->post($this->buildUrl($endpoint));
                },
                [
                    'correo' => $correo,
                    'adjuntos' => array_keys($archivos),
                ],
                [200, 201]
            );
        }

        public function descargarAdjuntoPerfilAlumno(string $correo, string $tipo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $tipo = strtolower(trim($tipo));
            $endpoint = "/v1/alumno/perfil/adjuntos/{$tipo}";

            Log::debug('API Servicios request (descargar adjunto perfil alumno).', [
                'correo' => $correo,
                'tipo' => $tipo,
                'endpoint' => $endpoint,
            ]);

            return $this->executeRaw(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                        'Accept' => '*/*',
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'correo' => $correo,
                    'tipo' => $tipo,
                ],
                [200]
            );
        }

        public function enviarSolicitudContactoAlumno(array $data): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = '/v1/alumnos/contacto/solicitudes';

            Log::debug('API Servicios request (crear solicitud contacto).', [
                'curso_edicion_id' => $data['curso_edicion_id'] ?? null,
                'solicitante_correo' => $data['solicitante_correo'] ?? null,
                'destinatario_correo' => $data['destinatario_correo'] ?? null,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $data['solicitante_correo'] ?? session(AuthSessionKeys::USER_EMAIL),
                    ])
                    ->post($this->buildUrl($endpoint), $data),
                [
                    'curso_edicion_id' => $data['curso_edicion_id'] ?? null,
                    'solicitante_correo' => $data['solicitante_correo'] ?? null,
                    'destinatario_correo' => $data['destinatario_correo'] ?? null,
                ],
                [200, 201]
            );
        }

        public function consultarSolicitudesContactoAlumno(string $correo, string $tipo = 'RECIBIDAS'): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $correo = strtolower(trim($correo));
            $tipo = strtoupper(trim($tipo));
            $endpoint = '/v1/alumnos/contacto/solicitudes';

            Log::debug('API Servicios request (solicitudes contacto alumno).', [
                'correo' => $correo,
                'tipo' => $tipo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint), [
                        'correo' => $correo,
                        'tipo' => $tipo,
                    ]),
                [
                    'correo' => $correo,
                    'tipo' => $tipo,
                ],
                [200]
            );
        }

        public function responderSolicitudContactoAlumno(string $solicitudId, array $data): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $solicitudId = trim($solicitudId);
            $destinatarioCorreo = strtolower(trim((string) ($data['destinatario_correo'] ?? '')));
            $endpoint = "/v1/alumnos/contacto/solicitudes/{$solicitudId}/responder";

            Log::debug('API Servicios request (responder solicitud contacto).', [
                'solicitud_id' => $solicitudId,
                'destinatario_correo' => $destinatarioCorreo,
                'estado' => $data['estado'] ?? null,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $destinatarioCorreo,
                    ])
                    ->put($this->buildUrl($endpoint), $data),
                [
                    'solicitud_id' => $solicitudId,
                    'destinatario_correo' => $destinatarioCorreo,
                    'estado' => $data['estado'] ?? null,
                ],
                [200]
            );
        }

        /* ===================== SESIONES ===================== */

        public function listarSesionesCurso(int $courseId, string $rol): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/curso/{$courseId}/sesiones";

            Log::debug('API Servicios request (curso sesiones).', [
                'curso_id' => $courseId,
                'param_role' => $rol,
                'session_role' => session(AuthSessionKeys::USER_ROLE),
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                ])
                ->get($this->buildUrl($endpoint)),
                [
                    'curso_id' => $courseId,
                    'rol' => $rol,
                ],  
                [200]
            );
        }

        public function listarSesionesCursoLight(int $courseId, string $correo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/alumno/cursos/{$courseId}/sesiones/light";

            Log::debug('API Servicios request (curso sesiones light).', [
                'curso_id' => $courseId,
                'correo' => $correo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'curso_id' => $courseId,
                    'correo' => $correo,
                ],
                [200]
            );
        }

        public function obtenerDetalleSesionAlumno(int $courseId, int $sessionId, string $correo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/alumno/cursos/{$courseId}/sesiones/{$sessionId}/detalle";

            Log::debug('API Servicios request (detalle sesion alumno).', [
                'curso_id' => $courseId,
                'session_id' => $sessionId,
                'correo' => $correo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => 'alumno',
                        'X-USER-EMAIL' => $correo,
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'curso_id' => $courseId,
                    'session_id' => $sessionId,
                    'correo' => $correo,
                ],
                [200]
            );
        }

        public function listarAnunciosCurso(int $courseId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios/curso/{$courseId}";

        Log::debug('API Servicios request (curso anuncios).', [
            'curso_id' => $courseId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->get($this->buildUrl($endpoint)),
            ['curso_id' => $courseId],
            [200]
        );
    }


        /**
         * Lista anuncios de un curso con información de lectura.
         * @param int $courseId
         * @param string $correo
         * @return ServiceResult
         */
        public function listarAnunciosCursoConLectura(int $courseId, string $correo): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/anuncios/curso/{$courseId}/con-lectura";

            Log::debug('API Servicios request (curso anuncios con lectura).', [
                'curso_id' => $courseId,
                'correo'   => $correo,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->asForm()
                    ->post($this->buildUrl($endpoint), [ 'correo' => $correo ]),
                [ 'curso_id' => $courseId, 'correo' => $correo ],
                [200]
            );
        }
        /**
         * Construye la URL completa para un endpoint.
         */
        private function buildUrl(string $endpoint): string
        {
            return rtrim($this->baseUrl, '/') . $endpoint;
        }

        /**
         * Adjunta un archivo a una petición HTTP.
         */
        private function attachFile($request, $file, string $field = 'archivo')
        {
            return $request->attach(
                $field,
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName()
            );
        }

        private function attachFiles($request, array $files, string $field): mixed
        {
            foreach ($files as $file) {
                if (!$file instanceof \Illuminate\Http\UploadedFile) {
                    continue;
                }

                $request = $request->attach(
                    $field,
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName()
                );
            }

            return $request;
        }


    public function marcarAnuncioComoLeido(
        int $anuncioId,
        string $correo
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios/{$anuncioId}/leer";

        Log::debug('API Servicios request (anuncio leer).', [
            'anuncio_id' => $anuncioId,
            'correo'     => $correo,
            'endpoint'   => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->asForm()
                ->post(
                    $this->buildUrl($endpoint),
                    ['correo' => $correo]
                ),
            [
                'anuncio_id' => $anuncioId,
                'correo'     => $correo,
            ],
            [200]
        );
    }

    public function marcarAnunciosComoLeido(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return ServiceResult::failure([
                'message' => 'entidad_tipo invalido.',
            ]);
        }

        if (trim($correo) === '') {
            return ServiceResult::failure([
                'message' => 'correo requerido.',
            ]);
        }

        $endpoint = "/v1/anuncios/{$entidadTipo}/{$entidadId}/leer-todos";

        Log::debug('API Servicios request (anuncios leer todos).', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $entidadId,
            'correo'       => $correo,
            'endpoint'     => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->asForm()
                ->post(
                    $this->buildUrl($endpoint),
                    [
                        'correo' => $correo
                    ]
                ),
            [
                'entidad_tipo' => $entidadTipo,
                'entidad_id'   => $entidadId,
                'correo'       => $correo,
            ],
            [200]
        );
    }

    /* ===================== MATERIALES SESION ===================== */

    public function listarMaterialesPorSesion(int $sessionId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sessionId}/materiales";

        Log::debug('API Servicios request (sesion materiales).', [
            'session_id' => $sessionId,
            'endpoint'   => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->get($this->buildUrl($endpoint)),
            [
                'session_id' => $sessionId,
            ],
            [200]
        );
    }

        public function crearMaterialSesion(
        int $sessionId,
        array $payload,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sessionId}/materiales";

        Log::debug('API Servicios request (crear material sesion).', [
            'session_id' => $sessionId,
            'rol'        => $rol,
            'endpoint'   => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            function () use ($sessionId, $payload, $rol, $endpoint) {

                $request = $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => $rol,
                    ]);

                // Si hay archivo → multipart
                if (isset($payload['archivo'])) {

                    $file = $payload['archivo'];

                    $request = $request->attach(
                        'archivo',
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName()
                    );

                    unset($payload['archivo']);
                }

                return $request->post(
                    $this->buildUrl($endpoint),
                    $payload
                );
            },
            [
                'session_id' => $sessionId,
                'rol'        => $rol,
            ],
            [200, 201] // 👈 importante
        );
    }
        public function actualizarMaterialSesion(int $sessionId, int $materialId, array $payload, string $rol): ServiceResult
        {
            if ($fail = $this->validateConfig()) return $fail;

            $endpoint = "/v1/sesiones/{$sessionId}/materiales/{$materialId}";

            Log::debug('API Servicios request (actualizar material sesion).', [
                'session_id'  => $sessionId,
                'material_id' => $materialId,
                'rol'         => $rol,
                'endpoint'    => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                function () use ($sessionId, $materialId, $payload, $rol, $endpoint) {
                    $request = $this->client()
                        ->withHeaders([
                            'X-USER-ROL' => $rol,
                        ]);
                    $url = $this->buildUrl($endpoint);
                    // Si hay archivo → multipart + method spoofing
                    if (isset($payload['archivo']) && $payload['archivo'] instanceof \Illuminate\Http\UploadedFile) {
                        $file = $payload['archivo'];
                        $request = $this->attachFile($request, $file);
                        unset($payload['archivo']);
                        // Usar POST + _method
                        return $request->post($url, array_merge($payload, [ '_method' => 'PUT' ]));
                    } else {
                        // Sin archivo
                        return $request->put($url, $payload);
                    }
                },
                [
                    'session_id'  => $sessionId,
                    'material_id' => $materialId,
                    'rol'         => $rol,
                ],
                [200]
            );
        }

        public function eliminarMaterialSesion(
        int $sessionId,
        int $materialId,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sessionId}/materiales/{$materialId}";

        Log::debug('API Servicios request (eliminar material sesion).', [
            'session_id'  => $sessionId,
            'material_id' => $materialId,
            'rol'         => $rol,
            'endpoint'    => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                ])
                ->delete($this->buildUrl($endpoint)),
            [
                'session_id'  => $sessionId,
                'material_id' => $materialId,
                'rol'         => $rol,
            ],
            [200]
        );
    }

    public function descargarMaterialSesion(
        int $materialId,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/materiales/{$materialId}/descargar";

        Log::debug('API Servicios request (descargar material).', [
            'material_id' => $materialId,
            'rol'         => $rol,
            'endpoint'    => $endpoint,
        ]);

        return $this->executeRaw(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'Accept'     => '*/*',
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'material_id' => $materialId,
                'rol'         => $rol,
            ],
            [200]
        );
    }

    /* ===================== ANUNCIOS SESION ===================== */

    public function listarAnuncios(
        string $entidadTipo,
        int $entidadId,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios/{$entidadTipo}/{$entidadId}";

        Log::debug('API Servicios request (anuncios).', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $entidadId,
            'rol'          => $rol,
            'endpoint'     => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'entidad_tipo' => $entidadTipo,
                'entidad_id'   => $entidadId,
                'rol'          => $rol,
            ],
            [200]
        );
    }

    public function listarAnunciosAlumno(
        string $entidadTipo,
        int $entidadId,
        string $correo
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        if (!in_array($entidadTipo, ['curso', 'sesion'])) {
            return ServiceResult::failure([
                'message' => 'entidad_tipo invalido.',
            ]);
        }

        if (trim($correo) === '') {
            return ServiceResult::failure([
                'message' => 'correo requerido.',
            ]);
        }

        $endpoint = "/v1/anuncios/{$entidadTipo}/{$entidadId}/con-lectura";

        Log::debug('API Servicios request (anuncios con lectura).', [
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $entidadId,
            'correo'       => $correo,
            'endpoint'     => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->asForm()
                ->post(
                    $this->buildUrl($endpoint),
                    [
                        'correo' => $correo,
                    ]
                ),
            [
                'entidad_tipo' => $entidadTipo,
                'entidad_id'   => $entidadId,
                'correo'       => $correo,
            ],
            [200]
        );
    }

    public function crearAnuncio(
        array $payload,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios";

        Log::debug('API Servicios request (crear anuncio).', [
            'entidad_tipo' => $payload['entidad_tipo'] ?? null,
            'entidad_id'   => $payload['entidad_id'] ?? null,
            'rol'          => $rol,
            'endpoint'     => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                ])
                ->post($this->buildUrl($endpoint), $payload),
            [
                'entidad_tipo' => $payload['entidad_tipo'] ?? null,
                'entidad_id'   => $payload['entidad_id'] ?? null,
                'rol'          => $rol,
            ],
            [200, 201]
        );
    }

    public function actualizarAnuncio(
        int $announcementId,
        array $payload,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios/{$announcementId}";

        Log::debug('API Servicios request (actualizar anuncio).', [
            'announcement_id' => $announcementId,
            'rol'             => $rol,
            'endpoint'        => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                ])
                ->put($this->buildUrl($endpoint), $payload),
            [
                'announcement_id' => $announcementId,
                'rol'             => $rol,
            ],
            [200]
        );
    }

    public function eliminarAnuncio(
        int $announcementId,
        string $rol
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/anuncios/{$announcementId}";

        Log::debug('API Servicios request (eliminar anuncio).', [
            'announcement_id' => $announcementId,
            'rol'             => $rol,
            'endpoint'        => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                ])
                ->delete($this->buildUrl($endpoint)),
            [
                'announcement_id' => $announcementId,
                'rol'             => $rol,
            ],
            [200]
        );
    }

    /* ===================== CHAT ===================== */

    public function obtenerSalaChatPorContexto(string $context, int $contextId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $context = strtoupper($context);
        $endpoint = "/v1/chat/salas/contexto/{$context}/{$contextId}";

        Log::debug('API Servicios request (chat sala por contexto).', [
            'context' => $context,
            'context_id' => $contextId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'context' => $context,
                'context_id' => $contextId,
            ],
            [200, 201]
        );
    }

    public function listarMensajesChat(string $salaId, int $limit = 20, int $offset = 0): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/chat/salas/{$salaId}/mensajes";

        Log::debug('API Servicios request (chat mensajes).', [
            'sala_id' => $salaId,
            'limit' => $limit,
            'offset' => $offset,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                ])
                ->get($this->buildUrl($endpoint), [
                    'limit' => $limit,
                    'offset' => $offset,
                ]),
            [
                'sala_id' => $salaId,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [200]
        );
    }

    public function crearMensajeChat(string $salaId, array $payload): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/chat/salas/{$salaId}/mensajes";

        Log::debug('API Servicios request (crear mensaje chat).', [
            'sala_id' => $salaId,
            'mensaje_padre_id' => $payload['mensaje_padre_id'] ?? null,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                    'X-USER-NAME' => session(AuthSessionKeys::USER_NAME),
                    'X-USER-NOMBRE' => session(AuthSessionKeys::USER_NAME),
                ])
                ->post($this->buildUrl($endpoint), $payload),
            [
                'sala_id' => $salaId,
                'mensaje_padre_id' => $payload['mensaje_padre_id'] ?? null,
            ],
            [200, 201]
        );
    }

    public function eliminarMensajeChat(string $mensajeId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/chat/mensajes/{$mensajeId}";

        Log::debug('API Servicios request (eliminar mensaje chat).', [
            'mensaje_id' => $mensajeId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
                ])
                ->delete($this->buildUrl($endpoint)),
            [
                'mensaje_id' => $mensajeId,
            ],
            [200, 204]
        );
    }

        private function correlationId(): string
        {
            // Recupera correlation-id agregado por middleware.
            $request = app('request');
            if (!$request instanceof HttpRequest) {
                return '';
            }

            return (string) $request->attributes->get('correlation_id', '');
        }

        public function registerVideoUploadStarted(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/upload-started";

            Log::debug('API Servicios request (video upload started).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'payload_keys' => array_keys($payload),
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                ],
                [200, 201]
            );
        }

        public function updateVideoUploadProgress(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/upload-progress";

            Log::debug('API Servicios request (video upload progress update).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'upload_id' => $payload['upload_id'] ?? null,
                'bytes_uploaded' => $payload['bytes_uploaded'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                    'upload_id' => $payload['upload_id'] ?? null,
                ],
                [200, 201]
            );
        }

        public function completeVideoUpload(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/upload-completed";

            Log::debug('API Servicios request (video upload completed).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'upload_id' => $payload['upload_id'] ?? null,
                'drive_file_id' => $payload['drive_file_id'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                    'upload_id' => $payload['upload_id'] ?? null,
                ],
                [200, 201]
            );
        }

        public function registerVideoUploadError(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/upload-error";

            Log::debug('API Servicios request (video upload error).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'upload_id' => $payload['upload_id'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                    'upload_id' => $payload['upload_id'] ?? null,
                ],
                [200, 201]
            );
        }

        public function registerVideoUploadCancelled(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/upload-cancelled";

            Log::debug('API Servicios request (video upload cancelled).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'upload_id' => $payload['upload_id'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                    'upload_id' => $payload['upload_id'] ?? null,
                ],
                [200, 201]
            );
        }

        public function updateVideoStatusRecord(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/status-updated";

            Log::debug('API Servicios request (video status updated).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'video_status' => $payload['video_status'] ?? null,
                'drive_file_id' => $payload['drive_file_id'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                    'video_status' => $payload['video_status'] ?? null,
                ],
                [200, 201]
            );
        }

        public function deleteVideo(int $sessionId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/deleted";

            Log::debug('API Servicios request (delete video).', [
                'session_id' => $sessionId,
                'endpoint'   => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->post($this->buildUrl($endpoint)),
                [
                    'session_id' => $sessionId,
                ],
                [200]
            );
        }

        public function registerVideoChatUploaded(int $sessionId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/chat-uploaded";

            Log::debug('API Servicios request (video chat uploaded).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'filesize' => $payload['filesize'] ?? null,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint), $payload),
                [
                    'session_id' => $sessionId,
                ],
                [200, 201]
            );
        }

        public function deleteVideoChat(int $sessionId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/chat-deleted";

            Log::debug('API Servicios request (delete video chat).', [
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()->post($this->buildUrl($endpoint)),
                [
                    'session_id' => $sessionId,
                ],
                [200]
            );
        }

        public function getVideoStatus(int $sessionId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/sesiones/{$sessionId}/video/status";

            Log::debug('API Servicios request (video status).', [
                'session_id' => $sessionId,
                'endpoint'   => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => (string) session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'session_id' => $sessionId,
                ],
                [200]
            );
        }

        public function getUploadProgress(int $sessionId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sessionId}/video/upload-progress";

        Log::debug('API Servicios request (upload progress).', [
            'session_id' => $sessionId,
            'endpoint'   => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->get($this->buildUrl($endpoint)),
            [
                'session_id' => $sessionId,
            ],
            [200]
        );
    }

        /* ===================== AUTH ===================== */

        public function login(string $email, string $password): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/login";

            Log::debug('API Servicios request (login).', [
                'endpoint' => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->post(
                        $this->buildUrl($endpoint),
                        [
                            'email' => $email,
                            'password' => $password,
                        ]
                    ),
                [],
                [200]
            );
        }

        /* ===================== ENCUESTAS ===================== */

        public function obtenerEncuestaFormulario(int $encuestaTipoId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }

            $endpoint = "/v1/encuestas/{$encuestaTipoId}/formulario";

            Log::debug('API Servicios request (encuesta formulario).', [
                'encuesta_id' => $encuestaTipoId,
                'endpoint'    => $endpoint,
            ]);

            return $this->execute(
                $endpoint,
                fn() => $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => (string) session(AuthSessionKeys::USER_ROLE),
                        'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                    ])
                    ->get($this->buildUrl($endpoint)),
                [
                    'encuesta_id' => $encuestaTipoId,
                ],
                [200]
            );
        }

        public function obtenerEncuestaAlumno(int $courseId, int $sessionId, int $linkId): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }
            $endpoint = "/v1/alumno/cursos/{$courseId}/sesiones/{$sessionId}/encuestas/{$linkId}";
            return $this->execute(
                $endpoint,
                fn () => $this->client()->withHeaders([
                    'X-USER-ROL' => 'alumno',
                    'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                ])->get($this->buildUrl($endpoint)),
                ['course_id' => $courseId, 'session_id' => $sessionId, 'link_id' => $linkId],
                [200]
            );
        }

        public function registrarEncuestaAlumno(int $courseId, int $sessionId, int $linkId, array $payload): ServiceResult
        {
            if ($fail = $this->validateConfig()) {
                return $fail;
            }
            $endpoint = "/v1/alumno/cursos/{$courseId}/sesiones/{$sessionId}/encuestas/{$linkId}/respuestas";
            return $this->execute(
                $endpoint,
                fn () => $this->client()->withHeaders([
                    'X-USER-ROL' => 'alumno',
                    'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                ])->post($this->buildUrl($endpoint), $payload),
                ['course_id' => $courseId, 'session_id' => $sessionId, 'link_id' => $linkId],
                [200]
            );
        }

        /* ===================== RESPUESTAS ENCUESTAS ===================== */

    public function verificarEncuestaRespondida(
    ?int $sesionId,
    ?int $cursoId,
    string $correo
): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        if (!$sesionId && !$cursoId) {
            return ServiceResult::error('sesion_id o curso_id requerido');
        }

        $endpoint = "/v1/encuestas/verificar";

        $payload = [
            'correo' => $correo,
        ];

        if ($sesionId) {
            $payload['sesion_id'] = $sesionId;
        }

        if ($cursoId) {
            $payload['curso_id'] = $cursoId;
        }

        Log::debug('API Servicios request (verificar encuesta).', [
            'sesion_id' => $sesionId,
            'curso_id'  => $cursoId,
            'correo'    => $correo,
            'endpoint'  => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => (string) session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                ])
                ->asForm()
                ->post(
                    $this->buildUrl($endpoint),
                    $payload
                ),
            $payload,
            [200]
        );
    }



    public function registrarRespuestaEncuesta(
        int $encuestaId,
        int $cursoId,
        int $sesionId,
        string $correo,
        array $respuestas
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/encuestas/responder";

        Log::debug('API Servicios request (registrar encuesta).', [
            'encuesta_id' => $encuestaId,
            'curso_id'    => $cursoId,
            'sesion_id'   => $sesionId,
            'correo'      => $correo,
            'endpoint'    => $endpoint,
        ]);     

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => (string) session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                ])
                ->post(
                    $this->buildUrl($endpoint),
                    [
                        'encuesta_id' => $encuestaId,
                        'curso_id'    => $cursoId,
                        'sesion_id'   => $sesionId,
                        'correo'      => $correo,
                        'respuestas'  => $respuestas,
                    ]
                ),
            [
                'encuesta_id' => $encuestaId,
                'sesion_id'   => $sesionId,
                'correo'      => $correo,
            ],
            [200]
        );
    }



    public function obtenerEstadisticaEncuestaSesion(
        int $sesionId
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sesionId}/encuestas/respuestas";

        Log::debug('API Servicios request (estadistica encuesta sesion).', [
            'sesion_id' => $sesionId,
            'endpoint'  => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => (string) session(AuthSessionKeys::USER_ROLE),
                    'X-USER-EMAIL' => (string) session(AuthSessionKeys::USER_EMAIL),
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'sesion_id' => $sesionId,
            ],
            [200]
        );
    }

    /* ===================== CURSO DETALLE ===================== */

    public function obtenerCurso(int $courseId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$courseId}";

        Log::debug('API Servicios request (curso detalle).', [
            'curso_id' => $courseId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->get($this->buildUrl($endpoint)),
            [
                'curso_id' => $courseId,
            ],
            [200]
        );
    }

    /* ===================== CURSOS - EVALUACIONES ===================== */

    public function listarCursosParaEvaluaciones(): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/cursos";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (cursos evaluaciones).', [
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function listarCursosParaCalificaciones(): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/calificaciones/cursos";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (cursos calificaciones).', [
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function listarCursosParaEncuestas(): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = '/v1/encuestas/cursos';
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        return $this->execute(
            $endpoint,
            fn () => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            ['rol' => $rol],
            [200]
        );
    }

    public function obtenerCertificadosPorCurso(int $courseId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$courseId}/certificados";
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (certificados curso).', [
            'curso_edicion_id' => $courseId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_edicion_id' => $courseId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function obtenerCertificadoAlumnoCurso(int $courseId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/alumno/cursos/{$courseId}/certificado";
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_edicion_id' => $courseId,
                'rol' => $rol,
            ],
            [200]
        );
    }

    public function adjuntarCertificado(array $data): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = '/v1/alumnos/certificados/adjuntar';
        $archivo = $data['certificado'] ?? null;
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (adjuntar certificado).', [
            'curso_edicion_id' => $data['curso_edicion_id'] ?? null,
            'alumno_correo' => $data['alumno_correo'] ?? null,
            'usuario_adjunta' => $data['usuario_adjunta'] ?? null,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            function () use ($endpoint, $data, $archivo, $rol, $correo) {
                $request = $this->client()
                    ->asMultipart()
                    ->withHeaders([
                        'X-USER-ROL' => $rol,
                        'X-USER-EMAIL' => $correo,
                    ]);

                if ($archivo instanceof \Illuminate\Http\UploadedFile) {
                    $request = $request->attach(
                        'certificado',
                        fopen($archivo->getRealPath(), 'r'),
                        $archivo->getClientOriginalName()
                    );
                }

                return $request->post($this->buildUrl($endpoint), [
                    'alumno_correo' => (string) ($data['alumno_correo'] ?? ''),
                    'curso_edicion_id' => (string) ($data['curso_edicion_id'] ?? ''),
                    'usuario_adjunta' => (string) ($data['usuario_adjunta'] ?? ''),
                ]);
            },
            [
                'curso_edicion_id' => $data['curso_edicion_id'] ?? null,
                'alumno_correo' => $data['alumno_correo'] ?? null,
            ],
            [200]
        );
    }

    public function enviarCertificado(int $certificadoId, array $data): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/alumnos/certificados/{$certificadoId}/enviar";
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (enviar certificado).', [
            'certificado_id' => $certificadoId,
            'usuario_envia' => $data['usuario_envia'] ?? null,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post($this->buildUrl($endpoint), $data),
            [
                'certificado_id' => $certificadoId,
                'usuario_envia' => $data['usuario_envia'] ?? null,
            ],
            [200]
        );
    }

    public function sincronizarDiplomasSgaCurso(int $courseId, array $data): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$courseId}/certificados/sync-sga";
        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (sync diplomas SGA).', [
            'curso_edicion_id' => $courseId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post($this->buildUrl($endpoint), $data),
            [
                'curso_edicion_id' => $courseId,
            ],
            [200]
        );
    }

    public function obtenerDetalleResultadosEncuestasCurso(int $cursoEdicionId, array $filters = []): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$cursoEdicionId}/encuestas/detalle-resultados";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (detalle resultados encuestas curso).', [
            'curso_edicion_id' => $cursoEdicionId,
            'rol' => $rol,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint), array_filter($filters, fn ($value) => $value !== null && $value !== '')),
            [
                'curso_edicion_id' => $cursoEdicionId,
                'rol' => $rol,
            ],
            [200]
        );
    }

    /* ===================== EVALUACIONES ===================== */

    public function listarEvaluacionesPorCurso(int $cursoId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$cursoId}/evaluaciones";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (evaluaciones por curso).', [
            'curso_id' => $cursoId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_id' => $cursoId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function obtenerResumenCalificacionesCurso(int $cursoId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/calificaciones/cursos/{$cursoId}";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (dashboard calificaciones curso).', [
            'curso_id' => $cursoId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_id' => $cursoId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function listarParticipantesEvaluacion(int $evaluacionId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/participantes";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (participantes evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function obtenerRevisionEntregaEvaluacion(
        int $evaluacionId,
        int $entregaId
    ): ServiceResult {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/entregas/{$entregaId}/revision";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (revision entrega evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'entrega_id' => $entregaId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'evaluacion_id' => $evaluacionId,
                'entrega_id' => $entregaId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function guardarRevisionEntregaEvaluacion(
        int $evaluacionId,
        int $entregaId,
        array $payload
    ): ServiceResult {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/entregas/{$entregaId}/revision";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (guardar revision entrega evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'entrega_id' => $entregaId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post($this->buildUrl($endpoint), $payload),
            [
                'evaluacion_id' => $evaluacionId,
                'entrega_id' => $entregaId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    public function registrarSubsanacionExamen(
        int $evaluacionId,
        array $payload
    ): ServiceResult {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/docente/evaluaciones/{$evaluacionId}/subsanaciones/examen";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (registrar subsanacion examen).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            function () use ($endpoint, $payload, $rol, $correo) {
                $request = $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => $rol,
                        'X-USER-EMAIL' => $correo,
                    ]);

                if (($payload['evidencia_archivo'] ?? null) instanceof \Illuminate\Http\UploadedFile) {
                    $request = $this->attachFile($request, $payload['evidencia_archivo'], 'evidencia_archivo');
                    unset($payload['evidencia_archivo']);
                }

                return $request->post($this->buildUrl($endpoint), $payload);
            },
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    public function registrarSubsanacionTrabajo(
        int $evaluacionId,
        array $payload
    ): ServiceResult {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/docente/evaluaciones/{$evaluacionId}/subsanaciones/trabajo";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (registrar subsanacion trabajo).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            function () use ($endpoint, $payload, $rol, $correo) {
                $request = $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => $rol,
                        'X-USER-EMAIL' => $correo,
                    ]);

                if (($payload['evidencia_archivo'] ?? null) instanceof \Illuminate\Http\UploadedFile) {
                    $request = $this->attachFile($request, $payload['evidencia_archivo'], 'evidencia_archivo');
                    unset($payload['evidencia_archivo']);
                }

                return $request->post($this->buildUrl($endpoint), $payload);
            },
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    public function actualizarSubsanacionEvaluacion(
        int $evaluacionId,
        int $subsanacionId,
        array $payload
    ): ServiceResult {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/docente/evaluaciones/{$evaluacionId}/subsanaciones/{$subsanacionId}";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (actualizar subsanacion evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'subsanacion_id' => $subsanacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            function () use ($endpoint, $payload, $rol, $correo) {
                $request = $this->client()
                    ->withHeaders([
                        'X-USER-ROL' => $rol,
                        'X-USER-EMAIL' => $correo,
                    ]);

                if (($payload['evidencia_archivo'] ?? null) instanceof \Illuminate\Http\UploadedFile) {
                    $request = $this->attachFile($request, $payload['evidencia_archivo'], 'evidencia_archivo');
                    unset($payload['evidencia_archivo']);

                    return $request->post($this->buildUrl($endpoint), array_merge($payload, [
                        '_method' => 'PUT',
                    ]));
                }

                return $request->put($this->buildUrl($endpoint), $payload);
            },
            [
                'evaluacion_id' => $evaluacionId,
                'subsanacion_id' => $subsanacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    public function listarSubsanacionesEvaluacion(int $evaluacionId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/docente/evaluaciones/{$evaluacionId}/subsanaciones";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (listar subsanaciones evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function descargarEvidenciaSubsanacion(string $path): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $normalizedPath = ltrim($path, '/');
        $endpoint = '/v1/docente/subsanaciones/evidencia';

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (descargar evidencia subsanacion).', [
            'path' => $normalizedPath,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->executeRaw(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint), [
                    'path' => $normalizedPath,
                ]),
            [
                'path' => $normalizedPath,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }
    
    /* ===================== CREAR EVALUACION ===================== */

    public function crearEvaluacion(array $payload): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $payload = $this->stripEvaluationDeadlineField($payload);

        $endpoint = "/v1/evaluaciones";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (crear evaluacion).', [
            'payload' => $payload,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post($this->buildUrl($endpoint), $payload),
            [
                'payload' => $payload,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    /* ===================== PARAMETROS ===================== */

    public function listarParametrosPorMaestro(int $idMaestro): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/parametros/maestro/{$idMaestro}";

        Log::debug('API Servicios request (parametros por maestro).', [
            'id_maestro' => $idMaestro,
            'endpoint'   => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->get($this->buildUrl($endpoint)),
            [
                'id_maestro' => $idMaestro,
            ],
            [200]
        );
    }

    /* ===================== AUTOSAVE EVALUACION ===================== */

public function autosaveEvaluacion(
        int $evaluacionId,
        array $payload
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $payload = $this->stripEvaluationDeadlineField($payload);

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/autosave";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (autosave evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post(
                    $this->buildUrl($endpoint),
                    $payload
                ),
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    public function guardarTrabajoEvaluacion(
        int $evaluacionId,
        array $payload
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $payload = $this->stripEvaluationDeadlineField($payload);

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/trabajo";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (guardar trabajo evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post(
                    $this->buildUrl($endpoint),
                    $payload
                ),
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    /* ===================== OBTENER EVALUACION COMPLETA ===================== */

public function obtenerEvaluacion(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/evaluaciones/{$evaluacionId}";

    $rol = session(AuthSessionKeys::USER_ROLE);
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (obtener evaluacion).', [
        'evaluacion_id' => $evaluacionId,
        'rol' => $rol,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
        ],
        [200]
    );
}

public function obtenerTrabajoEvaluacion(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/evaluaciones/{$evaluacionId}/trabajo";

    $rol = session(AuthSessionKeys::USER_ROLE);
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (obtener trabajo evaluacion).', [
        'evaluacion_id' => $evaluacionId,
        'rol' => $rol,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
        ],
        [200]
    );
}

public function obtenerTrabajoAlumno(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/trabajo";

    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (obtener trabajo alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function guardarEntregaTrabajoAlumno(
    int $evaluacionId,
    array $payload
): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/trabajo/entrega";
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (guardar entrega trabajo alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
        'archivos_nuevos' => count($payload['archivos'] ?? []),
        'archivos_eliminar' => count($payload['archivos_eliminar'] ?? []),
    ]);

    return $this->execute(
        $endpoint,
        function () use ($endpoint, $payload, $correo) {
            $request = $this->client()
                ->asMultipart()
                ->withHeaders([
                    'X-USER-ROL' => 'alumno',
                    'X-USER-EMAIL' => $correo,
                ]);

            $files = $payload['archivos'] ?? [];
            unset($payload['archivos']);

            if (!empty($files)) {
                $request = $this->attachFiles($request, $files, 'archivos[]');
            }

            return $request->post($this->buildUrl($endpoint), $payload);
        },
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200, 201]
    );
}

public function finalizarEntregaTrabajoAlumno(
    int $evaluacionId,
    array $payload
): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/trabajo/finalizar";
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (finalizar entrega trabajo alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->post($this->buildUrl($endpoint), $payload),
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200, 201]
    );
}

public function descargarArchivoEntregaTrabajoAlumno(int $archivoId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/entregas/archivos/{$archivoId}/descargar";
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (descargar archivo entrega trabajo alumno).', [
        'archivo_id' => $archivoId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->executeRaw(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
                'Accept' => '*/*',
            ])
            ->withOptions([
                'stream' => true,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'archivo_id' => $archivoId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function publicarEvaluacion(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/evaluaciones/{$evaluacionId}/publicar";

    $rol = session(AuthSessionKeys::USER_ROLE);
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (publicar evaluacion).', [
        'evaluacion_id' => $evaluacionId,
        'rol' => $rol,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
            ])
            ->post($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
        ],
        [200]
    );
}

   public function duplicarEvaluacion(int $evaluacionId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/duplicar";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (duplicar evaluacion).', [
            'evaluacion_id' => $evaluacionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post($this->buildUrl($endpoint)),
            [
                'evaluacion_id' => $evaluacionId,
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200, 201]
        );
    }

    public function listarEvaluacionesPublicadasPorCursoYTipo(
    int $cursoId,
    int $tipoId
): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/evaluaciones/curso/{$cursoId}/tipo/{$tipoId}";

    $rol = session(AuthSessionKeys::USER_ROLE);
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (evaluaciones por curso y tipo).', [
        'curso_id' => $cursoId,
        'tipo_id' => $tipoId,
        'rol' => $rol,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'curso_id' => $cursoId,
            'tipo_id' => $tipoId,
            'rol' => $rol,
            'correo' => $correo,
        ],
        [200]
    );
}

    public function obtenerEvaluacionesSesion(
        int $cursoId,
        int $sesionId
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$cursoId}/sesiones/{$sesionId}/evaluaciones";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (evaluaciones sesion).', [
            'curso_id' => $cursoId,
            'sesion_id' => $sesionId,
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_id' => $cursoId,
                'sesion_id' => $sesionId,
            ],
            [200]
        );
    }

    public function obtenerPlanEvaluacionCurso(int $cursoId): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/cursos/{$cursoId}/evaluaciones/plan";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->get($this->buildUrl($endpoint)),
            [
                'curso_id' => $cursoId,
                'endpoint' => $endpoint,
            ],
            [200]
        );
    }

    public function agregarEvaluacionesSesion(int $sesionId, array $evaluaciones): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sesionId}/evaluacion";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        Log::debug('API Servicios request (agregar evaluaciones sesion).', [
            'sesion_id' => $sesionId,
            'evaluaciones' => $evaluaciones,
            'endpoint' => $endpoint,
        ]);

        $payload = [
            'evaluaciones' => collect($evaluaciones)
                ->map(function ($item) {
                    if (is_array($item)) {
                        return array_filter([
                            'id' => $item['id'] ?? $item['evaluacion_id'] ?? null,
                            'fecha_limite' => $item['fecha_limite'] ?? null,
                            'hito_nombre' => $item['hito_nombre'] ?? null,
                            'hito_orden' => $item['hito_orden'] ?? null,
                            'grupo_nombre' => $item['grupo_nombre'] ?? null,
                            'plazo_dias' => $item['plazo_dias'] ?? null,
                        ], fn ($value) => $value !== null);
                    }

                    return [
                        'id' => $item,
                    ];
                })
                ->filter(fn ($item) => !empty($item['id']))
                ->values()
                ->all(),
        ];

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post(
                    $this->buildUrl($endpoint),
                    $payload
                ),
            [
                'sesion_id' => $sesionId,
                'payload' => $payload,
            ],
            [200]
        );
    }

    public function eliminarEvaluacionSesion(
    int $sesionId,
    int $evaluacionId
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sesionId}/evaluacion/{$evaluacionId}";

        Log::debug('API Servicios request (eliminar evaluacion sesion).', [
            'sesion_id' => $sesionId,
            'evaluacion_id' => $evaluacionId,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->delete($this->buildUrl($endpoint)),
            [
                'sesion_id' => $sesionId,
                'evaluacion_id' => $evaluacionId,
            ],
            [200]
        );
    }

    public function actualizarEvaluacionSesion(
        int $sesionId,
        int $evaluacionId,
        array $payload
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/sesiones/{$sesionId}/evaluacion/{$evaluacionId}";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        $normalizedPayload = array_filter([
            'fecha_limite' => $payload['fecha_limite'] ?? null,
            'hito_nombre' => $payload['hito_nombre'] ?? null,
            'hito_orden' => $payload['hito_orden'] ?? null,
            'grupo_nombre' => $payload['grupo_nombre'] ?? null,
            'plazo_dias' => $payload['plazo_dias'] ?? null,
        ], fn ($value) => $value !== null);

        Log::debug('API Servicios request (actualizar evaluacion sesion).', [
            'sesion_id' => $sesionId,
            'evaluacion_id' => $evaluacionId,
            'payload' => $normalizedPayload,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->put(
                    $this->buildUrl($endpoint),
                    $normalizedPayload
                ),
            [
                'sesion_id' => $sesionId,
                'evaluacion_id' => $evaluacionId,
                'payload' => $normalizedPayload,
            ],
            [200]
        );
    }

    private function stripEvaluationDeadlineField(array $payload): array
    {
        unset($payload['fecha_limite']);

        if (isset($payload['trabajo']) && is_array($payload['trabajo'])) {
            unset($payload['trabajo']['fecha_limite']);
        }

        return $payload;
    }

    public function evaluarEvaluacion(
    int $evaluacionId,
    array $respuestas
    ): ServiceResult
    {
        if ($fail = $this->validateConfig()) {
            return $fail;
        }

        $endpoint = "/v1/evaluaciones/{$evaluacionId}/evaluar";

        $rol = session(AuthSessionKeys::USER_ROLE);
        $correo = session(AuthSessionKeys::USER_EMAIL);

        $payload = [
            'respuestas' => $respuestas
        ];

        Log::debug('API Servicios request (evaluar evaluacion)', [
            'evaluacion_id' => $evaluacionId,
            'payload' => $payload,
            'total_respuestas' => count($respuestas),
            'rol' => $rol,
            'correo' => $correo,
            'endpoint' => $endpoint,
        ]);

        return $this->execute(
            $endpoint,
            fn() => $this->client()
                ->withHeaders([
                    'X-USER-ROL' => $rol,
                    'X-USER-EMAIL' => $correo,
                ])
                ->post(
                    $this->buildUrl($endpoint),
                    $payload
                ),
            [
                'evaluacion_id' => $evaluacionId,
                'total_respuestas' => count($respuestas),
                'rol' => $rol,
                'correo' => $correo,
            ],
            [200]
        );
    }

    /* ===================== EVALUACIONES - RENDICION ALUMNO ===================== */

public function obtenerOIniciarRendicionAlumno(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/rendicion";

    $correo = session(AuthSessionKeys::USER_EMAIL);
    Log::debug('API Servicios request (obtener o iniciar rendicion alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function guardarRespuestaRendicionAlumno(
    int $evaluacionId,
    int $preguntaId,
    ?int $opcionId
): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/rendicion/respuesta";

    $correo = session(AuthSessionKeys::USER_EMAIL);
    $payload = [
        'pregunta_id' => $preguntaId,
        'opcion_id' => $opcionId,
    ];

    Log::debug('API Servicios request (guardar respuesta rendicion alumno).', [
        'evaluacion_id' => $evaluacionId,
        'pregunta_id' => $preguntaId,
        'opcion_id' => $opcionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->post(
                $this->buildUrl($endpoint),
                $payload
            ),
        [
            'evaluacion_id' => $evaluacionId,
            'pregunta_id' => $preguntaId,
            'opcion_id' => $opcionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function obtenerResultadoParcialRendicionAlumno(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/rendicion/parcial";

    $correo = session(AuthSessionKeys::USER_EMAIL);
    Log::debug('API Servicios request (resultado parcial rendicion alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function finalizarRendicionAlumno(int $evaluacionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/evaluaciones/{$evaluacionId}/rendicion/finalizar";

    $correo = session(AuthSessionKeys::USER_EMAIL);
    Log::debug('API Servicios request (finalizar rendicion alumno).', [
        'evaluacion_id' => $evaluacionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->post($this->buildUrl($endpoint)),
        [
            'evaluacion_id' => $evaluacionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function obtenerResultadoFinalRendicionAlumno(int $rendicionId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/alumno/rendiciones/{$rendicionId}";

    $correo = session(AuthSessionKeys::USER_EMAIL);
    Log::debug('API Servicios request (resultado final rendicion alumno).', [
        'rendicion_id' => $rendicionId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'rendicion_id' => $rendicionId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function listarNotasAlumnoPorCurso(int $cursoId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/evaluaciones/cursos/{$cursoId}/notas-alumno";

    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (notas alumno por curso).', [
        'curso_id' => $cursoId,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => 'alumno',
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'curso_id' => $cursoId,
            'correo' => $correo,
        ],
        [200]
    );
}

public function descargarArchivoEntregaTrabajoBackoffice(int $archivoId): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = "/v1/backoffice/evaluaciones/entregas/archivos/{$archivoId}/descargar";

    $rol = session(AuthSessionKeys::USER_ROLE);
    $correo = session(AuthSessionKeys::USER_EMAIL);

    Log::debug('API Servicios request (descargar archivo entrega trabajo backoffice).', [
        'archivo_id' => $archivoId,
        'rol' => $rol,
        'correo' => $correo,
        'endpoint' => $endpoint,
    ]);

    return $this->executeRaw(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
                'Accept' => '*/*',
            ])
            ->withOptions([
                'stream' => true,
            ])
            ->get($this->buildUrl($endpoint)),
        [
            'archivo_id' => $archivoId,
            'rol' => $rol,
            'correo' => $correo,
        ],
        [200]
    );
}

/* ===================== PAGOS ===================== */

public function listarPagosPorCorreo(string $correo): ServiceResult
{
    if ($fail = $this->validateConfig()) {
        return $fail;
    }

    $endpoint = '/v1/pagos';

    $rol = session(AuthSessionKeys::USER_ROLE);

    Log::debug('API Servicios request (pagos por correo).', [
        'correo' => $correo,
        'rol' => $rol,
        'endpoint' => $endpoint,
    ]);

    return $this->execute(
        $endpoint,
        fn() => $this->client()
            ->withHeaders([
                'X-USER-ROL' => $rol,
                'X-USER-EMAIL' => $correo,
            ])
            ->get($this->buildUrl($endpoint), [
                'email' => $correo,
            ]),
        [
            'correo' => $correo,
            'rol' => $rol,
        ],
        [200]
    );
}

/* ===================== ASISTENCIA ===================== */

public function registrarIntentoZoom(int $courseId, int $sessionId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/cursos/{$courseId}/sesiones/{$sessionId}/zoom/join";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->post($this->buildUrl($endpoint)),
        ['course_id' => $courseId, 'session_id' => $sessionId],
        [200]
    );
}

public function listarAsistenciaCurso(int $courseId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/cursos/{$courseId}/asistencias";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->get($this->buildUrl($endpoint)),
        ['course_id' => $courseId],
        [200]
    );
}

public function listarResumenesAsistenciaCursos(): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = '/v1/asistencias/cursos/resumen';
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->get($this->buildUrl($endpoint)),
        [],
        [200]
    );
}

public function listarResumenSesionesAsistencia(int $courseId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/cursos/{$courseId}/asistencias/resumen";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->get($this->buildUrl($endpoint)),
        ['course_id' => $courseId],
        [200]
    );
}

public function listarAsistenciaSesion(int $courseId, int $sessionId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/cursos/{$courseId}/sesiones/{$sessionId}/asistencias";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->get($this->buildUrl($endpoint)),
        ['course_id' => $courseId, 'session_id' => $sessionId],
        [200]
    );
}

public function listarMiAsistencia(int $courseId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/alumno/cursos/{$courseId}/asistencia";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => 'alumno',
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->get($this->buildUrl($endpoint)),
        ['course_id' => $courseId],
        [200]
    );
}

public function corregirAsistencia(int $sessionId, int $attendanceId, string $status, string $reason): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/sesiones/{$sessionId}/asistencias/{$attendanceId}";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->patch($this->buildUrl($endpoint), ['status' => $status, 'reason' => $reason]),
        ['session_id' => $sessionId, 'attendance_id' => $attendanceId],
        [200]
    );
}

public function sincronizarAsistencia(int $sessionId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/sesiones/{$sessionId}/asistencias/sync";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->post($this->buildUrl($endpoint)),
        ['session_id' => $sessionId],
        [200]
    );
}

public function identificarParticipanteAsistencia(int $sessionId, int $eventId, int $attendanceId): ServiceResult
{
    if ($fail = $this->validateConfig()) return $fail;
    $endpoint = "/v1/sesiones/{$sessionId}/asistencias/identify";
    return $this->execute(
        $endpoint,
        fn() => $this->client()->withHeaders([
            'X-USER-ROL' => session(AuthSessionKeys::USER_ROLE),
            'X-USER-EMAIL' => session(AuthSessionKeys::USER_EMAIL),
        ])->post($this->buildUrl($endpoint), [
            'event_id' => $eventId,
            'attendance_id' => $attendanceId,
        ]),
        ['session_id' => $sessionId, 'event_id' => $eventId, 'attendance_id' => $attendanceId],
        [200]
    );
}

}
