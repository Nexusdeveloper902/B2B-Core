{{--
    TASK-029 — the design-system pagination (replaces the stock
    pagination::tailwind markup, whose Tailwind utility classes do not
    exist here — the students desk and the student history ledger were
    rendering completely unstyled page links). The .pagination rules
    already lived in app.css; this view emits the markup they expect.
    Same variable contract as the framework view ($paginator/$elements).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('app.pagination_nav') }}">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="disabled" aria-disabled="true"><span>&lsaquo;</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="{{ __('app.pagination_prev') }}">&lsaquo;</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="disabled" aria-disabled="true"><span>{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="active" aria-current="page"><span>{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}" aria-label="{{ __('app.pagination_page', ['page' => $page]) }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="{{ __('app.pagination_next') }}">&rsaquo;</a></li>
            @else
                <li class="disabled" aria-disabled="true"><span>&rsaquo;</span></li>
            @endif
        </ul>
    </nav>
@endif
