<script setup>
/**
 * Tổng quan: bảng điều hành Admin / Quản lý / Học thuật (RoleWidgets.vue); vai trò khác: lời chào + "Việc của bạn"
 * (MyWork.vue — lịch hẹn 7 ngày tới, việc cần xử lý, việc của tôi); chất lượng giảng dạy theo tháng (TeachingQuality.vue).
 * Lưới phân hệ (phím tắt) cuối trang đã bỏ (04/10/2026).
 */
import { Link } from '@inertiajs/vue3';
import MyWork from './Dashboard/MyWork.vue';
import RoleWidgets from './Dashboard/RoleWidgets.vue';
import TeachingQuality from './Dashboard/TeachingQuality.vue';

defineOptions({ layout: { title: 'Tổng quan' } });

defineProps({
    isOperations: { type: Boolean, default: false },
    welcome: { type: Object, default: null },
    roleDashboard: { type: Object, default: null },
    dailyReport: { type: Object, default: null },
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
                <UiButton v-if="welcome.tasksUrl" variant="secondary" size="sm" icon="checklist" :href="welcome.tasksUrl">{{ welcome.tasksLabel ?? 'Việc của tôi' }}</UiButton>
            </div>
        </div>

        <!-- Học vụ / Học thuật: báo cáo ngày còn thiếu / cần bổ sung / hôm nay (rà soát 16/10/2026). -->
        <section
            v-if="dailyReport"
            :class="[
                'flex flex-col gap-3 rounded-2xl border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between',
                dailyReport.missing.length || dailyReport.returned.length ? 'border-warning/40 bg-warning-container/40' : 'border-surface-container-highest bg-surface-container-lowest',
            ]"
            data-dashboard-daily-report
        >
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['material-symbols-outlined mt-0.5', dailyReport.missing.length || dailyReport.returned.length ? 'text-warning' : 'text-tertiary']" aria-hidden="true">
                    {{ dailyReport.missing.length || dailyReport.returned.length ? 'assignment_late' : 'assignment_turned_in' }}
                </span>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-bold text-on-surface">Báo cáo ngày</p>
                    <p v-if="dailyReport.missing.length" class="text-on-surface">
                        Chưa nộp:
                        <template v-for="(d, i) in dailyReport.missing.slice(0, 5)" :key="d.href">
                            <Link :href="d.href" class="font-semibold text-primary underline">{{ d.label }}</Link><template v-if="i < Math.min(dailyReport.missing.length, 5) - 1">, </template>
                        </template>
                        <template v-if="dailyReport.missing.length > 5"> và {{ dailyReport.missing.length - 5 }} ngày khác</template>
                        <span v-if="!dailyReport.missing[0].overdue" class="text-on-surface-variant"> · hạn {{ dailyReport.missing[0].deadline }}</span>
                    </p>
                    <p v-for="r in dailyReport.returned" :key="r.href" class="text-on-surface">
                        Admin yêu cầu bổ sung báo cáo <Link :href="r.href" class="font-semibold text-primary underline">{{ r.label }}</Link><template v-if="r.note">: {{ r.note }}</template>
                    </p>
                    <p v-if="!dailyReport.missing.length && !dailyReport.returned.length" class="text-on-surface-variant">Đã nộp đủ các ngày trước.</p>
                    <p class="text-on-surface-variant">Hôm nay: {{ dailyReport.todaySubmitted ? 'đã nộp' : 'chưa nộp (hạn 09:00 sáng mai)' }}</p>
                </div>
            </div>
            <UiButton :href="dailyReport.missing.length ? dailyReport.missing[0].href : dailyReport.todayHref" icon="edit_note" size="sm">
                {{ dailyReport.missing.length ? 'Nộp báo cáo ' + dailyReport.missing[0].label : 'Viết báo cáo hôm nay' }}
            </UiButton>
        </section>

        <!-- Giáo viên / trợ giảng: chất lượng giảng dạy lên đầu; vai trò khác: sau bảng điều hành / việc của bạn. -->
        <TeachingQuality v-if="teaching?.mine" :teaching="teaching" />
        <MyWork v-if="roleDashboard?.type === 'personal'" :dashboard="roleDashboard" />
        <RoleWidgets v-else-if="roleDashboard" :dashboard="roleDashboard" />
        <TeachingQuality v-if="teaching && !teaching.mine" :teaching="teaching" />
    </div>
</template>
