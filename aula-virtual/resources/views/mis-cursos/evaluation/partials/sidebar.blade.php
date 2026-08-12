<div class="w-56 bg-white border-r border-[rgba(10,37,64,0.1)] h-full">

    <div class="p-4 border-b border-[rgba(10,37,64,0.1)]">
        <h3 class="text-sm text-[#2B2B2B]">Navegación</h3>
    </div>

    <div class="p-4">

        <div class="grid grid-cols-4 gap-2 question-grid">
            @foreach($preguntas as $index => $pregunta)
                <button
                    data-index="{{ $index }}"
                    class="question-number
                    relative aspect-square rounded flex items-center justify-center
                    transition-all text-sm
                    bg-[#F2F2F2] text-[#0A2540]">
                    {{ $index + 1 }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 space-y-2 text-xs question-legend">
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-[#1F6AE1]"></div>
                <span class="text-[#2B2B2B]">Respondida</span>
            </div>

            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-[#F2F2F2]"></div>
                <span class="text-[#2B2B2B]">Sin responder</span>
            </div>
        </div>

    </div>

</div>
