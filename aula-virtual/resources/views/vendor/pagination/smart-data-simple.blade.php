@if ($paginator->hasPages())
    <nav class="smart-pagination smart-pagination--simple" role="navigation" aria-label="Paginación de resultados">
        <div class="smart-pagination__simple-row">
            @if ($paginator->onFirstPage())
                <span class="smart-pagination__direction is-disabled" aria-disabled="true">
                    <span aria-hidden="true">&lsaquo;</span>
                    Anterior
                </span>
            @else
                <a class="smart-pagination__direction" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                    <span aria-hidden="true">&lsaquo;</span>
                    Anterior
                </a>
            @endif

            <span class="smart-pagination__mobile-status" aria-live="polite">
                Página {{ $paginator->currentPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a class="smart-pagination__direction" href="{{ $paginator->nextPageUrl() }}" rel="next">
                    Siguiente
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
            @else
                <span class="smart-pagination__direction is-disabled" aria-disabled="true">
                    Siguiente
                    <span aria-hidden="true">&rsaquo;</span>
                </span>
            @endif
        </div>
    </nav>
@endif
