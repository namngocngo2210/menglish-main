<script setup>
/**
 * Thanh chuyển khối / loại nhân sự của bảng lương một kỳ: Tất cả · GV Part-time · GV Full-time · Học thuật · Học vụ & Vận hành.
 * current: all | parttime | fulltime | academic | operations (nút đang chọn tô màu chính); `department`: nhãn viết hoa như trang khối.
 */
import { computed } from 'vue';
import { route } from '@/lib/route';

const props = defineProps({
    periodId: { type: [Number, String], required: true },
    current: { type: String, default: null },
    department: { type: Boolean, default: false },
});

const tabs = computed(() => [
    { key: 'all', icon: 'groups', label: 'Tất cả', href: route('payroll.periods.show', props.periodId) },
    { key: 'parttime', icon: 'schedule', label: 'GV Part-time', href: route('payroll.periods.show', { id: props.periodId, type: 'teacher_parttime' }) },
    { key: 'fulltime', icon: 'work', label: 'Giáo viên Full-time', href: route('payroll.periods.fulltime', props.periodId) },
    { key: 'academic', icon: 'school', label: props.department ? 'Khối Học Thuật' : 'Khối Học thuật', href: route('payroll.periods.academic', props.periodId) },
    { key: 'operations', icon: 'support_agent', label: props.department ? 'Khối Học Vụ & Vận Hành' : 'Khối Học vụ & Vận hành', href: route('payroll.periods.operations', props.periodId) },
]);
</script>

<template>
    <nav class="flex flex-wrap gap-sm border-b border-surface-container pb-sm" aria-label="Bảng lương theo khối">
        <UiButton v-for="tab in tabs" :key="tab.key" :variant="current === tab.key ? 'primary' : 'secondary'" size="sm" :icon="tab.icon" :href="tab.href">{{ tab.label }}</UiButton>
    </nav>
</template>
