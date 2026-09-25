{{--
    <x-ui.pagination> — thanh phân trang theo mockup: "Hiển thị x - y trong tổng số N" + chọn số dòng + link trang.
    Props:
      paginator: LengthAwarePaginator (bắt buộc; Paginator đơn giản sẽ chỉ hiện link trang)
      options:   các lựa chọn số dòng/trang (mặc định [10, 20, 50, 100, 'all']; truyền [] để ẩn)
      unit:      danh từ đơn vị (mặc định "bản ghi")
    Ví dụ (trong footer của <x-ui.data-table>): <x-ui.pagination :paginator="$customers" unit="khách" />
    Link trang dùng view mặc định vendor/pagination/tailwind.blade.php (đã restyle).
--}}
@props(['paginator', 'options' => [10, 20, 50, 100, 'all'], 'unit' => 'bản ghi'])

@if ($paginator instanceof \Illuminate\Contracts\Pagination\Paginator)
    @php
        $isLengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
        $currentPerPage = (string) request('per_page', $paginator->perPage());
    @endphp
    <div {{ $attributes->merge(['class' => 'flex flex-col gap-sm px-md py-md md:flex-row md:items-center md:justify-between']) }}>
        <div class="flex flex-wrap items-center gap-sm font-body-small text-body-small text-on-surface-variant">
            @if (! empty($options))
                <label class="flex items-center gap-xs">
                    <span>Hiển thị:</span>
                    <select class="rounded-lg border border-outline-variant bg-surface-container-lowest py-1 pl-sm pr-lg font-body-small text-body-small text-on-surface focus:border-primary-container focus:ring-primary-container/20"
                            aria-label="Số dòng mỗi trang"
                            onchange="const u = new URL(window.location.href); u.searchParams.set('per_page', this.value); u.searchParams.set('page', '1'); window.location.href = u.toString();">
                        @foreach ($options as $opt)
                            @php $isSelected = $currentPerPage === (string) $opt || ($opt === 'all' && ($currentPerPage === 'all' || (int) $currentPerPage >= 9999)); @endphp
                            <option value="{{ $opt }}" @selected($isSelected)>{{ $opt === 'all' ? 'Tất cả' : $opt }}</option>
                        @endforeach
                    </select>
                    <span>dòng</span>
                </label>
            @endif
            @if ($isLengthAware)
                <span>
                    <span class="font-code text-on-surface">{{ $paginator->firstItem() ?? 0 }}</span>
                    - <span class="font-code text-on-surface">{{ $paginator->lastItem() ?? 0 }}</span>
                    trong tổng số <span class="font-code text-on-surface">{{ number_format($paginator->total(), 0, ',', '.') }}</span> {{ $unit }}
                </span>
            @endif
        </div>
        <div>{{ $paginator->appends(request()->query())->links() }}</div>
    </div>
@endif
