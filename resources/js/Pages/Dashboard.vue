<script setup>
/**
 * Tổng quan (thiết kế lại 28/10/2026): mọi vai trò đọc cùng một thứ tự — lời chào, "Cần bạn xử lý" (gộp, quá hạn lên đầu),
 * khối riêng của vai trò, số liệu tháng. overview.kind chọn bố cục:
 *  - admin / manager: 4 số liệu tháng lên đầu, cảnh báo vận hành, so sánh cơ sở (Admin) hoặc lớp học hôm nay (Quản lý).
 *  - academic: hàng chờ duyệt chuyên môn, lớp cần chú ý, Big Test 14 ngày, lịch hẹn + việc của tôi.
 *  - sales / personal: gọi lại hôm nay, phễu khách (Sales), lớp học hôm nay (Học vụ), lịch hẹn 7 ngày, việc của tôi.
 *  - teaching: ca tiếp theo (Check-in / Điểm danh), lịch dạy hôm nay, lương tạm tính, học sinh cần chú ý.
 * Chất lượng giảng dạy theo tháng (TeachingQuality.vue) giữ ở cuối trang.
 */
import { computed } from 'vue';
import AcademicBoard from './Dashboard/AcademicBoard.vue';
import AgendaCard from './Dashboard/AgendaCard.vue';
import AlertsCard from './Dashboard/AlertsCard.vue';
import BranchTable from './Dashboard/BranchTable.vue';
import DailyReportBanner from './Dashboard/DailyReportBanner.vue';
import FunnelCard from './Dashboard/FunnelCard.vue';
import KpiGrid from './Dashboard/KpiGrid.vue';
import MyTasksCard from './Dashboard/MyTasksCard.vue';
import OverviewHeader from './Dashboard/OverviewHeader.vue';
import RevenueWeeks from './Dashboard/RevenueWeeks.vue';
import ShiftHero from './Dashboard/ShiftHero.vue';
import TeachingQuality from './Dashboard/TeachingQuality.vue';
import TeachingSide from './Dashboard/TeachingSide.vue';
import TodaySessions from './Dashboard/TodaySessions.vue';
import TodoCard from './Dashboard/TodoCard.vue';

defineOptions({ layout: { title: 'Tổng quan' } });

const props = defineProps({
    isOperations: { type: Boolean, default: false },
    welcome: { type: Object, default: null },
    roleDashboard: { type: Object, default: null },
    overview: { type: Object, required: true },
    dailyReport: { type: Object, default: null },
    teaching: { type: Object, default: null },
});

const kind = computed(() => props.overview.kind);
const board = computed(() => props.roleDashboard ?? {});
const operations = computed(() => ['admin', 'manager'].includes(kind.value));
// Số liệu tháng của vai trò không phải Admin / Quản lý: thẻ cũ của bảng (hẹn test, việc đến hạn, học phí quá hạn…).
const stats = computed(() => (operations.value ? [] : (board.value.stats ?? [])));
// Báo cáo tháng giáo viên chưa nộp → thêm vào cảnh báo vận hành.
const missingReports = computed(() => {
    const reports = props.teaching?.teacherReports;
    if (!reports?.length) return [];
    const missing = reports.filter((r) => !r.submitted);
    return [{ value: missing.length, tone: missing.length ? 'today' : 'info', title: 'GV chưa nộp báo cáo tháng', meta: missing.length ? missing.slice(0, 3).map((r) => r.teacher).join(' · ') : 'Đã nộp đủ', href: null }];
});
</script>

