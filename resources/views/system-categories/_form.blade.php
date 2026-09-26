{{--
    Form Thêm/Sửa danh mục — dùng chung cho panel bên phải trang danh sách, trang thêm riêng và modal (system-categories/form, $asModal).
    Biến: $category (SystemCategory, có thể chưa lưu), $typeLabels, $asModal (bool), $typeSelect (bool — cho chọn nhóm, mặc định khi thêm mới),
          $nextOrder (tuỳ chọn — thứ tự gợi ý khi thêm mới).
    Trong modal: id có tiền tố "modal-" (tránh trùng form ở panel), nút Lưu nằm ở footer của x-ui.modal-frame.
--}}
@php
    $asModal = $asModal ?? false;
    $typeSelect = $typeSelect ?? ! $category->exists;
    $nextOrder = $nextOrder ?? null;
    $id = fn (string $field) => $asModal ? 'modal-category-'.$field : null;
@endphp
<form id="{{ $asModal ? 'modal-' : '' }}category-form" method="POST" class="space-y-md"
      action="{{ $category->exists ? route('system-categories.update', $category) : route('system-categories.store') }}">
    @csrf
    @if ($category->exists) @method('PUT') @endif

    @if ($typeSelect)
        <x-ui.select name="type" :id="$id('type')" label="Nhóm danh mục" required :options="$typeLabels" :value="$category->type" />
    @else
        <input type="hidden" name="type" value="{{ $category->type }}">
        @if ($asModal)
            <p class="font-body-small text-body-small text-on-surface-variant">Nhóm: <span class="font-semibold text-on-surface">{{ $typeLabels[$category->type] ?? $category->type }}</span></p>
        @endif
    @endif
    <x-ui.input name="code" :id="$id('code')" label="Mã danh mục" required maxlength="50" :value="$category->code" class="font-code"
                :placeholder="$category->exists ? null : 'Vd: '.$category->code" />
    <x-ui.input name="name" :id="$id('name')" label="Tên danh mục" required maxlength="255" :value="$category->name" placeholder="Nhập tên..." />
    <x-ui.input type="number" name="sort_order" :id="$id('sort_order')" label="Thứ tự hiển thị" min="0" :value="$category->sort_order ?? $nextOrder"
                :placeholder="$nextOrder !== null ? (string) $nextOrder : 'Để trống = cuối danh sách'" />
    <label class="flex items-center gap-sm font-body-small text-body-small">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->exists ? $category->is_active : true)) class="rounded border-outline-variant text-primary-container focus:ring-primary-container">
        Đang sử dụng
    </label>

    @unless ($asModal)
        <div class="flex gap-sm">
            <x-ui.button type="submit" icon="save" class="flex-1">Lưu thông tin</x-ui.button>
            <x-ui.button variant="secondary" :href="route('system-categories.index', ['type' => $category->type])">Hủy bỏ</x-ui.button>
        </div>
    @endunless
</form>
