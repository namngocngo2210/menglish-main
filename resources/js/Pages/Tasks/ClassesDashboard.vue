<script setup>
/**
 * Lịch học các lớp: buổi học theo ngày (điểm danh, trợ giảng làm việc) / ma trận khung giờ theo tuần.
 * Dữ liệu lấy từ buổi học thật (class_sessions); nút điểm danh theo cửa sổ 24h tính ở server.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Lịch học các lớp' } });

const props = defineProps({
    tab: { type: String, default: 'day' },
    branches: { type: Array, default: () => [] },
    branchId: { type: Number, default: null },
    branchName: { type: String, default: null },
    date: { type: String, required: true },
    week: { type: String, required: true },
    isToday: { type: Boolean, default: false },
    noBranch: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    teachers: { type: Array, default: () => [] },
    dayStats: { type: Object, required: true },
    dayRows: { type: Array, default: () => [] },
    dayClassCount: { type: Number, default: 0 },
    assistantsToday: { type: Array, default: () => [] },
    weekRange: { type: String, default: '' },
    weekDays: { type: Array, default: () => [] },
    weekRows: { type: Array, default: () => [] },
});

const more = ref(!!(props.filters.attendance || props.filters.teacher_id));
const attendanceOptions = [
    { value: 'done', label: 'Đã điểm danh' },
    { value: 'missing', label: 'Chưa điểm danh' },
    { value: 'upcoming', label: 'Chưa diễn ra' },
    { value: 'cancelled', label: 'Hủy / nghỉ lễ' },
];
const tabUrl = (tab) => route('tasks.classes-dashboard', tab === 'day' ? { tab, date: props.date, branch_id: props.branchId } : { tab, week: props.week, branch_id: props.branchId });
</script>

<template>
    <UiPageHeader title="Lịch học các lớp" description="Buổi học của mọi lớp theo ngày / tuần: điểm danh và chấm công giảng viên">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="urlWith({ export: 1 })" native title="Xuất Excel đúng dữ liệu đang xem">Xuất báo cáo</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="noBranch" type="warning" class="mb-lg" data-testid="no-branch-alert">Tài khoản chưa gán chi nhánh, liên hệ Quản trị viên.</UiAlert>

    <UiTabs class="mb-lg">
        <UiTab icon="today" :href="tabUrl('day')" :active="tab === 'day'">Theo ngày</UiTab>
        <UiTab icon="calendar_view_week" :href="tabUrl('week')" :active="tab === 'week'">Theo tuần</UiTab>
    </UiTabs>

    <template v-if="tab === 'day'">
        <!-- ─── THEO NGÀY ─── -->
        <UiFilterBar :search="false" :action="route('tasks.classes-dashboard')">
            <input type="hidden" name="tab" value="day" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="branches" :value="branchId" placeholder="Tất cả chi nhánh" />
            <UiDate name="date" label="Chọn ngày" :value="date" />
            <div><UiButton variant="ghost" icon="tune" :aria-expanded="more ? 'true' : 'false'" @click="more = !more">Lọc thêm</UiButton></div>
            <div v-show="more" class="contents">
                <UiSelect name="teacher_id" label="Giáo viên" :options="teachers" :value="filters.teacher_id" placeholder="Tất cả giáo viên" />
                <UiSelect name="attendance" label="Điểm danh" :options="attendanceOptions" :value="filters.attendance" placeholder="Tất cả trạng thái" />
            </div>
        </UiFilterBar>

        <div class="mb-lg grid grid-cols-2 gap-md lg:grid-cols-4">
            <UiStatCard label="Buổi học trong ngày" :value="dayStats.total" icon="event" />
            <UiStatCard label="Đã điểm danh" :value="dayStats.done" tone="success" icon="how_to_reg" />
            <UiStatCard label="Chưa điểm danh" :value="dayStats.missing" tone="error" icon="pending_actions" />
            <UiStatCard label="Hủy / nghỉ lễ" :value="dayStats.cancelled" tone="warning" icon="event_busy" />
        </div>

        <div class="grid grid-cols-1 gap-lg xl:grid-cols-4">
            <div class="xl:col-span-3">
                <UiDataTable min-width="920px">
                    <template #header>
                        <h3 class="font-h3 text-h3 text-on-surface">Buổi học ngày {{ formatDate(date) }}</h3>
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ branchName ?? 'Tất cả chi nhánh' }}</span>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Tên lớp</th>
                                <th>Khung giờ</th>
                                <th>Phòng học</th>
                                <th>GV chính</th>
                                <th>GVNN</th>
                                <th>Trợ giảng</th>
                                <th class="text-center">Sĩ số</th>
                                <th class="text-center">Điểm danh</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in dayRows" :key="s.id" :data-session-id="s.id">
                                <td>
                                    <div class="font-semibold text-on-surface">{{ s.class_name }}</div>
                                    <div class="flex flex-wrap items-center gap-xs font-caption text-caption text-on-surface-variant">
                                        <span class="font-code">{{ s.class_code }}</span>
                                        <UiBadge v-if="s.type === 'makeup'" color="warning">Học bù</UiBadge>
                                        <UiBadge v-else-if="s.type === 'support'" color="secondary">Phụ đạo</UiBadge>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap font-code">{{ s.time }}</td>
                                <td class="whitespace-nowrap">{{ s.room }}</td>
                                <td class="whitespace-nowrap">{{ s.teacher }}</td>
                                <td class="whitespace-nowrap">{{ s.foreign_teacher }}</td>
                                <td class="whitespace-nowrap">{{ s.assistant }}</td>
                                <td class="whitespace-nowrap text-center font-code">{{ s.seat }}</td>
                                <td class="text-center">
                                    <UiBadge :color="s.state.color" pill>{{ s.state.label }}{{ s.state.key === 'done' ? ` (${s.state.count})` : '' }}</UiBadge>
                                    <div v-if="s.holiday" class="mt-xs font-caption text-caption text-on-surface-variant">{{ s.holiday }}</div>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <!-- Ngoài khung ±24h quanh giờ bắt đầu (GV) / chưa tới giờ học → nút Điểm danh khóa kèm lý do. -->
                                    <div v-if="s.action.kind === 'locked'" class="inline-flex flex-col items-end gap-xs">
                                        <UiButton size="sm" variant="secondary" icon="how_to_reg" disabled>Điểm danh</UiButton>
                                        <span class="max-w-[180px] whitespace-normal text-right font-caption text-caption text-on-surface-variant">{{ s.action.message || 'Ngoài khung ±24h so với giờ bắt đầu buổi học' }}</span>
                                    </div>
                                    <UiButton v-else-if="s.action.kind === 'link'" size="sm" :variant="s.action.primary ? 'primary' : 'secondary'" icon="how_to_reg" :href="s.action.href" :title="s.action.title">{{ s.action.label }}</UiButton>
                                    <span v-else-if="s.action.kind === 'makeup'" class="font-caption text-caption text-on-surface-variant">Bù ngày {{ s.action.date }}</span>
                                    <span v-else class="font-caption text-caption text-on-surface-variant">—</span>
                                </td>
                            </tr>
                            <tr v-if="!dayRows.length">
                                <td colspan="9">
                                    <UiEmptyState
                                        icon="event_available"
                                        title="Không có buổi học nào"
                                        :description="`Không có buổi học nào ${filters.attendance || filters.teacher_id ? 'khớp bộ lọc' : 'trong ngày đã chọn'}${branchName ? ' tại ' + branchName : ''}. Lịch học được sinh từ màn TKB.`"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <template #footer>
                        <div class="px-md py-sm font-body-small text-body-small text-on-surface-variant">Hiển thị {{ dayRows.length }} buổi học của {{ dayClassCount }} lớp học</div>
                    </template>
                </UiDataTable>
            </div>

            <!-- Trợ giảng làm việc trong ngày: việc giao theo ca (nguồn chính) + buổi còn gán trợ giảng cố định (dữ liệu cũ) -->
            <aside class="rounded-xl border border-outline-variant bg-surface-container-low p-md">
                <div class="mb-md flex items-center gap-sm border-b border-outline-variant pb-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">support_agent</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Trợ giảng làm việc {{ isToday ? 'hôm nay' : 'ngày ' + formatDate(date, 'd/m') }}</h3>
                </div>
                <ul class="space-y-sm">
                    <li v-for="duty in assistantsToday" :key="duty.name" class="flex items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest p-sm">
                        <UiAvatar :name="duty.name" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate font-body-medium text-body-medium text-on-surface">{{ duty.name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ duty.detail }}</p>
                        </div>
                    </li>
                    <li v-if="!assistantsToday.length" class="py-md text-center font-body-small text-body-small text-on-surface-variant">Chưa giao việc cho trợ giảng nào trong ngày.</li>
                </ul>
                <template v-if="can('work_task.assign')">
                    <UiButton variant="secondary" class="mt-md w-full" :href="route('portal.ta-tasks', { date })">Xem tất cả trợ giảng</UiButton>
                    <UiButton variant="ghost" icon="add" class="mt-xs w-full" :href="route('tasks.ta-assign')" modal="4xl">Giao việc cho trợ giảng</UiButton>
                </template>
            </aside>
        </div>
    </template>

    <template v-else>
        <!-- ─── THEO TUẦN (ma trận khung giờ) ─── -->
        <UiFilterBar :search="false" :action="route('tasks.classes-dashboard')">
            <input type="hidden" name="tab" value="week" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="branches" :value="branchId" placeholder="Tất cả chi nhánh" />
            <UiInput type="week" name="week" label="Chọn tuần" :value="week" />
        </UiFilterBar>

        <div class="mb-md flex flex-wrap items-center gap-md font-caption text-caption text-on-surface-variant">
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-info/30 bg-info-container"></span> Chính khóa (màu theo khóa học)</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-warning/30 bg-warning-container"></span> Học bù</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-accent/30 bg-accent-container"></span> Phụ đạo</span>
            <span class="flex items-center gap-xs"><span class="h-3 w-3 rounded border border-outline-variant bg-surface-container-low"></span> Đã hủy / nghỉ lễ</span>
        </div>

        <div v-if="!weekRows.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
            <UiEmptyState icon="calendar_view_week" title="Tuần này chưa có buổi học" :description="`Tuần ${weekRange} chưa có buổi học nào${branchName ? ' tại ' + branchName : ''}.`" />
        </div>
        <UiDataTable v-else min-width="980px">
            <table class="table-fixed">
                <thead>
                    <tr>
                        <th class="w-[120px] text-center">Khung giờ</th>
                        <th v-for="day in weekDays" :key="day.iso" :class="['text-center', day.today ? 'text-primary' : '']">
                            {{ day.label }}
                            <span class="block font-caption text-caption normal-case text-on-surface-variant">{{ day.date }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="align-top">
                    <tr v-for="row in weekRows" :key="row.slot">
                        <td class="text-center font-code font-semibold">{{ row.slot }}</td>
                        <td v-for="(cell, i) in row.cells" :key="i" class="!px-xs">
                            <div class="space-y-xs">
                                <Link v-for="s in cell" :key="s.id" :href="s.href" :class="['block rounded-lg border p-xs transition hover:shadow-md', s.tone]" :title="s.title">
                                    <p class="truncate text-[12px] font-semibold">{{ s.code }}</p>
                                    <p class="truncate text-xs opacity-80">{{ s.room }}</p>
                                    <p v-if="s.note" class="text-xs font-semibold no-underline">{{ s.note }}</p>
                                </Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </template>
</template>
