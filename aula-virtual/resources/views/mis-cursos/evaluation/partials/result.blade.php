<div id="evaluation-result" class="hidden min-h-screen bg-white flex items-center justify-center p-6">
    <div class="w-full max-w-2xl mx-auto">

        <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4 bg-[#2B2B2B]">
        <svg xmlns="http://www.w3.org/2000/svg"
             width="24"
             height="24"
             viewBox="0 0 24 24"
             fill="none"
             stroke="currentColor"
             stroke-width="2"
             stroke-linecap="round"
             stroke-linejoin="round"
             class="w-8 h-8 text-white">

            <path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"></path>
            <circle cx="12" cy="8" r="6"></circle>

        </svg>
    </div>

            <h1 class="text-2xl mb-2 text-[#0A2540]">
                Evaluación Completada
            </h1>

            <p class="text-[#2B2B2B] result-message">
                —
            </p>
        </div>

        <div class="bg-white border border-[rgba(10,37,64,0.1)] rounded-lg overflow-hidden mb-6">

            <div class="p-8 text-center border-b border-[rgba(10,37,64,0.1)]">
                <div class="text-4xl text-[#0A2540] mb-1 result-percent">
                    0.0%
                </div>
                <div class="text-sm text-[#2B2B2B]">
                    Calificación Final
                </div>
            </div>

            <div class="grid grid-cols-3 divide-x divide-[rgba(10,37,64,0.1)]">

                
                <div class="p-6 text-center">
                    <div class="text-2xl text-[#22c55e] mb-1 result-correct">0</div>
                    <div class="text-sm text-[#2B2B2B]">Correctas</div>
                </div>

                <div class="p-6 text-center">
                    <div class="text-2xl text-[#ef4444] mb-1 result-incorrect">0</div>
                    <div class="text-sm text-[#2B2B2B]">Incorrectas</div>
                </div>
                <div class="p-6 text-center">
                    <div class="text-2xl text-[#0A2540] mb-1 result-unanswered">0</div>
                    <div class="text-sm text-[#2B2B2B]">Sin responder</div>
                </div>


            </div>

        </div>

        <div class="p-4 rounded-lg mb-6 bg-[#F2F2F2]">
            <p class="text-sm text-[#0A2540] text-center result-pass">
                —
            </p>
        </div>

        <div class="flex gap-3 justify-center">
            <button class="btn-review px-6 py-2.5 bg-[#1F6AE1] text-white rounded-lg">
                Ver Respuestas
            </button>

            <a href="{{ route('mis-cursos.show', [$courseId, $sessionId]) }}"
               class="inline-flex items-center justify-center px-6 py-2.5 bg-white text-[#0A2540] border border-[rgba(10,37,64,0.14)] rounded-lg hover:bg-[#F8FAFC] transition-colors">
                Volver a la sesión
            </a>

            <button class="btn-retry hidden px-6 py-2.5 bg-white text-[#1F6AE1] border border-[#1F6AE1] rounded-lg">
                Reintentar
            </button>
        </div>

    </div>
</div>
