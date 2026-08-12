<?php

    use Illuminate\Support\Facades\Route;
    use App\Http\Controllers\Auth\LoginController;
    use App\Http\Controllers\MisCursos\CursosController;
    use App\Http\Controllers\MisCursos\CursoAnunciosController;
    use App\Http\Controllers\Backoffice\CoursesController;
    use App\Http\Controllers\Backoffice\SesionVideoController;
    use App\Http\Controllers\MisCursos\SurveyController;
    use App\Http\Controllers\MisCursos\SurveyResponseController;
    use App\Http\Controllers\Backoffice\EvaluationsController;
    use App\Http\Controllers\Backoffice\QualificationsController;
    use App\Http\Controllers\Backoffice\CertificatesController;
    use App\Http\Controllers\Backoffice\SurveysController as BackofficeSurveysController;
    use App\Http\Controllers\ParameterController;
    use App\Http\Controllers\Backoffice\SessionsController;
    use App\Http\Controllers\MisCursos\EvaluationController;
    use App\Http\Controllers\MisCursos\EvaluationSubmissionController;
    use App\Http\Controllers\MisCursos\PaymentController;
    use App\Http\Controllers\MisCursos\PerfilController as AlumnoPerfilController;
    use App\Http\Controllers\ChatController;
    use App\Http\Controllers\CommunityParticipantsController;
    use App\Http\Controllers\CursoParticipanteController;
    use App\Http\Controllers\ZoomJoinController;
    use App\Http\Controllers\Backoffice\AttendanceController as BackofficeAttendanceController;
    use App\Http\Controllers\MisCursos\AttendanceController as StudentAttendanceController;
    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */

    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('auth.login.post');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get(
        'community/courses/{cursoEdicionId}/participants/{correo}/photo',
        [CommunityParticipantsController::class, 'photo']
    )->name('community.courses.participants.photo');


    /*
    |--------------------------------------------------------------------------
    | AUTH SESSION
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth.session')->group(function () {

        Route::post('courses/{course}/sessions/{session}/zoom/join', ZoomJoinController::class)
            ->name('zoom.join');

        Route::get(
            'courses/sessions/{session}/video/chat/preview',
            [SesionVideoController::class, 'previewChat']
        )->name('sessions.video.chat.preview');

        Route::get(
            'courses/sessions/{session}/video/chat/download',
            [SesionVideoController::class, 'downloadChat']
        )->name('sessions.video.chat.download');

        /*
        |--------------------------------------------------------------------------
        | PARAMETROS (COMPARTIDO)
        |--------------------------------------------------------------------------
        */
        Route::get(
            'parameters/{id}',
            [ParameterController::class, 'porMaestro']
        )->name('parameters.porMaestro');

        Route::post(
            'chat/salas/{sala}/mensajes',
            [ChatController::class, 'storePrincipal']
        )->name('chat.mensajes.store');
        Route::get(
            'chat/salas/{sala}/mensajes',
            [ChatController::class, 'index']
        )->name('chat.mensajes.index');
        Route::delete(
            'chat/mensajes/{mensaje}',
            [ChatController::class, 'destroy']
        )->name('chat.mensajes.destroy');

        Route::get(
            'community/courses/{cursoEdicionId}/participants',
            [CommunityParticipantsController::class, 'index']
        )->name('community.courses.participants.index');

        Route::get(
            'community/courses/{cursoEdicionId}/participants/{correo}/profile',
            [CommunityParticipantsController::class, 'profile']
        )->name('community.courses.participants.profile');

        Route::get(
            'community/courses/{cursoEdicionId}/participants/{correo}/cv',
            [CommunityParticipantsController::class, 'cv']
        )->name('community.courses.participants.cv');

        Route::post(
            'mis-cursos/{cursoEdicionId}/participantes/{correo}/solicitar-contacto',
            [CursoParticipanteController::class, 'solicitarContacto']
        )->name('curso.participantes.solicitar-contacto');

        Route::get(
            'mi-perfil',
            [AlumnoPerfilController::class, 'show']
        )->name('alumno.perfil.show');

        Route::put(
            'mi-perfil',
            [AlumnoPerfilController::class, 'actualizar']
        )->name('alumno.perfil.actualizar');

        Route::get(
            'mi-perfil/adjuntos/{tipo}',
            [AlumnoPerfilController::class, 'descargarAdjunto']
        )->name('alumno.perfil.adjuntos.descargar');

        Route::get(
            'mi-perfil/solicitudes-contacto',
            [AlumnoPerfilController::class, 'solicitudesContacto']
        )->name('alumno.perfil.solicitudes-contacto');

        Route::put(
            'mi-perfil/solicitudes-contacto/{solicitudId}/responder',
            [AlumnoPerfilController::class, 'responderSolicitudContacto']
        )->name('alumno.perfil.solicitudes.responder');

        /*
        |--------------------------------------------------------------------------
        | BACKOFFICE
        |--------------------------------------------------------------------------
        */

        Route::prefix('backoffice')
            ->name('backoffice.')
            ->group(function () {

            Route::get('attendance', [BackofficeAttendanceController::class, 'index'])->name('attendance.index');
            Route::get('attendance/export', [BackofficeAttendanceController::class, 'export'])->name('attendance.export');
            Route::get('attendance/{course}/export', [BackofficeAttendanceController::class, 'export'])->name('attendance.course.export');
            Route::get('attendance/{course}', [BackofficeAttendanceController::class, 'show'])->name('attendance.show');
            Route::patch('attendance/sessions/{session}/records/{attendance}', [BackofficeAttendanceController::class, 'update'])->name('attendance.update');
            Route::post('attendance/sessions/{session}/sync', [BackofficeAttendanceController::class, 'sync'])->name('attendance.sync');
            Route::post('attendance/sessions/{session}/identify', [BackofficeAttendanceController::class, 'identify'])->name('attendance.identify');

            Route::get('courses', [CoursesController::class, 'index'])
                ->name('courses');

             /* ===============================
            EVALUATIONS
            =============================== */

            Route::get(
                'qualifications',
                [QualificationsController::class, 'index']
            )->name('qualifications.index');

            Route::get(
                'qualifications/{courseId}',
                [QualificationsController::class, 'show']
            )->name('qualifications.show');

            Route::get(
                'qualifications/{courseId}/notes',
                [QualificationsController::class, 'notes']
            )->name('qualifications.notes');

            Route::get(
                'qualifications/{courseId}/notes/subsanation',
                [QualificationsController::class, 'subsanation']
            )->name('qualifications.notes.subsanation');

            Route::post(
                'qualifications/{courseId}/notes/subsanation',
                [QualificationsController::class, 'saveSubsanation']
            )->name('qualifications.notes.subsanation.save');

            Route::put(
                'qualifications/{courseId}/notes/subsanation',
                [QualificationsController::class, 'updateSubsanation']
            )->name('qualifications.notes.subsanation.update');

            Route::get(
                'qualifications/{courseId}/notes/subsanation/evidence',
                [QualificationsController::class, 'downloadSubsanationEvidence']
            )->name('qualifications.notes.subsanation.evidence');

            Route::get(
                'qualifications/{courseId}/{evaluationId}',
                [QualificationsController::class, 'evaluate']
            )->name('qualifications.evaluate');

            Route::post(
                'qualifications/{courseId}/{evaluationId}/deliveries/{deliveryId}/review',
                [QualificationsController::class, 'saveReview']
            )->name('qualifications.review.save');

            Route::get(
                'qualifications/{courseId}/{evaluationId}/attachments/{attachmentId}/download',
                [QualificationsController::class, 'downloadAttachment']
            )->name('qualifications.attachments.download');

            /* ===============================
            CERTIFICATES
            =============================== */

            Route::get(
                'certificates',
                [CertificatesController::class, 'index']
            )->name('certificates.index');

            Route::get(
                'certificates/{courseId}',
                [CertificatesController::class, 'show']
            )->name('certificates.show');

            Route::post(
                'certificates/{courseId}/students/{studentEmail}/attach',
                [CertificatesController::class, 'attach']
            )->name('certificates.attach');

              Route::post(
                  'certificates/{courseId}/certificates/{certificateId}/send',
                  [CertificatesController::class, 'send']
              )->name('certificates.send');

              Route::post(
                  'certificates/{courseId}/sync-sga',
                  [CertificatesController::class, 'syncSga']
              )->name('certificates.sync-sga');

              Route::get(
                  'evaluations',
                [CoursesController::class, 'evaluaciones']
            )->name('evaluations.index');

            Route::get(
                'surveys',
                [BackofficeSurveysController::class, 'index']
            )->name('surveys.index');

            Route::get(
                'surveys/{courseId}/results',
                [BackofficeSurveysController::class, 'results']
            )->name('surveys.results');

            Route::get(
                'surveys/{courseId}/results/export',
                [BackofficeSurveysController::class, 'export']
            )->name('surveys.results.export');

            Route::get(
                'evaluations/{courseId}',
                [EvaluationsController::class, 'index']
            )->name('evaluations.show');

            Route::post(
                'evaluations/{courseId}',
                [EvaluationsController::class, 'store']
            )->name('evaluations.store');

            Route::get(
                'evaluations/{courseId}/{evaluationId}/edit',
                [EvaluationsController::class, 'edit']
            )->name('evaluations.edit');

            Route::get(
                'evaluations/{courseId}/{evaluationId}/work/edit',
                [EvaluationsController::class, 'workEdit']
            )->name('evaluations.work.edit');

            Route::get(
                'evaluations/{courseId}/{evaluationId}/view',
                [EvaluationsController::class, 'view']
            )->name('evaluations.view');

            Route::get(
                'evaluations/{courseId}/{evaluationId}/work/view',
                [EvaluationsController::class, 'workView']
            )->name('evaluations.work.view');

            Route::post(
                'evaluations/{courseId}/{evaluationId}/autosave',
                [EvaluationsController::class, 'autosave']
            )->name('evaluations.autosave');

            Route::post(
                'evaluations/{courseId}/{evaluationId}/work',
                [EvaluationsController::class, 'saveWork']
            )->name('evaluations.work.save');

           Route::post(
                'evaluations/{courseId}/{evaluationId}/duplicate',
                [EvaluationsController::class, 'duplicate']
            )->name('evaluations.duplicate');

            Route::post(
                'evaluations/{courseId}/{evaluationId}/publish',
                [EvaluationsController::class, 'publish']
            )->name('evaluations.publish');

            Route::get(
                'evaluations/{courseId}/by-type/{typeId}',
                [EvaluationsController::class, 'byType']
            );

            Route::get(
                'courses/{courseId}/sessions/{sessionId}/evaluations',
                [SessionsController::class, 'evaluations']
            )->name('sessions.evaluations.list');

            Route::post(
                'sessions/{sessionId}/evaluation',
                [SessionsController::class, 'assignEvaluation']
            )->name('sessions.evaluation.assign');

            Route::post(
                'courses/{courseId}/evaluation-plan/template',
                [SessionsController::class, 'applyEvaluationPlanTemplate']
            )->name('courses.evaluation-plan.template');

            Route::delete(
                'sessions/{sessionId}/evaluation/{evaluationId}',
                [SessionsController::class, 'removeEvaluation']
            )->name('sessions.evaluation.remove');

            Route::put(
                'sessions/{sessionId}/evaluation/{evaluationId}',
                [SessionsController::class, 'updateEvaluation']
            )->name('sessions.evaluation.update');



            Route::get(
                'courses/{course}/announcements/{session?}',
                [CoursesController::class, 'courseAnnouncements']
            )->name('courses.announcements.index');

            Route::post(
                'courses/{course}/announcements',
                [CoursesController::class, 'storeAnnouncement']
            )->name('courses.announcements.store');

            Route::put(
                'courses/{course}/announcements/{announcement}',
                [CoursesController::class, 'updateAnnouncement']
            )->name('courses.announcements.update');

            Route::delete(
                'courses/{course}/announcements/{announcement}',
                [CoursesController::class, 'destroyAnnouncement']
            )->name('courses.announcements.destroy');

            Route::get(
                'courses/{course}/sessions/{session}/workspace',
                [CoursesController::class, 'workspace']
            )->name('courses.sessions.workspace');

            Route::get(
                'courses/{course}/sessions/{session}/panels/{panel}',
                [CoursesController::class, 'panel']
            )->name('courses.sessions.panels.show');

            Route::get(
                'courses/{course}/community',
                [CoursesController::class, 'community']
            )->name('courses.community.show');

            Route::get(
                'courses/{course}/{session?}',
                [CoursesController::class, 'show']
            )->name('courses.show');

            Route::post(
                'courses/{course}/sessions/{session}/materials',
                [CoursesController::class, 'storeMaterial']
            )->name('courses.materials.store');

            Route::put(
                'courses/{course}/sessions/{session}/materials/{material}',
                [CoursesController::class, 'updateMaterial']
            )->name('courses.materials.update');

            Route::delete(
                'courses/{course}/sessions/{session}/materials/{material}',
                [CoursesController::class, 'destroyMaterial']
            )->name('courses.materials.destroy');

            Route::get(
                'materials/{material}/preview',
                [CoursesController::class, 'previewMaterial']
            )->name('courses.materials.preview');

            Route::get(
                'materials/{material}/download',
                [CoursesController::class, 'downloadMaterial']
            )->name('courses.materials.download');

            Route::get(
                'courses/{courseId}/sessions/{sessionId}/video/status',
                [SesionVideoController::class, 'status']
            )->name('backoffice.sessions.video.status');

            Route::post(
                'courses/{course}/sessions/{session}/video/start-upload',
                [SesionVideoController::class, 'startUpload']
            );

            Route::post(
                'courses/{course}/sessions/{session}/video/upload-chunk',
                [SesionVideoController::class, 'uploadChunk']
            );

            Route::post(
                'courses/{course}/sessions/{session}/video/finalize-upload',
                [SesionVideoController::class, 'finalizeUpload']
            );

            Route::post(
                'courses/{course}/sessions/{session}/video/cancel-upload',
                [SesionVideoController::class, 'cancelUpload']
            );

            Route::delete(
                'courses/{course}/sessions/{session}/video',
                [SesionVideoController::class, 'deleteVideo']
            );

            Route::post(
                'courses/{course}/sessions/{session}/video/chat',
                [SesionVideoController::class, 'uploadChat']
            );

            Route::delete(
                'courses/{course}/sessions/{session}/video/chat',
                [SesionVideoController::class, 'deleteChat']
            );

            Route::get(
                'sessions/{sessionId}/video/upload-progress',
                [SesionVideoController::class, 'uploadProgress']
            );
        
            
        });

        Route::get('mis-cursos/{course}/asistencia', [StudentAttendanceController::class, 'index'])
            ->name('mis-cursos.attendance');
        /*
        |--------------------------------------------------------------------------
        | MIS CURSOS (ALUMNO)
        |--------------------------------------------------------------------------
        */

        Route::prefix('mis-cursos')
            ->name('mis-cursos.')
            ->group(function () {

             /*
    |--------------------------------------------------------------------------
    | PAGOS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'pagos',
        [PaymentController::class, 'index']
    )->name('payments.index');

 /*
    |--------------------------------------------------------------------------
    | NOTAS
    |--------------------------------------------------------------------------
    */
            Route::get(
                '{course}/notas',
                [EvaluationController::class, 'listarNotasAlumnoPorCurso']
            )->name('notas.index');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}',
                [EvaluationController::class, 'take']
            )->name('evaluaciones.rendir');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/trabajo',
                [EvaluationController::class, 'work']
            )->name('evaluaciones.trabajo.show');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/trabajo/entrega',
                [EvaluationController::class, 'saveWorkSubmission']
            )->name('evaluaciones.trabajo.save');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/trabajo/finalizar',
                [EvaluationController::class, 'finalizeWorkSubmission']
            )->name('evaluaciones.trabajo.finalize');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/trabajo/archivos/{attachment}/descargar',
                [EvaluationController::class, 'downloadWorkAttachment']
            )->name('evaluaciones.trabajo.attachments.download');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/run',
                [EvaluationController::class, 'run']
            )->name('evaluaciones.run');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/evaluate',
                [EvaluationController::class, 'evaluate']
            )->name('evaluaciones.evaluate');

            /*
            |--------------------------------------------------------------------------
            | EVALUATION SUBMISSION (EXAMEN ALUMNO)
            |--------------------------------------------------------------------------
            */

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir',
                [EvaluationSubmissionController::class, 'show']
            )->name('evaluaciones.rendicion.show');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir/iniciar',
                [EvaluationSubmissionController::class, 'start']
            )->name('evaluaciones.rendicion.start');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir/respuesta',
                [EvaluationSubmissionController::class, 'saveAnswer']
            )->name('evaluaciones.rendicion.answer');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir/parcial',
                [EvaluationSubmissionController::class, 'partial']
            )->name('evaluaciones.rendicion.partial');

            Route::post(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir/finalizar',
                [EvaluationSubmissionController::class, 'finalize']
            )->name('evaluaciones.rendicion.finalize');

            Route::get(
                '{course}/sesion/{session}/evaluacion/{evaluation}/rendir/resultado/{submission}',
                [EvaluationSubmissionController::class, 'result']
            )->name('evaluaciones.rendicion.result');


             Route::get(
                'surveys',
                [SurveyController::class, 'index']
            )->name('surveys.index');

             Route::get('{course}/sesiones/{session}/encuestas/{link}', [SurveyController::class, 'show'])
                ->name('encuestas.show');

             Route::get('{course}/encuestas/{type}', [SurveyController::class, 'legacy'])
                ->name('encuestas.legacy');

             Route::post(
                '{course}/sesiones/{session}/encuestas/{link}',
                [SurveyResponseController::class, 'store']
            )->name('survey.store');

            Route::get('/', [CursosController::class, 'index'])
                ->name('index');

            Route::get(
                '{course}/announcements/{session?}',
                [CursosController::class, 'courseAnnouncements']
            )->name('announcements.index');

            Route::get(
                '{course}/sessions/{session}/announcements',
                [CursosController::class, 'sessionAnnouncements']
            )->name('sessions.announcements');

            Route::get(
                '{course}/sessions/{session}/detail',
                [CursosController::class, 'sessionDetail']
            )->name('sessions.detail');

            Route::get(
                '{course}/sessions/{session}/workspace',
                [CursosController::class, 'workspace']
            )->name('sessions.workspace');

            Route::get(
                '{course}/sessions/{session}/panels/{panel}',
                [CursosController::class, 'panel']
            )->where('panel', 'video|materials|evaluations|surveys|announcements|attendance')
                ->name('sessions.panels.show');

            Route::get(
                '{course}/community',
                [CursosController::class, 'community']
            )->name('community.show');

            Route::get(
                '{course}/{session?}',
                [CursosController::class, 'show']
            )->name('show');

            Route::post(
                'announcements/{anuncioId}/leer',
                [CursoAnunciosController::class, 'leer']
            )->name('announcements.leer');

            Route::post(
                'anuncios/{entidadTipo}/{entidadId}/leer-todos',
                [CursoAnunciosController::class, 'leerTodos']
            )->name('announcements.leer-todos');

        });

    });
