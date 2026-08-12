<div class="flex-1 overflow-y-auto bg-white">

    <div class="max-w-4xl mx-auto p-8">

        {{-- Tiempo --}}
        <div class="mb-6">
            <div class="inline-flex items-center gap-2 text-sm">
                <span class="text-[#2B2B2B]">Tiempo restante:</span>
                <span class="text-[#0A2540] time-remaining"></span>
            </div>
        </div>

        <div class="w-full max-w-3xl mx-auto">

            {{-- HEADER PREGUNTA --}}
            <div class="mb-6">
                <div class="flex items-start justify-between mb-4">

                    <div class="flex-1">
                        <div class="text-sm text-[#2B2B2B] mb-2 question-progress"></div>
                        <h2 class="text-xl text-[#0A2540] question-title"></h2>
                    </div>

                    <div class="ml-4 text-sm text-[#2B2B2B] question-points"></div>

                </div>
            </div>

            <div class="space-y-2 question-options"></div>
            <div class="mt-4 hidden question-feedback"></div>

        </div>

        {{-- BOTONES --}}
        <div class="mt-8 flex justify-between items-center">

            <button
                type="button"
                class="js-prev
                flex items-center gap-1
                px-3 py-2
                rounded-lg
                text-sm
                text-[#2B2B2B]
                hover:bg-[#F2F2F2]
                transition-colors">

                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 18-6-6 6-6"/>
                </svg>

                <span>Anterior</span>

                </button>

            <div class="flex gap-3">

                <button class="btn-finish flex items-center gap-2 px-5 py-2 bg-[#1F6AE1] text-white rounded-lg">
                    Finalizar Evaluación
                </button>

                <button
                type="button"
                class="js-next flex items-center gap-1 px-3 py-2 rounded-lg text-sm
                text-[#1F6AE1] hover:bg-[#F2F2F2] transition-colors">

                <span>Siguiente</span>

                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"></path>
                </svg>

                </button>

            </div>

        </div>

    </div>

</div>
