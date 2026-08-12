<?php

/** @var \Laravel\Lumen\Routing\Router $router */
$router->get('/', function () {
    return response()->json([
        'ok' => true,
        'service' => 'aula-virtual-api-servicios',
    ]);
});

$router->get('/up', function () {
    return response()->json([
        'ok' => true,
    ]);
});

$router->post('/v1/login', 'UsuarioController@login');
$router->post('/v1/webhooks/zoom', 'ZoomWebhookController@handle');

$router->get('/Certificados/{token}', 'AlumnoController@descargarCertificadoPublico');

$router->group(['prefix' => 'v1', 'middleware' => 'internal.auth'], function () use ($router) {

$router->post('/cursos/{courseId}/sesiones/{sessionId}/zoom/join', 'AttendanceController@join');
$router->get('/asistencias/cursos/resumen', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@summaries',
]);
$router->get('/cursos/{courseId}/asistencias/resumen', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@courseSummary',
]);
$router->get('/cursos/{courseId}/asistencias', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@course',
]);
$router->get('/cursos/{courseId}/sesiones/{sessionId}/asistencias', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@session',
]);
$router->get('/alumno/cursos/{courseId}/asistencia', [
    'middleware' => 'role:alumno',
    'uses' => 'AttendanceController@student',
]);
$router->get('/alumno/cursos/{courseId}/certificado', [
    'middleware' => 'role:alumno',
    'uses' => 'AlumnoController@obtenerCertificadoAlumnoCurso',
]);
$router->patch('/sesiones/{sessionId}/asistencias/{attendanceId}', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@update',
]);
$router->post('/sesiones/{sessionId}/asistencias/identify', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@identify',
]);
$router->post('/sesiones/{sessionId}/asistencias/sync', [
    'middleware' => 'role:admin,administrador,operador,docente,profesor',
    'uses' => 'AttendanceController@sync',
]);

/*
|--------------------------------------------------------------------------
| CURSOS
|--------------------------------------------------------------------------
*/

// Cursos
$router->get('/alumno/resumen', 'CursoController@resumenAlumno');
$router->get('/backoffice/resumen', 'CursoController@resumenBackoffice');
$router->get('/cursos', 'CursoController@listar');
$router->get('/cursos/{id}', 'CursoController@obtener');
$router->get('/cursos/{cursoEdicionId}/alumnos', 'CursoController@listarAlumnosCurso');

    /*
|--------------------------------------------------------------------------
| PAGOS
|--------------------------------------------------------------------------
*/

$router->get(
    '/pagos',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'PagoController@listarPorCorreo'
    ]
);

/*
|--------------------------------------------------------------------------
| EVALUACIONES - CURSOS
|--------------------------------------------------------------------------
*/

$router->get(
    '/evaluaciones/cursos',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'CursoController@listarParaEvaluaciones'
    ]
);

$router->get(
    '/calificaciones/cursos',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'CursoController@listarParaCalificaciones'
    ]
);

$router->get(
    '/calificaciones/cursos/{cursoId}',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@resumenCalificacionesCurso'
    ]
);

$router->get(
    '/cursos/{cursoId}/evaluaciones',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@listarPorCurso'
    ]
);

$router->post(
    '/evaluaciones',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@crear'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/autosave',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@autosave'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/trabajo',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@guardarTrabajo'
    ]
);

$router->post(
    '/docente/evaluaciones/{evaluacionId}/subsanaciones/examen',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@registrarSubsanacionExamen'
    ]
);

$router->post(
    '/docente/evaluaciones/{evaluacionId}/subsanaciones/trabajo',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@registrarSubsanacionTrabajo'
    ]
);

$router->get(
    '/docente/evaluaciones/{evaluacionId}/subsanaciones',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@listarSubsanaciones'
    ]
);

$router->put(
    '/docente/evaluaciones/{evaluacionId}/subsanaciones/{subsanacionId}',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@actualizarSubsanacion'
    ]
);

$router->get(
    '/docente/subsanaciones/evidencia',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@descargarEvidenciaSubsanacion'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/publicar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@publicar'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/duplicar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@duplicar'
    ]
);

$router->get(
    '/evaluaciones/curso/{cursoId}/tipo/{tipoId}',
    'EvaluacionController@listarPublicadasPorCursoYTipo'
);

$router->get(
    '/evaluaciones/{evaluacionId}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'EvaluacionController@obtener'
    ]
);

$router->get(
    '/evaluaciones/{evaluacionId}/participantes',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@listarParticipantes'
    ]
);

$router->get(
    '/evaluaciones/{evaluacionId}/trabajo',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'EvaluacionController@obtenerTrabajo'
    ]
);

