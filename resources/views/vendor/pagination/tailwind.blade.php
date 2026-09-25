{{-- Link phân trang mặc định (->links()) — restyle theo mockup khach-khong-chot-lost-deals. --}}
@if ($paginator->hasPages())
    @php
        $base = 'inline-flex h-8 min-w-8 items-center justify-center rounded px-xs font-body-medium text-body-medium transition-colors';
        $idle = $base . ' text-on-surface hover:bg-surface-container-high';
        $disabled = $base . ' cursor-default text-on-surface-variant opacity-30';
    @endphp
    <nav role="navigation" aria-label="Phân trang" class="flex items-center gap-xs">
        @if ($paginator->onFirstPage())
            <span class="{{ $disabled }}" aria-disabled="true" aria-label="Trang trước"><span class="material-symbols-outlined">chevron_left</span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $idle }}" aria-label="Trang trước"><span class="material-symbols-outlined">chevron_left</span></a>
        @endif

        <span class="px-sm font-body-small text-body-small text-on-surface-variant sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

        <span class="hidden items-center gap-xs sm:flex">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-sm text-on-surface-variant" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="{{ $base }} bg-primary-container text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="{{ $idle }}" aria-label="Trang {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $idle }}" aria-label="Trang sau"><span class="material-symbols-outlined">chevron_right</span></a>
        @else
            <span class="{{ $disabled }}" aria-disabled="true" aria-label="Trang sau"><span class="material-symbols-outlined">chevron_right</span></span>
        @endif
    </nav>
@endif
