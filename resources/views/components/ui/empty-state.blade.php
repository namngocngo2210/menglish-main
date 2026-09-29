{{--
    <x-ui.empty-state> — trạng thái rỗng (không có dữ liệu / không có kết quả lọc).
    Props: icon (mặc định "inbox"), title (bắt buộc), description (tuỳ chọn),
           compact (một dòng ngang, dùng khi khối rỗng không nên chiếm chỗ — vd. "Không có ca dạy hôm nay")
    Slot:  nút hành động (tuỳ chọn)
    Ví dụ:
      <x-ui.empty-state icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khoá hoặc xoá bộ lọc.">
          <x-ui.button variant="secondary" :href="route('crm.customers.index')">Xoá bộ lọc</x-ui.button>
      </x-ui.empty-state>
--}}
@props(['icon' => 'inbox', 'title', 'description' => null, 'compact' => false])

@if ($compact)
<div {{ $attributes->merge(['class' => 'flex items-start gap-sm rounded-lg bg-surface-container-low px-md py-sm']) }}>
    <span class="material-symbols-outlined mt-0.5 text-[20px] text-on-surface-subtle" aria-hidden="true">{{ $icon }}</span>
    <div class="min-w-0">
        <p class="font-body-semibold text-body-semibold text-on-surface">{{ $title }}</p>
        @if ($description)
            <p class="font-body-small text-body-small text-on-surface-variant">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="ml-auto flex shrink-0 items-center gap-sm">{{ $slot }}</div>
    @endif
</div>
@else
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-sm px-md py-xl text-center']) }}>
    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-low text-on-surface-subtle">
        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">{{ $icon }}</span>
    </div>
    <p class="font-h3 text-h3 text-on-surface">{{ $title }}</p>
    @if ($description)
        <p class="max-w-md font-body-base text-body-base text-on-surface-variant">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-sm flex flex-wrap items-center justify-center gap-sm">{{ $slot }}</div>
    @endif
</div>
@endif
