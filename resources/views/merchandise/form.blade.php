{{-- Thêm/Sửa hàng hóa: mở từ danh sách → modal (htmx); mở thẳng URL → trang form đầy đủ. --}}
@php $title = $isEdit ? 'Cập nhật Hàng hóa: '.$item->name : 'Thêm mới Hàng hóa & Vật phẩm'; @endphp
@if ($asModal)
    <x-ui.modal-frame :title="$title" description="Hàng hóa đang kinh doanh được chọn khi lập Hóa đơn / Phiếu thu.">
        @include('merchandise._form')
        <x-slot:footer>
            <x-ui.button type="submit" form="modal-merchandise-form" icon="save">{{ $isEdit ? 'Lưu cập nhật' : 'Tạo mới Hàng hóa' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout :title="$title">
        <x-ui.page-header :title="$title">
            <x-slot:breadcrumbs>
                <a href="{{ route('merchandise.index') }}" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">inventory_2</span>Hàng hóa & Vật phẩm</a>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ $isEdit ? 'Cập nhật' : 'Thêm mới' }}</span>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <div class="mx-auto max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            @include('merchandise._form')
        </div>
    </x-app-layout>
@endif
