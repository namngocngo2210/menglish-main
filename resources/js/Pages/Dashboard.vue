<script setup>
/**
 * Tổng quan: bảng điều hành Admin / Quản lý / Học thuật (RoleWidgets.vue); vai trò khác: lời chào + "Việc của bạn"
 * (MyWork.vue — lịch hẹn 7 ngày tới, việc cần xử lý, việc của tôi). Cuối trang là lưới phân hệ chức năng (chỉ gồm link
 * user mở được, do DashboardController tính).
 */
import { Link } from '@inertiajs/vue3';
import MyWork from './Dashboard/MyWork.vue';
import RoleWidgets from './Dashboard/RoleWidgets.vue';

defineOptions({ layout: { title: 'Tổng quan' } });

defineProps({
    isOperations: { type: Boolean, default: false },
    welcome: { type: Object, default: null },
    roleDashboard: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
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

        <MyWork v-if="roleDashboard?.type === 'personal'" :dashboard="roleDashboard" />
        <RoleWidgets v-else-if="roleDashboard" :dashboard="roleDashboard" />

        <!-- Lưới phân hệ chức năng -->
        <div v-if="modules.length" class="space-y-3">
            <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                <span class="material-symbols-outlined text-base text-primary">grid_view</span>
                Các Phân Hệ Chức Năng Của Bạn
            </h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="module in modules" :key="module.title" :class="['space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm transition', module.borderClass]">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div :class="['flex h-9 w-9 items-center justify-center rounded-xl', module.iconClass]">
                                <span class="material-symbols-outlined text-lg">{{ module.icon }}</span>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-on-surface">{{ module.title }}</h3>
                                <span class="text-xs text-on-surface-subtle">{{ module.subtitle }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5 text-xs">
                        <Link v-for="link in module.links" :key="link.url + link.label" :href="link.url" :class="['rounded-lg bg-surface-container-low p-2 font-medium transition', module.linkClass]">{{ link.label }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
