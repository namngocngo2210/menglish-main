<script setup>
/** Trường form tạo ticket — dùng chung modal và trang riêng (Create.vue). Đính kèm: AttachmentUploader (chọn / kéo thả / dán ảnh). */
import { ref } from 'vue';
import AttachmentUploader from './AttachmentUploader.vue';

defineProps({ staffs: { type: Array, default: () => [] } });

const uploader = ref(null);
defineExpose({ reset: () => uploader.value?.reset() });

const categories = [
    { value: 'technical_issue', label: 'Lỗi Hệ Thống / IT' },
    { value: 'curriculum', label: 'Giáo Trình / Học Vụ' },
    { value: 'tuition', label: 'Học Phí / Hóa Đơn' },
    { value: 'customer_complaint', label: 'Khiếu Nại Học Viên' },
    { value: 'other', label: 'Yêu Cầu Hỗ Trợ Khác' },
];
const priorities = [
    { value: 'low', label: 'Thấp (Low)' },
    { value: 'medium', label: 'Trung bình (Medium)' },
    { value: 'high', label: 'Cao (High)' },
    { value: 'urgent', label: 'Khẩn cấp (Urgent)' },
];
</script>

<template>
    <div class="space-y-4 text-xs">
        <UiInput name="title" label="Tiêu đề sự cố / yêu cầu" required placeholder="Ví dụ: Lỗi không xuất được hóa đơn điện tử cho học viên HV-0012" class="text-xs font-bold" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <UiSelect name="category" label="Phân loại danh mục" required class="text-xs font-semibold" :options="categories" :value="categories[0].value" />
            <UiSelect name="priority" label="Mức độ ưu tiên" required class="text-xs font-semibold" value="medium" :options="priorities" />
            <UiSelect name="assignee_id" label="Phân công người xử lý" class="text-xs" placeholder="-- Để mở (Chưa gán) --" :value="''" :options="staffs" />
        </div>

        <UiTextarea name="description" label="Mô tả chi tiết sự cố / Nội dung yêu cầu" :rows="4" required class="text-xs" placeholder="Mô tả cụ thể các bước tái hiện lỗi, đường dẫn URL bị lỗi hoặc yêu cầu nghiệp vụ cần xử lý..." />

        <AttachmentUploader ref="uploader" />
    </div>
</template>
