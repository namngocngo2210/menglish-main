<script setup>
/**
 * Tổng quan: bảng điều hành theo vai trò (Admin / Quản lý / Học thuật — RoleWidgets.vue), còn lại lời chào + thẻ số liệu
 * theo quyền; cuối trang là lưới phân hệ chức năng (chỉ gồm link user mở được, do DashboardController tính).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import RoleWidgets from './Dashboard/RoleWidgets.vue';

defineOptions({ layout: { title: 'Tổng quan' } });

const props = defineProps({
    isOperations: { type: Boolean, default: false },
    welcome: { type: Object, default: null },
    roleDashboard: { type: Object, default: null },
    kpis: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
});

// Học vụ có 5 thẻ (thêm Lịch hẹn test hôm nay) → 5 cột trên màn rộng.
const kpiCount = computed(() => Object.values(props.kpis ?? {}).filter(Boolean).length);
const card = 'group rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm transition hover:shadow-md';
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

        <RoleWidgets v-if="roleDashboard" :dashboard="roleDashboard" />

        <!-- Thẻ số liệu chính (theo quyền) — vai trò không có dashboard riêng -->
        <div v-if="kpis" :class="['grid grid-cols-1 gap-4 sm:grid-cols-2', kpiCount >= 5 ? 'lg:grid-cols-3 xl:grid-cols-5' : 'lg:grid-cols-4']">
            <Link v-if="kpis.lead" :href="kpis.lead.url" :class="[card, 'hover:border-primary-container']">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant">Leads Tuyển Sinh</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-container/10 text-primary transition group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">pie_chart</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-black text-on-surface">{{ kpis.lead.count }} leads</div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="font-bold text-tertiary">{{ kpis.lead.won }} deals đã chốt</span>
                    <span class="font-bold text-primary transition group-hover:translate-x-1">→ Pipeline</span>
                </div>
            </Link>

            <Link v-if="kpis.testToday" :href="kpis.testToday.url" :class="[card, 'hover:border-info']" data-kpi="test-today">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant">Hẹn test hôm nay</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-info-container text-info transition group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">event</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-black text-on-surface">{{ kpis.testToday.count }} lịch hẹn</div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span :class="['font-bold', kpis.testToday.pending > 0 ? 'text-warning' : 'text-on-surface-variant']">{{ kpis.testToday.pending }} chưa làm bài</span>
                    <span class="font-bold text-info transition group-hover:translate-x-1">→ Danh sách</span>
                </div>
            </Link>

            <Link v-if="kpis.tuition" :href="kpis.tuition.url" :class="[card, 'hover:border-warning']">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant">Thu Học Phí (Thực thu)</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-warning-container text-warning transition group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">payments</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-black text-on-surface">{{ kpis.tuition.paid }}</div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="font-bold text-error">{{ kpis.tuition.overdue }} HV quá hạn</span>
                    <span class="font-bold text-warning transition group-hover:translate-x-1">→ Thu phí</span>
                </div>
            </Link>

            <!-- GV / TA không xem được danh sách học viên → không hiện -->
            <Link v-if="kpis.student" :href="kpis.student.url" :class="[card, 'hover:border-tertiary']">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant">Học Viên Trong Hệ Thống</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-tertiary/10 text-tertiary transition group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">school</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-black text-on-surface">{{ kpis.student.students }} học viên</div>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="font-bold text-tertiary">{{ kpis.student.classes }} lớp đang chạy</span>
                    <span class="font-bold text-tertiary transition group-hover:translate-x-1">→ Hồ sơ</span>
                </div>
            </Link>

            <Link v-if="kpis.payroll" :href="kpis.payroll.url" :class="[card, 'hover:border-info']">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant">Lương &amp; Thu nhập</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-info-container text-info transition group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-black text-on-surface">{{ kpis.payroll.value }}</div>
                <div class="mt-1 flex items-center justify-between gap-2 text-xs">
                    <span class="min-w-0 truncate font-bold text-info" :title="kpis.payroll.title">{{ kpis.payroll.title }}</span>
                    <span class="shrink-0 font-bold text-info transition group-hover:translate-x-1">→ Chi tiết</span>
                </div>
            </Link>
        </div>

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
