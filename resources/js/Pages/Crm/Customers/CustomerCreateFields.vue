<script setup>
/** Trường form Thêm khách mới — dùng chung modal và trang đầy đủ (Create.vue). */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    branches: { type: Array, default: () => [] },
    salesUsers: { type: Array, default: () => [] },
    leadSources: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
    defaultAssigneeId: { type: Number, default: null },
});
const page = usePage();
// Lỗi ở trường bổ sung → mở sẵn khối "Thông tin bổ sung".
const extraOpen = computed(() => ['email', 'parent_phone', 'next_follow_up_at', 'assigned_user_id', 'dob', 'deal_value'].some((key) => page.props.errors?.[key]));
const genders = [{ value: 'Nam', label: 'Nam' }, { value: 'Nữ', label: 'Nữ' }, { value: 'Khác', label: 'Khác' }];
</script>

<template>
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <UiInput name="name" label="Họ và tên" required placeholder="Nhập họ và tên khách" />
        <UiInput name="phone" type="tel" label="Số điện thoại" required placeholder="Nhập số điện thoại" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
    </div>

    <UiInput name="parent_name" label="Tên phụ huynh (tùy chọn)" placeholder="Nhập tên phụ huynh nếu có" />

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <UiSelect name="source" label="Nguồn khách" required placeholder="Chọn nguồn khách" :options="leadSources" />
        <UiSelect name="branch_id" label="Chi nhánh" required placeholder="Chọn cơ sở học tập" :value="defaultBranchId" :options="branches" />
    </div>

    <details class="group rounded-lg border border-surface-container-highest" :open="extraOpen || null">
        <summary class="flex cursor-pointer select-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface-variant">
            Thông tin bổ sung (tùy chọn)
            <span class="material-symbols-outlined transition-transform group-open:rotate-180">expand_more</span>
        </summary>
        <div class="grid grid-cols-1 gap-md border-t border-surface-container-highest p-md sm:grid-cols-2">
            <UiInput name="parent_phone" type="tel" label="SĐT phụ huynh" placeholder="0912 345 678" />
            <UiInput name="next_follow_up_at" type="datetime-local" label="Hạn liên hệ tiếp theo" />
            <UiInput name="email" type="email" label="Email" placeholder="hocvien@example.com" />
            <UiDate name="dob" label="Ngày sinh" />
            <UiSelect name="gender" label="Giới tính" placeholder="-- Chọn --" :options="genders" />
            <UiInput name="course_interest" label="Khóa học quan tâm" placeholder="Ví dụ: Starters" />
            <div class="sm:col-span-2"><UiInput name="address" label="Địa chỉ" /></div>
            <UiSelect v-if="can('lead.assign')" name="assigned_user_id" label="Người phụ trách" placeholder="-- Chọn người phụ trách --" :value="defaultAssigneeId" :options="salesUsers" />
            <UiInput name="deal_value" type="number" min="0" label="Giá trị dự kiến (VNĐ)" :value="0" />
            <div class="sm:col-span-2">
                <UiTextarea name="notes" label="Ghi chú ban đầu" :rows="3" />
            </div>
        </div>
    </details>
</template>
