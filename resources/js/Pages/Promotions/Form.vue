<script setup>
/**
 * Thêm / Sửa ưu đãi trong danh mục (mở từ danh sách → modal; mở thẳng URL → trang đầy đủ).
 * Ưu đãi riêng (ca đặc biệt) tạo trong màn Chốt & Xếp lớp; ở đây chỉ sửa thông tin / lý do của nó.
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Ưu đãi học phí' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    promotion: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    courses: { type: Array, default: () => [] },
    isEdit: { type: Boolean, default: false },
});

const type = ref(props.promotion.type ?? 'percent');
const typeOptions = [
    { value: 'percent', label: 'Phần trăm học phí (%)' },
    { value: 'fixed', label: 'Số tiền cố định (VNĐ)' },
];
const title = computed(() => (props.isEdit ? `Sửa ưu đãi: ${props.promotion.name}` : 'Thêm ưu đãi vào danh mục'));
const action = computed(() => (props.isEdit ? route('crm.promotions.update', props.promotion.id) : route('crm.promotions.store')));
</script>

<template>
    <UiModalFrame
        :title="title"
        description="Ưu đãi trong danh mục được chọn lại khi chốt khách và khi lập phiếu thu. Không xóa ưu đãi, chỉ ngừng áp dụng."
        :action="action"
        :method="isEdit ? 'put' : 'post'"
        :back="route('crm.promotions.index')"
        submit-icon="save"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm ưu đãi'"
    >
        <div class="space-y-md">
            <UiAlert v-if="promotion.is_special" type="info" title="Ưu đãi riêng (ca đặc biệt)">
                Tạo khi chốt khách, chỉ dùng 1 lần. Đã dùng {{ promotion.used_count }} lượt.
            </UiAlert>

            <UiInput name="name" label="Tên ưu đãi" required :value="promotion.name" placeholder="VD: Ưu đãi khai giảng, Anh chị em ruột, Học viên cũ quay lại" />

            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiSelect v-model="type" name="type" label="Loại giảm" required :options="typeOptions" />
                <UiInput type="number" name="value" :label="type === 'percent' ? 'Giảm (%)' : 'Giảm (VNĐ)'" required min="0" :max="type === 'percent' ? 100 : undefined" :step="type === 'percent' ? 0.5 : 1000" :value="promotion.value" class="font-code" />
                <UiInput v-if="type === 'percent'" type="number" name="max_discount_amount" label="Giảm tối đa (VNĐ)" min="0" step="1000" :value="promotion.max_discount_amount" placeholder="Không giới hạn" class="font-code" />
            </div>

            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect name="branch_id" label="Cơ sở áp dụng" :options="branches" :value="promotion.branch_id" placeholder="Mọi cơ sở" />
                <UiSelect name="course_id" label="Khóa học áp dụng" :options="courses" :value="promotion.course_id" placeholder="Mọi khóa học" />
            </div>

            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiInput type="datetime-local" name="starts_at" label="Bắt đầu" :value="promotion.starts_at" />
                <UiInput type="datetime-local" name="ends_at" label="Kết thúc" :value="promotion.ends_at" />
                <UiInput v-if="!promotion.is_special" type="number" name="usage_limit" label="Số lượt tối đa" min="1" :value="promotion.usage_limit" placeholder="Không giới hạn" :hint="isEdit ? `Đã dùng ${promotion.used_count} lượt` : null" />
            </div>

            <UiTextarea name="description" label="Điều kiện áp dụng" rows="2" :value="promotion.description" placeholder="VD: Đăng ký trước ngày khai giảng 7 ngày, đóng trọn khóa" />
            <UiTextarea v-if="promotion.is_special" name="reason" label="Lý do ca đặc biệt" required rows="2" :value="promotion.reason" />

            <div class="flex flex-col gap-sm border-t border-surface-container pt-md">
                <!-- Bỏ tích vẫn gửi 0 (ô tích chưa chọn không có trong form). -->
                <input type="hidden" name="is_default" value="0" />
                <input type="hidden" name="is_active" value="0" />
                <UiCheckbox
                    v-if="!promotion.is_special"
                    name="is_default"
                    value="1"
                    :checked="promotion.is_default"
                    label="Ưu đãi mặc định"
                    hint="Tự chọn sẵn khi chốt khách đúng cơ sở / khóa ở trên. Có nhiều ưu đãi mặc định thì chọn ưu đãi giới hạn cụ thể nhất."
                />
                <UiCheckbox name="is_active" value="1" :checked="promotion.is_active" label="Đang áp dụng" />
            </div>
        </div>
    </UiModalFrame>
</template>
