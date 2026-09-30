<script setup>
/** Trường form Thêm/Sửa hàng hóa — dùng chung cho modal và trang đầy đủ (Form.vue). */
defineProps({
    item: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <UiInput name="code" label="Mã hàng hóa (SKU)" required :value="item.code" placeholder="Ví dụ: BOOK-CAM-S3, UNI-POLO-M..." class="font-code uppercase" hint="Mã định danh duy nhất của hàng hóa trong hệ thống." />
        <UiSelect name="category" label="Nhóm phân loại" required :value="item.category" :options="categories" />
    </div>

    <UiInput name="name" label="Tên hàng hóa / Vật phẩm" required :value="item.name" placeholder="Ví dụ: Bộ Giáo trình Cambridge Stage 3, Áo Polo Đồng phục MEnglish..." />

    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        <UiInput name="unit" label="Đơn vị tính" required :value="item.unit ?? 'Bộ'" placeholder="Bộ, Cuốn, Chiếc, Cái..." />
        <UiInput type="number" name="price" label="Đơn giá niêm yết (VNĐ)" required min="0" step="1000" :value="item.price" class="font-code" hint="Giá tính vào hợp đồng & hoá đơn." />
        <UiInput type="number" name="cost_price" label="Giá vốn nhập (VNĐ)" min="0" step="1000" :value="item.cost_price" placeholder="Tùy chọn" class="font-code" />
    </div>

    <div class="grid grid-cols-1 items-end gap-md sm:grid-cols-2">
        <UiInput type="number" name="stock_quantity" label="Số lượng tồn kho ban đầu" required min="0" :value="item.stock_quantity ?? 0" class="font-code" />
        <UiCheckbox name="is_active" value="1" :checked="item.is_active ?? true" class="items-center pb-sm" label="Kích hoạt kinh doanh (cho phép chọn khi tạo Hóa đơn / Phiếu thu)" />
    </div>

    <UiTextarea name="description" label="Mô tả & Ghi chú về hàng hóa" rows="3" :value="item.description" placeholder="Nhập thông tin chi tiết về sách, độ tuổi phù hợp, chất liệu đồng phục hoặc phụ kiện đi kèm..." />
</template>
