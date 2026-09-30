<script setup>
/** Thêm/Sửa ngày nghỉ: mở từ danh sách → modal; mở thẳng URL → trang danh sách + form bên phải (Index.vue). */
import HolidayFields from './HolidayFields.vue';
import Index from './Index.vue';

defineOptions({ layout: { title: 'Cấu hình ngày nghỉ' } });

defineProps({
    asModal: { type: Boolean, default: false },
    holiday: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    selectedBranchIds: { type: Array, default: () => [] },
    holidays: { type: Object, default: null },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <UiModalFrame
        v-if="asModal"
        :title="holiday ? 'Sửa ngày nghỉ' : 'Thêm ngày nghỉ'"
        description="Ngày nghỉ dùng để sinh lịch học, hủy buổi trùng và xếp buổi học bù."
        :action="holiday ? route('holidays.update', holiday.id) : route('holidays.store')"
        :method="holiday ? 'put' : 'post'"
    >
        <HolidayFields :holiday="holiday" :branches="branches" :selected-branch-ids="selectedBranchIds" />
    </UiModalFrame>
    <Index v-else :holidays="holidays" :can-manage="canManage" :holiday="holiday" :branches="branches" :selected-branch-ids="selectedBranchIds" form-open />
</template>
