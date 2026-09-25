{{--
    <x-ui.empty-state> — trạng thái rỗng (không có dữ liệu / không có kết quả lọc).
    Props: icon (mặc định "inbox"), title (bắt buộc), description (tuỳ chọn)
    Slot:  nút hành động (tuỳ chọn)
    Ví dụ:
      <x-ui.empty-state icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khoá hoặc xoá bộ lọc.">
          <x-ui.button variant="secondary" :href="route('crm.customers.index')">Xoá bộ lọc</x-ui.button>
      </x-ui.empty-state>
--}}
@props(['icon' => 'inbox', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-sm px-md py-xl text-center']) }}>
    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-low text-on-surface-variant/60">
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
