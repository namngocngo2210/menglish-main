{{-- Sửa thông tin khách: mở từ danh sách / Kanban / xem nhanh → modal 2xl (htmx); mở thẳng URL → trang đầy đủ
     (mockup sua-thong-tin-khach). Form dùng chung: crm/customers/_edit-form. --}}
@if ($asModal)
    <x-ui.modal-frame title="Sửa thông tin khách" :description="$customer->name.' · '.$customer->code">
        @include('crm.customers._edit-form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-customer-form" icon="save">Lưu thay đổi</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout title="Sửa thông tin khách">
    <div class="mx-auto w-full max-w-[720px] py-md">
        <div class="overflow-hidden rounded-lg border border-surface-container-highest bg-surface-container-lowest shadow-level-3">
            <div class="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low px-lg py-md">
                <div class="flex min-w-0 items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">edit_square</span>
                    <div class="min-w-0">
                        <h1 class="font-h2 text-h2 text-on-surface">Sửa thông tin khách</h1>
                        <p class="truncate font-code text-caption text-on-surface-variant">{{ $customer->name }} · {{ $customer->code }}</p>
                    </div>
                </div>
                <a href="{{ route('crm.customers.show', $customer->id) }}" aria-label="Đóng" class="group rounded-full p-xs transition-colors hover:bg-surface-container-highest">
                    <span class="material-symbols-outlined text-on-surface-variant group-hover:text-on-surface">close</span>
                </a>
            </div>

            @include('crm.customers._edit-form')
            <div class="h-1 w-full bg-gradient-to-r from-primary-container to-secondary"></div>
        </div>
    </div>
</x-app-layout>
@endif
