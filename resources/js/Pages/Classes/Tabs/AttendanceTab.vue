<script setup>
/** Trang lớp · Điểm danh & báo cáo buổi: các buổi đã tới ngày (mới nhất trước), trạng thái điểm danh và báo cáo trực lớp. */
import { formatDate } from '@/lib/format';

defineProps({
    klass: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    canRecordAttendance: { type: Boolean, default: false },
});
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <UiStatCard label="Buổi đã tới ngày" :value="stats.past" icon="event_available" />
            <UiStatCard label="Đã điểm danh" :value="stats.done" tone="success" icon="how_to_reg" />
            <UiStatCard label="Chưa điểm danh" :value="stats.missing" :tone="stats.missing ? 'error' : 'default'" icon="pending_actions" />
            <UiStatCard label="Báo cáo buổi đã nộp" :value="stats.reports" tone="secondary" icon="assignment_turned_in" />
        </div>

        <UiDataTable min-width="760px">
            <template #header>
                <h2 class="text-base font-bold text-on-surface">Điểm danh & báo cáo từng buổi</h2>
                <UiButton v-if="can('work_task.view')" variant="secondary" size="sm" icon="post_add" :href="route('tasks.class-reports.create', { class_id: klass.id })">Nộp báo cáo buổi</UiButton>
            </template>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Buổi</th>
                        <th>Điểm danh</th>
                        <th>Báo cáo trực lớp</th>
                        <th class="text-right" aria-label="Thao tác"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in rows" :key="s.id">
                        <td>
                            <span class="font-code font-bold">{{ formatDate(s.date) }}</span>
                            <span class="block font-code text-xs text-on-surface-variant">{{ s.start }}–{{ s.end }}</span>
                        </td>
                        <td>
                            <UiBadge :color="s.state.color">{{ s.state.label }}</UiBadge>
                            <span v-if="s.attendances_count > 0" class="ml-1 text-xs text-on-surface-variant">{{ s.attendances_count }} HV</span>
                        </td>
                        <td>
                            <template v-if="s.report">
                                <UiBadge :color="s.report.color">{{ s.report.label }}</UiBadge>
                                <span class="ml-1 text-xs text-on-surface-variant">{{ s.report.reporter }}</span>
                            </template>
                            <span v-else-if="!s.cancelled" class="text-xs text-on-surface-subtle">Chưa nộp</span>
                            <span v-else class="text-xs text-on-surface-subtle">—</span>
                        </td>
                        <td class="text-right">
                            <UiButton v-if="canRecordAttendance && !s.cancelled" variant="ghost" size="sm" icon="fact_check" :href="route('teacher.attendance', { classId: klass.id, session: s.id })">{{
                                s.attendances_count > 0 ? 'Xem điểm danh' : 'Điểm danh'
                            }}</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="4"><UiEmptyState icon="event_busy" title="Chưa có buổi học nào tới ngày." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
