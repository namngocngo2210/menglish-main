{{-- Thêm khách mới: mở từ Kanban / danh sách → modal 2xl (htmx); mở thẳng URL → trang đầy đủ (mockup them-khach-moi).
     Trường phụ (email, ngày sinh, khóa quan tâm, hạn liên hệ, người phụ trách…) gom vào "Thông tin bổ sung". --}}
@if ($asModal)
    <x-ui.modal-frame title="Thêm khách mới" description="Khách mới vào giai đoạn Mới; SĐT / email không được trùng khách đang hoạt động.">
        @include('crm.customers._create-form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-customer-form" icon="save">Lưu thông tin</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
<x-app-layout title="Thêm khách mới">
    <div class="mx-auto w-full max-w-[560px] py-md">
        <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-level-3">
            <div class="flex items-center justify-between px-xl pb-md pt-xl">
                <h1 class="font-h2 text-h2 text-primary">Thêm khách mới</h1>
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('crm.customers.index') }}" aria-label="Đóng"
                   class="rounded-full p-xs text-on-surface-variant transition-colors hover:bg-surface-variant active:scale-95">
                    <span class="material-symbols-outlined block">close</span>
                </a>
            </div>

            @include('crm.customers._create-form')
        </div>
    </div>
</x-app-layout>
@endif
