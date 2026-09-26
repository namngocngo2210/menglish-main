{{-- Quản lý Danh mục hệ thống (mockup epic-5/quan-ly-danh-muc-he-thong): tab theo nhóm, bảng + panel thêm/sửa bên phải. --}}
@php
    $canManage = auth()->user()->can('system_category.manage');
    $formCategory = $editing;
    $formAction = $formCategory ? route('system-categories.update', $formCategory) : route('system-categories.store');
@endphp
<x-app-layout title="Quản lý Danh mục hệ thống">
    <x-ui.page-header title="Quản lý Danh mục hệ thống" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" :href="route('system-categories.index', ['type' => $type])">Làm mới</x-ui.button>
            @if ($canManage)
                <x-ui.button icon="add_circle" :href="route('system-categories.index', ['type' => $type]).'#category-form'" x-data x-on:click.prevent="document.getElementById('f_name').focus()">Thêm danh mục mới</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.tabs class="mb-md">
        @foreach ($types as $t)
            <x-ui.tab :href="route('system-categories.index', ['type' => $t])" :active="$type === $t">{{ $typeLabels[$t] ?? $t }}</x-ui.tab>
        @endforeach
    </x-ui.tabs>

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-3">
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
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :href="route('system-categories.index', ['type' => $type, 'edit' => $category->id])" title="Sửa" aria-label="Sửa" />
                                        @if ($category->is_active)
                                            <form action="{{ route('system-categories.destroy', $category) }}" method="POST" onsubmit="return confirm('Ngừng sử dụng danh mục này? Dữ liệu cũ vẫn giữ nguyên.');">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" size="sm" variant="danger-text" icon="block" title="Ngừng sử dụng" aria-label="Ngừng sử dụng" />
                                            </form>
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
            <section id="category-form" class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md lg:sticky lg:top-md">
                <div class="flex items-center justify-between">
                    <h2 class="font-h3 text-h3 text-on-surface">{{ $formCategory ? 'Sửa giá trị' : 'Thêm giá trị mới' }}</h2>
                    @if ($formCategory)
                        <x-ui.button variant="ghost" icon="close" size="sm" :href="route('system-categories.index', ['type' => $type])" aria-label="Đóng" />
                    @endif
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">Nhóm: <span class="font-semibold text-on-surface">{{ $typeLabels[$type] }}</span></p>
                <form method="POST" action="{{ $formAction }}" class="space-y-md">
                    @csrf
                    @if ($formCategory) @method('PUT') @endif
                    <input type="hidden" name="type" value="{{ $type }}">
                    <x-ui.input name="code" label="Mã danh mục" required maxlength="50" :value="$formCategory?->code ?? $suggestedCode" :placeholder="'Vd: '.$suggestedCode" class="font-code" />
                    <x-ui.input name="name" label="Tên danh mục" required maxlength="255" :value="$formCategory?->name" placeholder="Nhập tên..." />
                    <x-ui.input type="number" name="sort_order" label="Thứ tự hiển thị" min="0" :value="$formCategory?->sort_order ?? $nextOrder" :placeholder="(string) $nextOrder" />
                    <label class="flex items-center gap-sm font-body-small text-body-small">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $formCategory?->is_active ?? true)) class="rounded border-outline-variant text-primary-container focus:ring-primary-container">
                        Đang sử dụng
                    </label>
                    <div class="flex gap-sm">
                        <x-ui.button type="submit" icon="save" class="flex-1">Lưu thông tin</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('system-categories.index', ['type' => $type])">Hủy bỏ</x-ui.button>
                    </div>
                </form>
                <p class="flex items-start gap-xs rounded-lg bg-secondary-fixed/40 p-sm font-caption text-caption text-on-secondary-fixed">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                    Thứ tự hiển thị giúp sắp xếp danh mục trong các menu chọn tại màn hình quản lý Lead và Tài chính.
                </p>
            </section>
        @endif
    </div>
</x-app-layout>
