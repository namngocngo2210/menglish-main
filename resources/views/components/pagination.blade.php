@props(['paginator', 'options' => [10, 20, 50, 100, 'all']])

@if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 p-4 border-t border-gray-100 bg-white">
        <!-- Records per page selector -->
        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 shrink-0">
            <span class="font-medium text-gray-600">Hiển thị:</span>
            
            <!-- Quick Click Button Pills (10, 20, 50, 100, All) -->
            <div class="inline-flex items-center p-0.5 bg-gray-100 rounded-lg border border-gray-200/80 gap-0.5">
                @foreach ($options as $opt)
                    @php
                        $currentVal = request('per_page', 15);
                        $isSelected = ($currentVal === (string)$opt)
                            || ($opt === 'all' && ($currentVal === 'all' || (int)$currentVal >= 9999));
                    @endphp
                    <button
                        type="button"
                        onclick="
                            const url = new URL(window.location.href);
                            url.searchParams.set('per_page', '{{ $opt }}');
                            url.searchParams.set('page', '1');
                            window.location.href = url.toString();
                        "
                        class="px-2.5 py-1 text-xs font-semibold rounded-md transition {{ $isSelected ? 'bg-primary-container text-white shadow-xs' : 'text-gray-600 hover:text-gray-900 hover:bg-white/80' }}"
                    >
                        {{ $opt === 'all' ? 'Tất cả' : $opt }}
                    </button>
                @endforeach
            </div>

            <span class="text-gray-400">|</span>
            <span>Tổng <strong class="text-gray-900 font-bold">{{ number_format($paginator->total()) }}</strong> bản ghi</span>
        </div>

        <!-- Pagination links -->
        <div class="flex-1 flex justify-end">
            {{ $paginator->appends(request()->query())->links() }}
        </div>
    </div>
@endif