$router->get(
    '/evaluaciones/{evaluacionId}/entregas/{entregaId}/revision',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@obtenerDetalleRevision'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/entregas/{entregaId}/revision',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionController@guardarDetalleRevision'
    ]
);

$router->get(
    '/alumno/evaluaciones/{evaluacionId}/trabajo',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionController@obtenerTrabajoAlumno'
    ]
);

$router->post(
    '/alumno/evaluaciones/{evaluacionId}/trabajo/entrega',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionController@guardarEntregaTrabajo'
    ]
);

$router->post(
    '/alumno/evaluaciones/{evaluacionId}/trabajo/finalizar',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionController@finalizarEntregaTrabajo'
    ]
);

$router->get(
    '/alumno/evaluaciones/entregas/archivos/{archivoId}/descargar',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionController@descargarArchivoEntrega'
    ]
);

$router->get(
'/backoffice/evaluaciones/entregas/archivos/{archivoId}/descargar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'EvaluacionRendicionController@descargarArchivoEntregaBackoffice'
    ]
);

$router->post(
    '/evaluaciones/{evaluacionId}/evaluar',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'EvaluacionController@evaluar'
    ]
);

$router->get(
    'evaluaciones/cursos/{cursoId:[0-9]+}/notas-alumno',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@listarNotasAlumnoPorCurso'
    ]
);

/*
|--------------------------------------------------------------------------
| RENDICIÓN DE EVALUACIONES (EXÁMENES)
|--------------------------------------------------------------------------
*/

$router->get(
    '/alumno/evaluaciones/{evaluacionId}/rendicion',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@obtenerOIniciar'
    ]
);

$router->post(
    '/alumno/evaluaciones/{evaluacionId}/rendicion/respuesta',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@guardarRespuesta'
    ]
);

$router->get(
    '/alumno/evaluaciones/{evaluacionId}/rendicion/parcial',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@obtenerResultadoParcial'
    ]
);

$router->post(
    '/alumno/evaluaciones/{evaluacionId}/rendicion/finalizar',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@finalizar'
    ]
);

$router->get(
    '/alumno/rendiciones/{rendicionId}',
    [
        'middleware' => 'role:alumno',
        'uses' => 'EvaluacionRendicionController@obtenerResultadoFinal'
    ]
);

/*
|--------------------------------------------------------------------------
| SESIONES
|--------------------------------------------------------------------------
*/

$router->get('/curso/{cursoId}/sesiones', 'SesionController@listarPorCurso');

$router->get(
    '/alumno/cursos/{cursoId}/sesiones/light',
    [
        'middleware' => 'role:alumno',
        'uses' => 'SesionController@listarPorCursoAlumnoLight'
    ]
);

$router->get(
    '/alumno/cursos/{cursoId}/sesiones/{sesionId}/detalle',
    [
        'middleware' => 'role:alumno',
        'uses' => 'SesionController@detalleAlumno'
    ]
);

$router->get(
    '/cursos/{cursoId}/sesiones/{sesionId}/evaluaciones',
    'SesionController@obtenerEvaluaciones'
);

$router->get(
    '/cursos/{cursoId}/evaluaciones/plan',
    [
        'middleware' => 'role:admin,operador,docente,profesor',
        'uses' => 'SesionController@planEvaluacionCurso'
    ]
);

$router->post(
    '/sesiones/{sesionId}/evaluacion',
    'SesionController@actualizarEvaluacion'
);

$router->put(
    '/sesiones/{sesionId}/evaluacion/{evaluacionId}',
    'SesionController@actualizarFechaLimiteEvaluacion'
);

$router->delete(
    '/sesiones/{sesionId}/evaluacion/{evaluacionId}',
    'SesionController@eliminarEvaluacion'
);

/*
|--------------------------------------------------------------------------
| VIDEO DE SESION
|--------------------------------------------------------------------------
| Nuevo modelo:
| Aula Virtual sube a Google Drive
| API externa solo registra metadata / estado / BD
*/

// Registrar inicio de carga (Aula ya creó sesión resumible en Drive)
$router->post(
    '/sesiones/{sesionId}/video/upload-started',
    'SesionVideoController@uploadStarted'
);

// Actualizar progreso de carga (bytes subidos)
$router->post(
    '/sesiones/{sesionId}/video/upload-progress',
    'SesionVideoController@uploadProgress'
);

// Finalizar carga y registrar drive_file_id
$router->post(
    '/sesiones/{sesionId}/video/upload-completed',
    'SesionVideoController@uploadCompleted'
);

// Registrar error de carga
$router->post(
    '/sesiones/{sesionId}/video/upload-error',
    'SesionVideoController@uploadError'
);

// Cancelar carga
$router->post(
    '/sesiones/{sesionId}/video/upload-cancelled',
    'SesionVideoController@uploadCancelled'
);

