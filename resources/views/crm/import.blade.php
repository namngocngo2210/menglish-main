{{--
    Nhập khách hàng loạt từ Excel: bước 1 tải file → bước 2 xem trước + lỗi từng dòng → nhập các dòng hợp lệ.
    Mở từ nút "Nhập Excel" (CRM) → modal (htmx): 2 bước đổi nội dung ngay trong modal (bước 2 nới rộng 4xl);
    mở thẳng URL → trang đầy đủ (form + bảng xem trước) như cũ.
--}}
@if ($asModal)
    <x-ui.modal-frame :title="$preview ? 'Xem trước dữ liệu nhập' : 'Nhập khách hàng loạt từ Excel'" cancel="Đóng" :size="$preview ? '4xl' : null"
                      :description="$preview ? 'Bước 2/2 — kiểm tra lỗi từng dòng, chỉ các dòng hợp lệ được nhập.' : 'Bước 1/2 — tải file .xlsx / .csv (dòng 1 là tiêu đề).'">
        @if (session('status'))
            <x-ui.alert type="success" class="mb-md">{{ session('status') }}</x-ui.alert>
        @endif
        @if ($preview)
            @include('crm.import._preview')
        @else
            @include('crm.import._upload')
        @endif
        <x-slot:footer>
            @if ($preview)
                @php $validCount = collect($preview['rows'])->filter(fn ($r) => empty($r['errors']))->count(); @endphp
                <x-ui.button type="submit" form="modal-crm-import-cancel" variant="secondary" icon="undo">Hủy, chọn file khác</x-ui.button>
                <x-ui.button type="submit" form="modal-crm-import-confirm" icon="upload" :disabled="$validCount === 0">Nhập {{ $validCount }} khách hợp lệ</x-ui.button>
            @else
                <x-ui.button variant="secondary" icon="download" :href="route('crm.import.template')" hx-boost="false">Tải file mẫu</x-ui.button>
                <x-ui.button type="submit" form="modal-crm-import-form" icon="fact_check">Kiểm tra dữ liệu</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout>
        @include('crm.partials.header-tabs')

        <div class="space-y-4">
            <x-ui.page-header title="Nhập khách hàng loạt từ Excel"
                description="Tải file .xlsx / .csv (dòng 1 là tiêu đề). Hệ thống kiểm tra từng dòng (họ tên, SĐT Việt Nam, trùng trong file / trùng CRM, email) trước khi nhập.">
                <x-slot:actions>
                    <x-ui.button variant="secondary" icon="download" :href="route('crm.import.template')">Tải file mẫu</x-ui.button>
                </x-slot:actions>
            </x-ui.page-header>

            @include('crm.import._upload')

            @if ($preview)
                @include('crm.import._preview')
            @endif
        </div>
    </x-app-layout>
@endif
