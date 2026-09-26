{{--
    Modal xem nhanh khách (3xl) — mở từ Kanban / danh sách với hx-push-url (URL = trang chi tiết, copy link vẫn mở trang đầy đủ).
    Chỉ 3 khối: thông tin chính, chăm sóc gần nhất, nút hành động. Toàn bộ hồ sơ (test, học thử, nhật ký…) ở trang đầy đủ.
    Biến: $customer (branch, assignedUser), $recentHistories (user), $canEdit, $canClose.
--}}
@php
    $followUp = $customer->followUpStatus();
    $fields = [
        ['call', 'Số điện thoại', $customer->phone],
        ['family_restroom', 'Phụ huynh', trim(($customer->parent_name ?: '—').($customer->parent_phone ? ' · '.$customer->parent_phone : ''))],
        ['mail', 'Email', $customer->email ?: '—'],
        ['campaign', 'Nguồn', $customer->source ?: '—'],
        ['apartment', 'Chi nhánh', $customer->branch?->name ?? '—'],
        ['account_circle', 'Phụ trách', $customer->assignedUser?->name ?? 'Chưa phân công'],
        ['school', 'Khóa quan tâm', $customer->course_interest ?: '—'],
        ['payments', 'Giá trị dự kiến', $customer->deal_value > 0 ? number_format((float) $customer->deal_value, 0, ',', '.').'đ' : '—'],
    ];
@endphp
<x-ui.modal-frame :title="$customer->name" :description="$customer->code.' · cập nhật '.($customer->updated_at ?? $customer->created_at)->format('H:i d/m/Y')" cancel="Đóng">
    <div class="space-y-lg" data-testid="customer-quick-view">
        {{-- Giai đoạn + hạn liên hệ --}}
        <div class="flex flex-wrap items-center gap-sm">
            <span class="inline-flex items-center rounded-full border px-sm py-0.5 font-body-small text-body-small font-bold {{ $customer->stage_badge }}">{{ $customer->stage_label }}</span>
            @if ($customer->next_follow_up_at)
                <span @class([
                    'inline-flex items-center gap-xs rounded-full px-sm py-0.5 font-caption text-caption font-semibold',
                    'bg-error-container text-error' => $followUp === 'overdue',
                    'bg-warning-container text-on-warning-container' => $followUp === 'due_soon',
                    'bg-surface-container-high text-on-surface-variant' => ! $followUp,
                ])>
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">alarm</span>
                    Hạn liên hệ: {{ $customer->next_follow_up_at->format('H:i d/m/Y') }}{{ $followUp === 'overdue' ? ' (quá hạn)' : ($followUp === 'due_soon' ? ' (sắp hết hạn)' : '') }}
                </span>
            @endif
        </div>

        {{-- Thông tin chính --}}
        <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
            @foreach ($fields as [$icon, $label, $value])
                <div class="flex items-start gap-sm">
                    <span class="material-symbols-outlined mt-0.5 text-[18px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>
                    <div class="min-w-0">
                        <dt class="font-caption text-caption text-on-surface-variant">{{ $label }}</dt>
                        <dd class="break-words font-body-medium text-body-medium text-on-surface">{{ $value }}</dd>
                    </div>
                </div>
            @endforeach
        </dl>
        @if ($customer->notes)
            <div class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small text-on-surface">
                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Ghi chú</p>
                <p class="whitespace-pre-line">{{ $customer->notes }}</p>
            </div>
        @endif

        {{-- Chăm sóc gần nhất --}}
        <section class="space-y-sm">
            <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">history</span>Lịch sử chăm sóc gần nhất
            </h3>
            @forelse ($recentHistories as $history)
                <div class="flex gap-sm border-l-2 border-surface-container-highest pl-md">
                    <span class="material-symbols-outlined mt-0.5 text-[18px] text-primary" aria-hidden="true">{{ $history->type_icon }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-body-small text-body-small text-on-surface">{{ \Illuminate\Support\Str::limit($history->content, 220) }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            {{ \App\Models\CrmCustomerHistory::FILTER_TYPES[$history->type] ?? 'Hoạt động' }} · {{ $history->user?->name ?? 'Hệ thống' }} · {{ $history->created_at?->format('H:i d/m/Y') }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="font-body-small text-body-small italic text-on-surface-variant">Chưa có lịch sử chăm sóc.</p>
            @endforelse
        </section>
    </div>

    <x-slot:footer>
        <x-ui.button variant="secondary" icon="call" :href="'tel:'.$customer->phone" hx-boost="false">Gọi</x-ui.button>
        <x-ui.button variant="secondary" icon="open_in_new" :href="route('crm.customers.show', $customer->id)" hx-boost="false">Mở trang đầy đủ</x-ui.button>
        @if ($canClose)
            <x-ui.button variant="secondary" icon="how_to_reg" :href="route('crm.closing-wizard', ['customer_id' => $customer->id])" hx-boost="false">Chốt &amp; Xếp lớp</x-ui.button>
        @endif
        @if ($canEdit)
            {{-- Link thường trong modal-frame được hx-boost → form sửa thay nội dung modal --}}
            <x-ui.button icon="edit" :href="route('crm.customers.edit', $customer->id)">Sửa thông tin</x-ui.button>
        @endif
    </x-slot:footer>
</x-ui.modal-frame>