// Actualizar estado del video: processing / ready / missing
$router->post(
    '/sesiones/{sesionId}/video/status-updated',
    'SesionVideoController@statusUpdated'
);

// Eliminar registro de video
$router->post(
    '/sesiones/{sesionId}/video/deleted',
    'SesionVideoController@deleted'
);

// Consultar progreso para reanudación
$router->post(
    '/sesiones/{sesionId}/video/chat-uploaded',
    'SesionVideoController@chatUploaded'
);

$router->post(
    '/sesiones/{sesionId}/video/chat-deleted',
    'SesionVideoController@chatDeleted'
);

$router->get(
    '/sesiones/{sesionId}/video/upload-progress',
    'SesionVideoController@getUploadProgress'
);

$router->get(
    '/sesiones/{sesionId}/video/status',
    'SesionVideoController@status'
);

/*
|--------------------------------------------------------------------------
| ANUNCIOS
|--------------------------------------------------------------------------
*/

$router->get('/anuncios/{entidadTipo}/{entidadId}', 'CursoAnuncioController@listar');
$router->post('/anuncios/{entidadTipo}/{entidadId}/con-lectura', 'CursoAnuncioController@listarConEstadoLectura');
$router->post('/anuncios', 'CursoAnuncioController@crear');
$router->put('/anuncios/{anuncioId}', 'CursoAnuncioController@editar');
$router->delete('/anuncios/{anuncioId}', 'CursoAnuncioController@eliminar');
$router->post('/anuncios/{anuncioId}/leer', 'CursoAnuncioController@marcarLeido');
$router->post('/anuncios/{entidadTipo}/{entidadId}/leer-todos', 'CursoAnuncioController@marcarTodosLeidos');

/*
|--------------------------------------------------------------------------
| MATERIALES
|--------------------------------------------------------------------------
*/

$router->get('/sesiones/{sesionId}/materiales', 'SesionMaterialController@listar');
$router->post('/sesiones/{sesionId}/materiales', 'SesionMaterialController@crear');
$router->put('/sesiones/{sesionId}/materiales/{id}', 'SesionMaterialController@actualizar');
$router->delete('/sesiones/{sesionId}/materiales/{id}', 'SesionMaterialController@eliminar');
$router->get('/materiales/{id}/descargar', 'SesionMaterialController@descargar');

/*
|--------------------------------------------------------------------------
| ENCUESTAS
|--------------------------------------------------------------------------
*/

$router->get('/encuestas/cursos', [
    'middleware' => 'role:admin,administrador,operador',
    'uses' => 'CursoController@listarParaEncuestas',
]);
$router->get('/alumno/cursos/{course}/sesiones/{session}/encuestas/{link}', [
    'middleware' => 'role:alumno',
    'uses' => 'StudentSurveyController@show',
]);
$router->post('/alumno/cursos/{course}/sesiones/{session}/encuestas/{link}/respuestas', [
    'middleware' => 'role:alumno',
    'uses' => 'StudentSurveyController@store',
]);
$router->get('/encuestas', [
    'middleware' => 'role:admin,administrador',
    'uses' => 'EncuestaController@listar',
]);
$router->get('/encuestas/{id}', [
    'middleware' => 'role:admin,administrador',
    'uses' => 'EncuestaController@obtener',
]);
$router->get('/encuestas/{id}/formulario', [
    'middleware' => 'role:admin,administrador,operador,alumno',
    'uses' => 'EncuestaController@formulario',
]);
$router->post('/encuestas', [
    'middleware' => 'role:admin,administrador',
    'uses' => 'EncuestaController@crear',
]);
$router->put('/encuestas/{id}', [
    'middleware' => 'role:admin,administrador',
    'uses' => 'EncuestaController@actualizar',
]);
$router->delete('/encuestas/{id}', [
    'middleware' => 'role:admin,administrador',
    'uses' => 'EncuestaController@eliminar',
]);

/*
|--------------------------------------------------------------------------
| RESPUESTAS DE ENCUESTA
|--------------------------------------------------------------------------
*/

$router->post('/encuestas/verificar', [
    'middleware' => 'role:alumno',
    'uses' => 'EncuestaRespuestaController@verificar',
]);
$router->post('/encuestas/responder', [
    'middleware' => 'role:alumno',
    'uses' => 'EncuestaRespuestaController@registrar',
]);
$router->get('/sesiones/{sesionId}/encuestas/respuestas', [
    'middleware' => 'role:admin,administrador,operador',
    'uses' => 'EncuestaRespuestaController@estadisticaSesion',
]);