<template>
    <div class="space-y-lg" :data-overview-kind="kind">
        <OverviewHeader :greeting="overview.greeting">
            <UiButton v-if="welcome?.tasksUrl" variant="secondary" size="sm" icon="checklist" :href="welcome.tasksUrl">{{ welcome.tasksLabel ?? 'Việc của tôi' }}</UiButton>
            <UiButton v-if="welcome?.salaryUrl" variant="secondary" size="sm" icon="payments" :href="welcome.salaryUrl">Lương của tôi</UiButton>
        </OverviewHeader>

        <DailyReportBanner v-if="dailyReport" :daily-report="dailyReport" />

        <!-- Admin / Quản lý cơ sở -->
        <template v-if="operations">
            <KpiGrid :kpis="overview.kpis" />
            <div v-if="kind === 'admin'" class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <TodoCard :todo="overview.todo" class="lg:col-span-2" />
                <AlertsCard :alerts="overview.alerts" :extra="missingReports" />
            </div>
            <template v-else>
                <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                    <TodaySessions :sessions="overview.todaySessions" class="lg:col-span-2" />
                    <AlertsCard :alerts="overview.alerts" :extra="missingReports" />
                </div>
                <div class="grid grid-cols-1 items-start gap-md lg:grid-cols-3">
                    <TodoCard :todo="overview.todo" class="lg:col-span-2" />
                    <RevenueWeeks :weeks="overview.revenueWeeks" />
                </div>
            </template>
            <div v-if="kind === 'admin'" class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <BranchTable :branches="overview.branches" class="lg:col-span-2" />
                <RevenueWeeks :weeks="overview.revenueWeeks" />
            </div>
        </template>

        <!-- Học thuật -->
        <template v-else-if="kind === 'academic'">
            <TodoCard :todo="overview.todo" />
            <AcademicBoard :attention="teaching?.team?.attention ?? []" :big-tests="board.upcomingBigTests ?? []" :month-label="teaching?.monthLabel ?? ''" />
            <div v-if="board.agenda" class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <AgendaCard :agenda="board.agenda" class="lg:col-span-2" />
                <MyTasksCard v-if="board.myTasks" :tasks="board.myTasks" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiStatCard v-for="stat in board.stats" :key="stat.label" :label="stat.label" :value="stat.value" :tone="stat.tone" :hint="stat.hint" />
            </div>
        </template>

        <!-- Giáo viên / trợ giảng -->
        <template v-else-if="kind === 'teaching'">
            <ShiftHero :shifts="overview.shifts" />
            <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <div class="space-y-md lg:col-span-2">
                    <TodoCard :todo="overview.todo" />
                    <MyTasksCard v-if="board.myTasks?.url" :tasks="board.myTasks" />
                </div>
                <TeachingSide :shifts="overview.shifts" />
            </div>
        </template>

        <!-- Sales / Học vụ / vai trò khác -->
        <template v-else>
            <div :class="['grid grid-cols-1 items-start gap-md', overview.funnel ? 'lg:grid-cols-3' : '']">
                <TodoCard :todo="overview.todo" :class="overview.funnel ? 'lg:col-span-2' : ''" />
                <FunnelCard v-if="overview.funnel" :funnel="overview.funnel" />
            </div>
            <div v-if="overview.todaySessions" class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <TodaySessions :sessions="overview.todaySessions" class="lg:col-span-2" />
                <MyTasksCard :tasks="board.myTasks" />
            </div>
            <div v-if="board.agenda || (!overview.todaySessions && board.myTasks?.url)" :class="['grid grid-cols-1 gap-md', board.agenda && !overview.todaySessions ? 'lg:grid-cols-3' : '']">
                <AgendaCard v-if="board.agenda" :agenda="board.agenda" :class="overview.todaySessions ? '' : 'lg:col-span-2'" />
                <MyTasksCard v-if="!overview.todaySessions" :tasks="board.myTasks" />
            </div>
        </template>

        <div v-if="stats.length && !['academic', 'teaching'].includes(kind)" :class="['grid grid-cols-1 gap-md sm:grid-cols-2', stats.length >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3']" data-overview-month-stats>
            <UiStatCard v-for="stat in stats.slice(0, 4)" :key="stat.label" :label="stat.label" :value="stat.value" :tone="stat.tone" :hint="stat.hint" />
        </div>

        <TeachingQuality v-if="teaching" :teaching="teaching" />
    </div>
</template>
