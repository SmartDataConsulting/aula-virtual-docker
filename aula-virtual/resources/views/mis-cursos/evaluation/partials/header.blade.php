<div class="bg-white border-b border-[rgba(10,37,64,0.1)] w-full">
    <div class="max-w-5xl mx-auto px-6 py-4">
        <div class="hidden justify-end mb-3 review-exit-row">
            <a href="{{ route('mis-cursos.show', [$courseId, $sessionId]) }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-[rgba(10,37,64,0.12)] text-sm font-medium text-[#0A2540] hover:bg-[#F8FAFC] transition-colors">
                Salir de la evaluación
            </a>
        </div>

        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-[#0A2540] exam-progress"></span>
            <span class="text-sm text-[#2B2B2B] exam-answered"></span>
        </div>

        <div class="relative h-1.5 bg-[#F2F2F2] rounded-full overflow-hidden">
            <div class="absolute top-0 left-0 h-full bg-[#1F6AE1] exam-progress-bar" style="width:0%"></div>
        </div>

    </div>
</div>
