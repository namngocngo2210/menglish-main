{{--
    <x-ui.filter-bar> — thanh bộ lọc CHUNG (form GET) trên MỘT hàng: ô tìm kiếm (giãn) + các control trong slot
    + nhóm nút Lọc / Xoá lọc ở cuối hàng. Slot `quick`: hàng lọc nhanh ở đầu khung (vd. <x-ui.workspace-chips />).
    - Control trong slot vẫn truyền `label` (cho trình đọc màn hình; nhãn ẩn trên thanh lọc) và placeholder "Tất cả …";
      khoảng ngày dùng <x-ui.date-range> (nhãn hiện ngay trước 2 ô ngày). Dropdown tự thành ô chọn có tìm kiếm (Tom Select).
    - Nhóm control ẩn/hiện: bọc <div class="contents">.
    - Điện thoại (< md): chỉ hiện ô tìm kiếm + nút "Bộ lọc (n)"; các control trong slot mở ra khi bấm nút đó.
    Props: action (mặc định URL hiện tại), search (tên tham số tìm kiếm; false = không có ô tìm — không dùng null vì Blade coi null là "không truyền"), placeholder,
           resetUrl, submitLabel (mặc định "Lọc")
    Ví dụ:
      <x-ui.filter-bar placeholder="Tìm họ tên, SĐT...">
          <x-ui.select name="branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
          <x-ui.date-range label="Ngày tạo" />
      </x-ui.filter-bar>
--}}
@props(['action' => null, 'search' => 'search', 'placeholder' => 'Tìm kiếm...', 'resetUrl' => null, 'submitLabel' => 'Lọc'])

@php
    $action ??= url()->current();
    $resetUrl ??= url()->current();
    $active = collect(request()->except(['page', 'per_page', 'tab']))->filter(fn ($v) => filled($v));
    $hasQuery = $active->isNotEmpty();
    // Số bộ lọc đang áp dụng ngoài ô tìm kiếm — hiện trên nút "Bộ lọc (n)" ở điện thoại.
    $activeFilters = $active->except($search ? [$search] : [])->count();
    $hasControls = trim($slot) !== '';
    $filtersId = 'filters-'.\Illuminate\Support\Str::random(6);
@endphp

<form method="GET" action="{{ $action }}" role="search" x-data="{ more: false }" data-filter-bar
      {{ $attributes->merge(['class' => 'mb-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-sm shadow-sm md:p-md']) }}>
    @isset($quick)
        {{-- Lọc nhanh (vd. <x-ui.workspace-chips />) nằm trong cùng khung bộ lọc --}}
        @if (trim($quick) !== '')
            <div class="mb-md border-b border-surface-container-highest pb-md">{{ $quick }}</div>
        @endif
    @endisset
    {{-- ≥ md: một hàng (ô tìm giãn, mỗi bộ lọc rộng vừa đủ, nút ở cuối); nhãn ẩn (sr-only) vì lựa chọn đầu "Tất cả …" đã nói rõ.
         Bố cục ở app.css ([data-filter-row]). --}}
    <div class="flex flex-col gap-sm md:flex-row md:items-center" data-filter-row>
        @if ($search)
            <div class="relative" data-filter-search>
                <label for="{{ $filtersId }}-search" class="sr-only">{{ $placeholder }}</label>
                <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                <input type="search" id="{{ $filtersId }}-search" name="{{ $search }}" value="{{ request($search) }}" placeholder="{{ $placeholder }}"
                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50">
            </div>
        @endif

        @if ($hasControls)
            {{-- ≥ md: display: contents (các control là ô của hàng); điện thoại: ẩn tới khi bấm "Bộ lọc". --}}
            <div id="{{ $filtersId }}" class="hidden flex-col gap-sm md:contents" x-bind:class="more && '!flex'" data-filter-controls>
                {{ $slot }}
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-sm md:ml-auto" data-filter-actions>
            @if ($hasControls)
                <x-ui.button type="button" variant="secondary" icon="tune" class="mr-auto md:hidden"
                             x-on:click="more = ! more" x-bind:aria-expanded="more" aria-controls="{{ $filtersId }}" aria-expanded="false">
                    Bộ lọc{{ $activeFilters ? ' ('.$activeFilters.')' : '' }}
                </x-ui.button>
            @endif
            @if ($hasQuery)
                <x-ui.button variant="ghost" :href="$resetUrl" icon="filter_alt_off">Xoá lọc</x-ui.button>
            @endif
            <x-ui.button type="submit" variant="secondary" icon="filter_list">{{ $submitLabel }}</x-ui.button>
        </div>
    </div>
</form>
