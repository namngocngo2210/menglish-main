<script setup>
/**
 * Tạo order học liệu (modal). Báo trước hạn xử lý theo loại + ngày sử dụng; tạo trễ vẫn được, chỉ cảnh báo
 * (công thức hạn giống App\Models\MaterialOrder::computeDueAt — server mới là nơi tính chính thức).
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Tạo order học liệu' } });

const props = defineProps({
    categories: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
    classes: { type: Array, default: () => [] },
    startOfMonthDay: { type: Number, default: 5 },
    asModal: { type: Boolean, default: false },
});

const category = ref('props');
const branchId = ref(props.defaultBranchId);
const useDate = ref('');

const classOptions = computed(() => props.classes.filter((c) => !branchId.value || c.branch_id === Number(branchId.value)));

// Hạn xử lý dự kiến (giờ địa phương, dựng từ Y-m-d để khỏi lệch múi giờ).
const dueAt = computed(() => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(useDate.value)) return null;
    const [y, m, d] = useDate.value.split('-').map(Number);
    if (['props', 'printing'].includes(category.value)) return new Date(y, m - 1, d - 1, 15, 0);
    return new Date(y, m - 1, Math.min(props.startOfMonthDay, new Date(y, m, 0).getDate()), 23, 59);
});
const isLate = computed(() => dueAt.value !== null && dueAt.value < new Date());
const rule = computed(() =>
    ['props', 'printing'].includes(category.value)
        ? 'Học vụ xử lý trước 15:00 ngày hôm trước ngày sử dụng.'
        : category.value === 'academic'
          ? `Trưởng Học thuật xử lý đầu tháng — hạn 23:59 ngày ${props.startOfMonthDay} của tháng sử dụng.`
          : `Học vụ xử lý đầu tháng — hạn 23:59 ngày ${props.startOfMonthDay} của tháng sử dụng.`,
);
</script>

<template>
    <UiModalFrame title="Tạo order học liệu" description="Gửi yêu cầu đạo cụ, in ấn hoặc học liệu cho Học vụ / Học thuật." :action="route('material-orders.store')" method="post" submit-label="Gửi order" submit-icon="send" cancel="Hủy" :back="route('material-orders.index')" size="lg">
        <div class="space-y-md">
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect v-model="category" name="category" label="Loại order" required :options="categories" />
                <UiSelect v-model="branchId" name="branch_id" label="Chi nhánh" required :options="branches" />
            </div>
            <UiInput name="title" label="Nội dung order" required maxlength="255" placeholder="VD: Flashcard unit 5, in 20 bộ đề..." />
            <UiTextarea name="description" label="Mô tả chi tiết" :rows="3" maxlength="2000" />
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiDate v-model="useDate" name="use_date" label="Ngày sử dụng" required />
                <UiInput type="number" name="quantity" label="Số lượng" min="1" />
                <UiSelect name="class_id" label="Lớp (nếu có)" placeholder="-- Không gắn lớp --" :options="classOptions" />
            </div>
            <UiAlert type="info" title="Hạn xử lý">
                {{ rule }}
                <template v-if="dueAt"> Hạn của order này: <strong>{{ formatDate(dueAt, 'd/m/Y H:i') }}</strong>.</template>
            </UiAlert>
            <UiAlert v-if="isLate" type="warning" title="Tạo trễ">Đã quá hạn xử lý — order vẫn được gửi nhưng sẽ gắn nhãn “Tạo trễ”.</UiAlert>
        </div>
    </UiModalFrame>
</template>
