{{-- Trang thêm danh mục riêng (dự phòng khi không dùng panel ở trang danh sách). --}}
<x-app-layout title="Thêm danh mục mới">
    <x-ui.page-header title="Thêm danh mục mới" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH." />

    <div class="mx-auto max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
        <form method="POST" action="{{ route('system-categories.store') }}" class="space-y-md">
            @csrf
            <x-ui.select name="type" label="Nhóm danh mục" required :options="$typeLabels" :value="$category->type" />
            <x-ui.input name="code" label="Mã danh mục" required maxlength="50" :value="$category->code" class="font-code" />
            <x-ui.input name="name" label="Tên danh mục" required maxlength="255" placeholder="Nhập tên..." />
            <x-ui.input type="number" name="sort_order" label="Thứ tự hiển thị" min="0" />
            <label class="flex items-center gap-sm font-body-small text-body-small">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true)) class="rounded border-outline-variant text-primary-container focus:ring-primary-container">
                Đang sử dụng
            </label>
            <div class="flex justify-end gap-sm">
                <x-ui.button variant="secondary" :href="route('system-categories.index', ['type' => $category->type])">Hủy bỏ</x-ui.button>
                <x-ui.button type="submit" icon="save">Lưu thông tin</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
