const EvaluationRun = (function () {
    let preguntas = [];
    let current = 0;
    let respuestas = {};
    let passScore = 11;
    let reviewMode = false;
    let finalMode = false;
    let resultData = null;
    let timerId = null;
    let urls = {
        answer: '',
        finalize: '',
    };
    let pendingSavePromises = {};

    function init(config) {
        preguntas = Array.isArray(config?.questions) ? config.questions : [];
        passScore = Number(config?.passScore || 11);
        urls = config?.urls || urls;
        respuestas = mapInitialAnswers(config?.answers || []);

        bindEvents();
        renderSidebar();
        renderLegend();
        syncReviewExit();

        if (preguntas.length === 0) {
            return;
        }

        const initialIndex = resolveInitialIndex();
        renderQuestion(initialIndex);
        updateHeader();
        bindFinish();

        const finalResult = normalizeFinalResult(config?.finalResult);

        if (finalResult) {
            finalMode = true;
            showResult(finalResult);
            return;
        }

        startTimer(
            computeRemainingSeconds(
                config?.submission?.started_at || config?.submission?.fecha_inicio || null,
                Number(config?.minutes || 0),
                config?.submission?.status || config?.submission?.estado || null
            )
        );
    }

    function mapInitialAnswers(initialAnswers) {
        return (Array.isArray(initialAnswers) ? initialAnswers : []).reduce((acc, answer) => {
            const questionId = answer.question_id ?? answer.pregunta_id;
            const optionId = answer.option_id ?? answer.opcion_id ?? null;

            if (questionId !== null && questionId !== undefined && optionId !== null && optionId !== undefined) {
                acc[String(questionId)] = String(optionId);
            }

            return acc;
        }, {});
    }

    function resolveInitialIndex() {
        if (Object.keys(respuestas).length === 0) {
            return 0;
        }

        let lastAnsweredIndex = 0;

        preguntas.forEach((question, index) => {
            if (respuestas[String(question.id)]) {
                lastAnsweredIndex = index;
            }
        });

        return lastAnsweredIndex;
    }

    function bindEvents() {
        document.addEventListener('click', function (e) {
            if (e.target.closest('.js-next')) {
                next();
            }

            if (e.target.closest('.js-prev')) {
                prev();
            }

            if (e.target.matches('.question-number')) {
                const index = parseInt(e.target.dataset.index || '0', 10);
                goTo(index);
            }

            if (e.target.matches('input[name="answer"]')) {
                const questionId = e.target.dataset.question;
                const optionId = e.target.value;
                saveAnswer(questionId, optionId);
            }

            if (e.target.closest('.btn-review')) {
                review();
            }
        });
    }

    function syncReviewExit() {
        const exitRow = document.querySelector('.review-exit-row');

        if (!exitRow) {
            return;
        }

        if (reviewMode) {
            exitRow.classList.remove('hidden');
            exitRow.classList.add('flex');
            return;
        }

        exitRow.classList.add('hidden');
        exitRow.classList.remove('flex');
    }

    function renderQuestion(index) {
        current = index;

        const q = preguntas[index];

        document.querySelector('.question-title').innerText = q.text;
        document.querySelector('.question-points').innerText = q.points + ' pts';

        let html = '';

        q.options.forEach(opt => {
            const checked = respuestas[String(q.id)] == opt.id ? 'checked' : '';
            let extraClass = '';

            if (reviewMode) {
                const selected = respuestas[String(q.id)] == opt.id;

                if (opt.correct && selected) {
                    extraClass = 'review-correct selected';
                } else if (opt.correct) {
                    extraClass = 'review-correct';
                } else if (selected && !opt.correct) {
                    extraClass = 'review-incorrect selected';
                }
            }

            html += `
                <label class="option-item ${extraClass}">
                    <input type="radio"
                        name="answer"
                        ${reviewMode ? 'disabled' : ''}
                        data-question="${q.id}"
                        value="${opt.id}"
                        ${checked}>
                    <span>${opt.text}</span>
                </label>
            `;
        });

        document.querySelector('.question-options').innerHTML = html;
        renderFeedback(q);
        syncReviewExit();
        updateHeader();
        updateButtons();
        updateSidebar();
    }

    function renderFeedback(question) {
        const feedbackContainer = document.querySelector('.question-feedback');

        if (!feedbackContainer) {
            return;
        }

        if (!reviewMode || !question?.feedback) {
            feedbackContainer.innerHTML = '';
            feedbackContainer.classList.add('hidden');
            return;
        }

        feedbackContainer.innerHTML = `
            <div class="rounded-xl border border-[#D7E6FF] bg-[#EEF4FF] px-4 py-3">
                <div class="text-sm font-semibold text-[#0A2540] mb-1">Feedback</div>
                <div class="text-sm text-[#2B2B2B]">${question.feedback}</div>
            </div>
        `;
        feedbackContainer.classList.remove('hidden');
    }

    function renderSidebar() {
        const container = document.querySelector('.question-grid');

        if (!container) {
            return;
        }

        let html = '';

        preguntas.forEach((q, i) => {
            html += `
                <button class="question-number"
                        data-index="${i}">
                    ${i + 1}
                </button>
            `;
        });

        container.innerHTML = html;
    }

    function renderLegend() {
        const container = document.querySelector('.question-legend');

        if (!container) {
            return;
        }

        if (reviewMode) {
            container.innerHTML = `
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded bg-[#22C55E]"></div>
                    <span class="text-[#2B2B2B]">Respuesta correcta</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded bg-[#EF4444]"></div>
                    <span class="text-[#2B2B2B]">Respuesta incorrecta</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 rounded bg-[#F2F2F2] border border-slate-200"></div>
                    <span class="text-[#2B2B2B]">No respondida</span>
                </div>
            `;
            return;
        }

        container.innerHTML = `
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-[#1F6AE1]"></div>
                <span class="text-[#2B2B2B]">Respondida</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-[#F2F2F2]"></div>
                <span class="text-[#2B2B2B]">Sin responder</span>
            </div>
        `;
    }

    function saveAnswer(questionId, optionId) {
        respuestas[String(questionId)] = String(optionId);
        updateSidebar();
        updateHeader();
        persistAnswer(questionId, optionId);
    }

    function persistAnswer(questionId, optionId) {
        if (!urls.answer || finalMode) {
            return Promise.resolve();
        }

        const request = fetch(urls.answer, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                question_id: questionId,
                option_id: optionId,
            }),
        }).catch(error => {
            console.error('No se pudo guardar la respuesta', error);
            return null;
        });

        pendingSavePromises[String(questionId)] = request.finally(() => {
            delete pendingSavePromises[String(questionId)];
        });

        return pendingSavePromises[String(questionId)];
    }

    function next() {
        if (current < preguntas.length - 1) {
            renderQuestion(current + 1);
        }
    }

    function prev() {
        if (current > 0) {
            renderQuestion(current - 1);
        }
    }

    function goTo(index) {
        renderQuestion(index);
    }

    function updateHeader() {
        const answered = Object.keys(respuestas).length;

        const counter = document.querySelector('.exam-progress');

        if (counter) {
            counter.innerText = `Pregunta ${current + 1} de ${preguntas.length}`;
        }

        const answeredEl = document.querySelector('.exam-answered');

        if (answeredEl) {
            answeredEl.innerText = `${answered} respondidas`;
        }

        const bar = document.querySelector('.exam-progress-bar');

        if (bar) {
            const percent = preguntas.length > 0 ? (answered / preguntas.length) * 100 : 0;
            bar.style.width = percent + '%';
        }

        const qProg = document.querySelector('.question-progress');

        if (qProg) {
            qProg.innerText = `Pregunta ${current + 1} de ${preguntas.length}`;
        }
    }

    function updateButtons() {
        const prevBtn = document.querySelector('.js-prev');
        const nextBtn = document.querySelector('.js-next');
        const finishBtn = document.querySelector('.btn-finish');

        if (prevBtn) {
            if (current === 0) {
                prevBtn.classList.add('opacity-30', 'cursor-not-allowed');
                prevBtn.disabled = true;
            } else {
                prevBtn.classList.remove('opacity-30', 'cursor-not-allowed');
                prevBtn.disabled = false;
            }
        }

        if (nextBtn) {
            if (current === preguntas.length - 1) {
                nextBtn.classList.add('opacity-30', 'cursor-not-allowed');
                nextBtn.disabled = true;
            } else {
                nextBtn.classList.remove('opacity-30', 'cursor-not-allowed');
                nextBtn.disabled = false;
            }
        }

        if (finishBtn && reviewMode) {
            finishBtn.classList.add('hidden');
        }
    }

    function updateSidebar() {
        document.querySelectorAll('.question-number').forEach((btn, index) => {
            btn.classList.remove('active', 'answered', 'correct', 'incorrect');

            if (index === current) {
                btn.classList.add('active');
            }

            const q = preguntas[index];

            if (reviewMode) {
                if (respuestas[String(q.id)]) {
                    const selected = respuestas[String(q.id)];
                    const correct = q.options.find(o => o.correct);

                    if (correct && selected == correct.id) {
                        btn.classList.add('correct');
                    } else {
                        btn.classList.add('incorrect');
                    }
                }
            } else if (respuestas[String(q.id)]) {
                btn.classList.add('answered');
            }
        });
    }

    function bindFinish() {
        const btn = document.querySelector('.btn-finish');
        const modal = document.getElementById('finishModal');

        if (!btn || !modal || finalMode) {
            return;
        }

        btn.addEventListener('click', () => {
            const total = preguntas.length;
            const answered = Object.keys(respuestas).length;
            const pending = total - answered;

            modal.querySelector('.js-unanswered-text').innerText =
                `Tienes ${pending} preguntas sin responder. ¿Deseas finalizar la evaluación?`;

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });

        modal.querySelectorAll('.js-close-modal').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });
        });

        modal.querySelector('.js-confirm-finish').addEventListener('click', submitEvaluation);
    }

    function normalizeFinalResult(payload) {
        if (!payload || !payload.submission) {
            return null;
        }

        const submission = payload.submission;

        const correct = Number(submission.correct_count ?? submission.correctas ?? 0);
        const incorrect = Number(submission.incorrect_count ?? submission.incorrectas ?? 0);
        const total = preguntas.length;
        const unanswered = Math.max(0, total - correct - incorrect);

        return {
            points: Number(submission.score ?? submission.puntaje_total ?? 0),
            correct,
            incorrect,
            unanswered,
            approved: Boolean(submission.approved ?? submission.aprobado ?? false),
        };
    }

    function showResult(data) {
        resultData = data;

        document.querySelector('.exam-run-wrapper')?.classList.add('hidden');
        document.getElementById('evaluation-result').classList.remove('hidden');

        document.querySelector('.result-percent').innerText =
            data.points || 0;

        document.querySelector('.result-unanswered').innerText =
            data.unanswered || 0;

        document.querySelector('.result-correct').innerText =
            data.correct || 0;

        document.querySelector('.result-incorrect').innerText =
            data.incorrect || 0;

       document.querySelector('.result-pass').innerText =
        `Necesitas al menos ${passScore} puntos para aprobar.`;

        document.querySelector('.result-message').innerText =
            data.approved
                ? '¡Aprobaste la evaluación!'
                : 'Necesitas revisar algunos conceptos';
    }

    async function submitEvaluation() {
        if (!urls.finalize || finalMode) {
            return;
        }

        const pendingPromises = Object.values(pendingSavePromises);

        if (pendingPromises.length > 0) {
            await Promise.allSettled(pendingPromises);
        }

        document.getElementById('gradingOverlay').classList.remove('hidden');
        document.getElementById('gradingOverlay').classList.add('flex');

        try {
            const response = await fetch(urls.finalize, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'No se pudo finalizar la evaluación');
            }

            finalMode = true;

            const modal = document.getElementById('finishModal');

            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            if (timerId) {
                clearInterval(timerId);
                timerId = null;
            }

            showResult(normalizeFinalResult(data));
        } catch (error) {
            console.error(error);
            window.alert(error.message || 'No se pudo finalizar la evaluación');
        } finally {
            document.getElementById('gradingOverlay').classList.add('hidden');
            document.getElementById('gradingOverlay').classList.remove('flex');
        }
    }

    function review() {
        reviewMode = true;

        document.getElementById('evaluation-result').classList.add('hidden');
        document.querySelector('.exam-run-wrapper').classList.remove('hidden');

        renderLegend();
        renderQuestion(0);
        updateSidebar();
        syncReviewExit();

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function startTimer(seconds) {
        let total = Math.max(0, Number(seconds || 0));
        const el = document.querySelector('.time-remaining');

        updateTimerText(total, el);

        if (total <= 0) {
            submitEvaluation();
            return;
        }

        timerId = setInterval(() => {
            total--;
            updateTimerText(total, el);

            if (total <= 0) {
                clearInterval(timerId);
                timerId = null;
                submitEvaluation();
            }
        }, 1000);
    }

    function updateTimerText(total, el) {
        if (!el) {
            return;
        }

        const m = Math.floor(total / 60);
        const s = total % 60;

        el.innerText =
            `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    function computeRemainingSeconds(startedAt, totalMinutes, status) {
        if (status === 'finalizado') {
            return 0;
        }

        const totalSeconds = Math.max(0, Number(totalMinutes || 0) * 60);

        if (!startedAt) {
            return totalSeconds;
        }

        const startedDate = new Date(String(startedAt).replace(' ', 'T'));

        if (Number.isNaN(startedDate.getTime())) {
            return totalSeconds;
        }

        const elapsed = Math.floor((Date.now() - startedDate.getTime()) / 1000);
        const remaining = totalSeconds - elapsed;

        if (elapsed < 0) {
            return totalSeconds;
        }

        return Math.min(totalSeconds, Math.max(0, remaining));
    }

    return {
        init,
    };
})();

function hydrateEvaluationRunConfig() {
    const context = document.getElementById('evaluationRunContext');
    const payload = document.getElementById('evaluationRunPayload');

    if (!context || !payload) {
        return null;
    }

    let data = {};

    try {
        data = JSON.parse(payload.innerHTML || '{}');
    } catch (error) {
        console.error('No se pudo leer la configuracion del examen.', error);
        return null;
    }

    return {
        questions: data.questions || [],
        minutes: Number(context.dataset.minutes || 30),
        passScore: Number(context.dataset.passScore || 11),
        submission: data.submission || null,
        answers: data.answers || [],
        finalResult: data.finalResult || null,
        urls: {
            answer: context.dataset.answerUrl || '',
            finalize: context.dataset.finalizeUrl || '',
        },
    };
}

document.addEventListener('DOMContentLoaded', function () {
    const config = hydrateEvaluationRunConfig();

    if (!config) {
        return;
    }

    EvaluationRun.init(config);
});

window.EvaluationRun = EvaluationRun;
