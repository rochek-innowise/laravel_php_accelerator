@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex gap-2 items-center justify-between">

        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center px-4 py-2 font-mono text-sm font-medium text-ink-soft bg-paper border border-dashed border-line cursor-not-allowed rounded-(--radius)">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-4 py-2 font-mono text-sm font-medium text-ink bg-paper border border-line rounded-(--radius) transition-colors duration-[120ms] hover:border-court hover:text-court">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-4 py-2 font-mono text-sm font-medium text-ink bg-paper border border-line rounded-(--radius) transition-colors duration-[120ms] hover:border-court hover:text-court">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center px-4 py-2 font-mono text-sm font-medium text-ink-soft bg-paper border border-dashed border-line cursor-not-allowed rounded-(--radius)">
                {!! __('pagination.next') !!}
            </span>
        @endif

    </nav>
@endif
