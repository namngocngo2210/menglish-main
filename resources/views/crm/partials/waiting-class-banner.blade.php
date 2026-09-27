{{-- Băng nhắc "N khách chờ xếp lớp" cho các màn phụ (Khách chốt, Xác nhận chính thức).
     Xếp lớp chỉ làm ở một nơi: màn Chờ xếp lớp (crm.waiting-list) — ở đây chỉ hiện số và link về đó.
     Cần: $waitingCount --}}
@if ($waitingCount > 0)
    <div class="flex flex-wrap items-center justify-between gap-sm rounded-xl border border-error/20 bg-error-container/20 px-md py-sm" data-testid="waiting-class-banner">
        <div class="flex items-center gap-sm font-body-medium text-body-medium text-on-surface">
            <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1;" aria-hidden="true">error</span>
            <span><strong class="text-error">{{ $waitingCount }}</strong> khách đã chốt đang chờ xếp lớp</span>
        </div>
        <x-ui.button variant="secondary" size="sm" icon="arrow_forward" :href="route('crm.waiting-list')">
            {{ auth()->user()->can('student.assign_class') ? 'Xếp lớp' : 'Xem danh sách' }}
        </x-ui.button>
    </div>
@endif
