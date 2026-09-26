{{--
    <x-ui.filter-bar> — thanh lọc dữ liệu (form GET) theo mockup pipeline-tong-quan-giai-doan.
    Props:
      action:      URL submit (mặc định URL hiện tại)
      search:      tên input tìm kiếm (mặc định "search"; truyền null để ẩn ô tìm kiếm)
      placeholder: placeholder ô tìm kiếm
      resetUrl:    URL "Xoá lọc" (mặc định URL hiện tại không query) — chỉ hiện khi đang có query
    Slot: các bộ lọc bổ sung (dùng <x-ui.select> / <x-ui.date> không label hoặc có label nhỏ)
    Ví dụ:
      <x-ui.filter-bar placeholder="Tìm họ tên, số điện thoại...">
          <x-ui.select name="source" :options="$sources" placeholder="Tất cả nguồn" inline-label="Nguồn:" />
          <x-ui.date name="from" inline-label="Từ ngày:" />
      </x-ui.filter-bar>
--}}
@props(['action' => null, 'search' => 'search', 'placeholder' => 'Tìm kiếm...', 'resetUrl' => null])

@php
    $action ??= url()->current();
    $resetUrl ??= url()->current();
    $hasQuery = collect(request()->except(['page', 'per_page']))->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<form method="GET" action="{{ $action }}" role="search"
      {{ $attributes->merge(['class' => 'mb-lg flex flex-wrap items-center gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm [&>select]:w-auto [&>input]:w-auto [&>label_select]:w-auto']) }}>
    @if ($search)
        <div class="relative min-w-[240px] flex-1">
            <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
            <input type="search" name="{{ $search }}" value="{{ request($search) }}" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-variant/60 focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
        </div>
    @endif

    {{ $slot }}

    <div class="ml-auto flex items-center gap-sm">
        @if ($hasQuery)
            <x-ui.button variant="ghost" :href="$resetUrl" icon="filter_alt_off">Xoá lọc</x-ui.button>
        @endif
        <x-ui.button type="submit" variant="secondary" icon="filter_list">Lọc</x-ui.button>
    </div>
</form>
