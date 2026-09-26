{{--
    Form Thêm/Sửa hàng hóa — dùng chung cho trang đầy đủ (merchandise/form) và modal ($asModal).
    Biến: $item, $categories, $isEdit, $asModal (bool, tuỳ chọn — id tiền tố "modal-", nút Lưu ở footer x-ui.modal-frame).
--}}
@php
    $asModal = $asModal ?? false;
    $id = fn (string $field) => $asModal ? 'modal-merchandise-'.$field : null;
@endphp
<form id="{{ $asModal ? 'modal-' : '' }}merchandise-form" method="POST" class="space-y-md"
      action="{{ $isEdit ? route('merchandise.update', $item) : route('merchandise.store') }}">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <x-ui.input name="code" :id="$id('code')" label="Mã hàng hóa (SKU)" required :value="$item->code" placeholder="Ví dụ: BOOK-CAM-S3, UNI-POLO-M..."
                    class="font-code uppercase" hint="Mã định danh duy nhất của hàng hóa trong hệ thống." />
        <x-ui.select name="category" :id="$id('category')" label="Nhóm phân loại" required :value="$item->category"
                     :options="collect($categories)->map(fn ($cat) => $cat['label'])" />
    </div>

    <x-ui.input name="name" :id="$id('name')" label="Tên hàng hóa / Vật phẩm" required :value="$item->name"
                placeholder="Ví dụ: Bộ Giáo trình Cambridge Stage 3, Áo Polo Đồng phục MEnglish..." />

    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        <x-ui.input name="unit" :id="$id('unit')" label="Đơn vị tính" required :value="$item->unit ?? 'Bộ'" placeholder="Bộ, Cuốn, Chiếc, Cái..." />
        <x-ui.input type="number" name="price" :id="$id('price')" label="Đơn giá niêm yết (VNĐ)" required min="0" step="1000"
                    :value="(int) $item->price" class="font-code" hint="Giá tính vào hợp đồng & hoá đơn." />
        <x-ui.input type="number" name="cost_price" :id="$id('cost_price')" label="Giá vốn nhập (VNĐ)" min="0" step="1000"
                    :value="$item->cost_price ? (int) $item->cost_price : null" placeholder="Tùy chọn" class="font-code" />
    </div>

    <div class="grid grid-cols-1 items-end gap-md sm:grid-cols-2">
        <x-ui.input type="number" name="stock_quantity" :id="$id('stock_quantity')" label="Số lượng tồn kho ban đầu" required min="0"
                    :value="$item->stock_quantity ?? 0" class="font-code" />
        <label class="flex items-center gap-sm pb-sm font-body-small text-body-small text-on-surface">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active ?? true))
                   class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
            Kích hoạt kinh doanh (cho phép chọn khi tạo Hóa đơn / Phiếu thu)
        </label>
    </div>

    <x-ui.textarea name="description" :id="$id('description')" label="Mô tả & Ghi chú về hàng hóa" rows="3" :value="$item->description"
                   placeholder="Nhập thông tin chi tiết về sách, độ tuổi phù hợp, chất liệu đồng phục hoặc phụ kiện đi kèm..." />

    @unless ($asModal)
        <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
            <x-ui.button variant="secondary" :href="route('merchandise.index')">Hủy bỏ</x-ui.button>
            <x-ui.button type="submit" icon="save">{{ $isEdit ? 'Lưu cập nhật' : 'Tạo mới Hàng hóa' }}</x-ui.button>
        </div>
    @endunless
</form>
