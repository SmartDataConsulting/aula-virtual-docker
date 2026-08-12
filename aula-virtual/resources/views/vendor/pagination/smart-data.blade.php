@if ($paginator->hasPages())
    <nav class="smart-pagination" role="navigation" aria-label="Paginación de resultados">
        <div class="smart-pagination__mobile">
            @if ($paginator->onFirstPage())
                <span class="smart-pagination__mobile-action is-disabled" aria-disabled="true">
                    <span aria-hidden="true">&lsaquo;</span>
                    Anterior
                </span>
            @else
                <a class="smart-pagination__mobile-action" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                    <span aria-hidden="true">&lsaquo;</span>
                    Anterior
                </a>
            @endif

            <span class="smart-pagination__mobile-status" aria-live="polite">
                Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a class="smart-pagination__mobile-action" href="{{ $paginator->nextPageUrl() }}" rel="next">
                    Siguiente
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
            @else
                <span class="smart-pagination__mobile-action is-disabled" aria-disabled="true">
                    Siguiente
                    <span aria-hidden="true">&rsaquo;</span>
                </span>
            @endif
        </div>

        <div class="smart-pagination__desktop">
            <p class="smart-pagination__summary" aria-live="polite">
                Mostrando
                <strong>{{ $paginator->firstItem() ?? 0 }}&ndash;{{ $paginator->lastItem() ?? 0 }}</strong>
                de <strong>{{ $paginator->total() }}</strong>
            </p>

            <div class="smart-pagination__controls">
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

                <div class="smart-pagination__pages" aria-label="Páginas disponibles">
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="smart-pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page === $paginator->currentPage())
                                    <span class="smart-pagination__page is-active" aria-current="page" aria-label="Página {{ $page }}">
                                        {{ $page }}
                                    </span>
                                @else
                                    <a class="smart-pagination__page" href="{{ $url }}" aria-label="Ir a la página {{ $page }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                </div>

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
        </div>
    </nav>
@endif
