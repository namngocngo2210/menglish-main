{{-- Link phân trang đơn giản (simplePaginate) — restyle theo mockup. --}}
@if ($paginator->hasPages())
    @php
        $base = 'inline-flex h-8 items-center justify-center gap-xs rounded px-sm font-body-medium text-body-medium transition-colors';
    @endphp
    <nav role="navigation" aria-label="Phân trang" class="flex items-center gap-xs">
        @if ($paginator->onFirstPage())
            <span class="{{ $base }} cursor-default text-on-surface-variant opacity-30" aria-disabled="true"><span class="material-symbols-outlined">chevron_left</span>Trước</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $base }} text-on-surface hover:bg-surface-container-high"><span class="material-symbols-outlined">chevron_left</span>Trước</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $base }} text-on-surface hover:bg-surface-container-high">Sau<span class="material-symbols-outlined">chevron_right</span></a>
        @else
            <span class="{{ $base }} cursor-default text-on-surface-variant opacity-30" aria-disabled="true">Sau<span class="material-symbols-outlined">chevron_right</span></span>
        @endif
    </nav>
@endif
