<script setup>
/**
 * Tổng quan: bảng điều hành Admin / Quản lý / Học thuật (RoleWidgets.vue); vai trò khác: lời chào + "Việc của bạn"
 * (MyWork.vue — lịch hẹn 7 ngày tới, việc cần xử lý, việc của tôi); chất lượng giảng dạy theo tháng (TeachingQuality.vue).
 * Lưới phân hệ (phím tắt) cuối trang đã bỏ (04/10/2026).
 */
import MyWork from './Dashboard/MyWork.vue';
import RoleWidgets from './Dashboard/RoleWidgets.vue';
import TeachingQuality from './Dashboard/TeachingQuality.vue';

defineOptions({ layout: { title: 'Tổng quan' } });

defineProps({
    isOperations: { type: Boolean, default: false },
    welcome: { type: Object, default: null },
    roleDashboard: { type: Object, default: null },
    teaching: { type: Object, default: null },
});
</script>

<template>
    <!-- Tiêu đề trùng tên menu "Tổng quan"; bảng điều hành (Admin / Quản lý) có thêm dòng mô tả. -->
    <UiPageHeader title="Tổng quan" icon="dashboard" :description="isOperations ? 'Bảng điều hành toàn trung tâm' : null" />

    <div class="space-y-6">
        <!-- Lời chào cho nhân sự / giáo viên -->
        <div v-if="welcome" class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-bold text-on-surface">Xin chào, {{ welcome.name }}!</h2>
                <p class="mt-1 text-xs text-on-surface-variant">Vai trò: <span class="font-semibold text-primary">{{ welcome.roles }}</span> · Chi nhánh: {{ welcome.branch }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <UiButton v-if="welcome.salaryUrl" variant="secondary" size="sm" icon="payments" :href="welcome.salaryUrl">Lương của tôi</UiButton>
                <UiButton v-if="welcome.tasksUrl" variant="secondary" size="sm" icon="checklist" :href="welcome.tasksUrl">Nhiệm vụ hôm nay</UiButton>
            </div>
        </div>

        <!-- Giáo viên / trợ giảng: chất lượng giảng dạy lên đầu; vai trò khác: sau bảng điều hành / việc của bạn. -->
        <TeachingQuality v-if="teaching?.mine" :teaching="teaching" />
        <MyWork v-if="roleDashboard?.type === 'personal'" :dashboard="roleDashboard" />
        <RoleWidgets v-else-if="roleDashboard" :dashboard="roleDashboard" />
        <TeachingQuality v-if="teaching && !teaching.mine" :teaching="teaching" />
    </div>
</template>
