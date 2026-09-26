{{--
    <x-ui.filter-bar> — thanh bộ lọc CHUNG (form GET) dạng lưới đều cột: ô tìm kiếm (rộng 2 cột) + các control trong slot
    + nhóm nút Lọc / Xoá lọc luôn căn ở cột cuối bên phải.
    - Control trong slot nên có `label` (nhãn nằm trên, các ô thẳng hàng đáy); khoảng ngày dùng <x-ui.date-range> (rộng 2 cột).
    - Phần tử muốn chiếm 2 cột: class="sm:col-span-2"; nhóm control ẩn/hiện: bọc <div class="contents">.
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
    $hasQuery = collect(request()->except(['page', 'per_page', 'tab']))->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<form method="GET" action="{{ $action }}" role="search"
      {{ $attributes->merge(['class' => 'mb-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm']) }}>
    <div class="grid grid-cols-1 items-end gap-md sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6" data-filter-grid>
        @if ($search)
            <x-ui.field label="Tìm kiếm" class="sm:col-span-2">
                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                    <input type="search" name="{{ $search }}" value="{{ request($search) }}" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
                           class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-variant/60 focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                </div>
            </x-ui.field>
        @endif

        {{ $slot }}

        {{-- Luôn nằm ở cột cuối (hàng kế tiếp nếu hàng hiện tại đã đầy) --}}
        <div class="flex items-center justify-end gap-sm sm:col-end-[-1]">
            @if ($hasQuery)
                <x-ui.button variant="ghost" :href="$resetUrl" icon="filter_alt_off">Xoá lọc</x-ui.button>
            @endif
            <x-ui.button type="submit" variant="secondary" icon="filter_list">{{ $submitLabel }}</x-ui.button>
        </div>
    </div>
</form>