$router->get('/cursos/{cursoEdicionId}/encuestas/detalle-resultados', [
    'middleware' => 'role:admin,administrador,operador',
    'uses' => 'EncuestaRespuestaController@detalleResultadosPorSesion',
]);

/*
|--------------------------------------------------------------------------
| ALUMNOS - PERFIL Y CONTACTO
|--------------------------------------------------------------------------
*/

$router->get(
    '/alumno/perfil',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@obtenerMiPerfil'
    ]
);

$router->put(
    '/alumno/perfil',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@actualizarMiPerfil'
    ]
);

$router->post(
    '/alumno/perfil/adjuntos',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@actualizarAdjuntosPerfil'
    ]
);

$router->get(
    '/alumno/perfil/adjuntos/{tipo}',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@descargarAdjuntoPerfil'
    ]
);

$router->post(
    '/alumnos',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'AlumnoController@insertar'
    ]
);

$router->post(
    '/alumnos/contacto/solicitudes',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@enviarSolicitudContacto'
    ]
);

$router->put(
    '/alumnos/contacto/solicitudes/{solicitudId}/responder',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@responderSolicitudContacto'
    ]
);

$router->get(
    '/alumnos/contacto/solicitudes',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@consultarSolicitudesPorAlumnoDesdeQuery'
    ]
);

$router->get(
    '/alumnos/{correo:.+}/contacto/solicitudes',
    [
        'middleware' => 'role:alumno',
        'uses' => 'AlumnoController@consultarSolicitudesPorAlumno'
    ]
);

$router->get(
    '/alumnos/perfil-publico',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'AlumnoController@obtenerPerfilPublicoDesdeQuery'
    ]
);

$router->get(
    '/alumnos/{correo:.+}/perfil-publico',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'AlumnoController@obtenerPerfilPublico'
    ]
);

$router->get(
    '/alumnos/{correo:.+}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'AlumnoController@obtenerPorCorreo'
    ]
);

$router->put(
    '/alumnos/{correo:.+}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'AlumnoController@actualizar'
    ]
);


/*
|--------------------------------------------------------------------------
| ALUMNOS - CERTIFICADOS
|--------------------------------------------------------------------------
*/

$router->post(
    '/alumnos/certificados/pendiente',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'AlumnoController@crearCertificadoPendiente'
    ]
);

$router->post(
    '/alumnos/certificados/adjuntar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'AlumnoController@adjuntarCertificado'
    ]
);

$router->post(
    '/alumnos/certificados/{certificadoId}/enviar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'AlumnoController@enviarCertificado'
    ]
);

$router->get(
    '/cursos/{cursoEdicionId}/certificados',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'AlumnoController@listarCertificadosPorCursoEdicion'
    ]
);

$router->post(
    '/cursos/{cursoEdicionId}/certificados/sync-sga',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'AlumnoController@sincronizarDiplomasSgaCurso'
    ]
);
/*
|--------------------------------------------------------------------------
| CHAT / CONVERSACIÓN DEL CURSO
|--------------------------------------------------------------------------
*/

$router->get(
    '/chat/salas/contexto/{tipoContexto}/{idContexto}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@obtenerOCrearSala'
    ]
);

$router->get(
    '/chat/salas/{salaId}/mensajes',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@listarMensajes'
    ]
);

$router->post(
    '/chat/salas/{salaId}/mensajes',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@crearMensaje'
    ]
);

$router->get(
    '/chat/mensajes/{mensajeId}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@obtenerMensaje'
    ]
);

$router->delete(
    '/chat/mensajes/{mensajeId}',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@eliminarMensaje'
    ]
);

$router->post(
    '/chat/mensajes/{mensajeId}/fijar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'ChatController@fijarMensaje'
    ]
);

$router->post(
    '/chat/mensajes/{mensajeId}/desfijar',
    [
        'middleware' => 'role:admin,operador',
        'uses' => 'ChatController@desfijarMensaje'
    ]
);

$router->get(
    '/chat/salas/{salaId}/mensajes/fijados',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@listarMensajesFijados'
    ]
);

$router->get(
    '/chat/salas/{salaId}/participantes',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@listarParticipantes'
    ]
);

$router->get(
    '/chat/salas/{salaId}/mensajes/buscar',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@buscarMensajes'
    ]
);

$router->get(
    '/chat/salas/{salaId}/resumen',
    [
        'middleware' => 'role:admin,operador,alumno',
        'uses' => 'ChatController@resumenSala'
    ]
);

/*
|--------------------------------------------------------------------------
| PARAMETROS
|--------------------------------------------------------------------------
*/

$router->get('/parametros', 'ParametroController@listar');
$router->get('/parametros/maestro/{id}', 'ParametroController@listarPorMaestro');
$router->get('/parametros/{idMaestro}/{idValor}', 'ParametroController@obtener');

});
