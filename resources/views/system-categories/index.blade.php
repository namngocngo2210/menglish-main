{{--
    Quản lý Danh mục hệ thống (mockup epic-5/quan-ly-danh-muc-he-thong): tab theo nhóm, bảng + panel thêm nhanh bên phải.
    "Thêm danh mục mới" / Sửa dòng mở modal (htmx), Ngừng sử dụng qua modal xác nhận. Panel sửa chỉ hiện khi mở thẳng URL edit (?edit=).
    Lưu/ngừng xong server phát "system-categories-changed" → #category-list tự tải lại (giữ tab, tìm kiếm, trang).
--}}
@php
    $canManage = auth()->user()->can('system_category.manage');
    $formCategory = $editing ?? new \App\Models\SystemCategory(['type' => $type, 'code' => $suggestedCode]);
@endphp
<x-app-layout title="Quản lý Danh mục hệ thống">
    <x-ui.page-header title="Quản lý Danh mục hệ thống" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" :href="route('system-categories.index', ['type' => $type])">Làm mới</x-ui.button>
            @if ($canManage)
                <x-ui.button icon="add_circle" :href="route('system-categories.create', ['type' => $type])" modal="md">Thêm danh mục mới</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.tabs class="mb-md">
        @foreach ($types as $t)
            <x-ui.tab :href="route('system-categories.index', ['type' => $t])" :active="$type === $t">{{ $typeLabels[$t] ?? $t }}</x-ui.tab>
        @endforeach
    </x-ui.tabs>

    <div x-data="{ del: { url: '', name: '' } }">
    <div id="category-list" class="grid grid-cols-1 items-start gap-lg lg:grid-cols-3"
         hx-get="{{ route('system-categories.index', request()->query()) }}" hx-trigger="system-categories-changed from:body" hx-select="#category-list" hx-swap="outerHTML" hx-disinherit="*">
        <x-ui.data-table class="lg:col-span-2">
            <x-slot:header>
                <form method="GET" action="{{ route('system-categories.index') }}" class="flex w-full items-center gap-sm">
                    <input type="hidden" name="type" value="{{ $type }}">
                    <div class="flex-1"><x-ui.input name="q" icon="search" :value="$search" placeholder="Tìm mã hoặc tên danh mục..." aria-label="Tìm kiếm" /></div>
                </form>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên danh mục</th>
                        <th class="text-center">Thứ tự</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr class="{{ $editing?->id === $category->id ? 'bg-primary-fixed/40' : '' }} {{ $category->is_active ? '' : 'opacity-70' }}">
                            <td class="font-code">{{ $category->code }}</td>
                            <td class="font-medium">{{ $category->name }}</td>
                            <td class="text-center font-code">{{ $category->sort_order }}</td>
                            <td>
                                @if ($category->is_active)
                                    <x-ui.badge color="success">Đang dùng</x-ui.badge>
                                @else
                                    <x-ui.badge color="neutral">Đã ngừng</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($canManage)
                                    <div class="flex items-center justify-end gap-xs">
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :href="route('system-categories.edit', $category)" modal="md" title="Sửa" aria-label="Sửa {{ $category->name }}" />
                                        @if ($category->is_active)
                                            <x-ui.button size="sm" variant="danger-text" icon="block" title="Ngừng sử dụng" aria-label="Ngừng sử dụng {{ $category->name }}"
                                                         data-url="{{ route('system-categories.destroy', $category) }}" data-name="{{ $category->name }}"
                                                         @click="del = { url: $el.dataset.url, name: $el.dataset.name }; $dispatch('open-modal', 'deactivate-category')" />
                                        @else
                                            <form action="{{ route('system-categories.reactivate', $category) }}" method="POST">
                                                @csrf
                                                <x-ui.button type="submit" size="sm" variant="ghost" icon="settings_backup_restore" title="Kích hoạt lại">Kích hoạt lại</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="category" title="Chưa có danh mục nào" description="Thêm giá trị đầu tiên ở panel bên phải." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$categories" unit="kết quả" />
            </x-slot:footer>
        </x-ui.data-table>

        @if ($canManage)
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md lg:sticky lg:top-md">
                <div class="flex items-center justify-between">
                    <h2 class="font-h3 text-h3 text-on-surface">{{ $editing ? 'Sửa giá trị' : 'Thêm giá trị mới' }}</h2>
                    @if ($editing)
                        <x-ui.button variant="ghost" icon="close" size="sm" :href="route('system-categories.index', ['type' => $type])" aria-label="Đóng" />
                    @endif
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">Nhóm: <span class="font-semibold text-on-surface">{{ $typeLabels[$type] }}</span></p>
                @include('system-categories._form', ['category' => $formCategory, 'typeSelect' => false, 'nextOrder' => $editing ? null : $nextOrder])
                <p class="flex items-start gap-xs rounded-lg bg-secondary-fixed/40 p-sm font-caption text-caption text-on-secondary-fixed">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                    Thứ tự hiển thị giúp sắp xếp danh mục trong các menu chọn tại màn hình quản lý Lead và Tài chính.
                </p>
            </section>
        @endif
    </div>

    {{-- Xác nhận ngừng sử dụng (dùng chung cho mọi dòng; url/tên lấy từ nút) --}}
    @if ($canManage)
        <x-ui.modal name="deactivate-category" title="Ngừng sử dụng danh mục?" max-width="md">
            <p>Ngừng sử dụng <strong class="font-semibold" x-text="del.name"></strong>?</p>
            <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Dữ liệu cũ vẫn giữ nguyên; có thể kích hoạt lại bất cứ lúc nào.</p>
            <form id="deactivate-category-form" method="POST" :action="del.url" hx-boost="true" hx-swap="none" hx-push-url="false">
                @csrf @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'deactivate-category')">Hủy</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="deactivate-category-form" icon="block">Ngừng sử dụng</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
    </div>
</x-app-layout>
