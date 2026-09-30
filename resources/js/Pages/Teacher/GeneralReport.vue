<script setup>
/**
 * Báo cáo giảng dạy của GV theo tháng: giờ dạy đã duyệt, điểm danh đã chấm, điểm đã nhập, lớp phụ trách, chi tiết chấm công.
 */
import { router } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Báo cáo giảng dạy' } });

const props = defineProps({
    month: { type: Number, required: true },
    year: { type: Number, required: true },
    period: { type: String, required: true },
    stats: { type: Object, required: true },
    myClasses: { type: Array, default: () => [] },
    timesheets: { type: Array, default: () => [] },
});

const pad = (n) => String(n).padStart(2, '0');

function changePeriod(event) {
    const value = event?.target?.value;
    if (value) router.get(route('teacher.general-report'), { period: value });
}
</script>

<template>
    <UiPageHeader :title="'Báo cáo giảng dạy của tôi — T' + month + '/' + year" icon="monitoring" :back="route('teacher.home')">
        <template #actions>
            <form method="GET" class="flex items-center gap-2" @submit.prevent>
                <UiInput type="month" name="period" :value="period" aria-label="Tháng báo cáo" @change="changePeriod" />
            </form>
        </template>
    </UiPageHeader>

    <div class="space-y-4">
        <!-- Thẻ tổng quan -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <UiStatCard label="Tổng giờ dạy tháng" :value="stats.hours" :hint="stats.sessions + ' phiên chấm công'" />
            <UiStatCard label="Giờ đã đối soát" :value="stats.valid_hours" tone="success" :hint="stats.valid_sessions + ' phiên hợp lệ'" />
            <UiStatCard label="Buổi đã điểm danh" :value="stats.attendance_marked" tone="primary" hint="bản ghi điểm danh" />
            <UiStatCard label="Điểm mini test đã nhập" :value="stats.scores_entered" tone="secondary" hint="bản ghi điểm" />
        </div>

        <!-- Lớp đang phụ trách -->
        <UiDataTable class="shadow-sm">
            <template #header>
                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary">school</span>
                    Lớp tôi phụ trách
                </h2>
            </template>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Khóa học</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="cls in myClasses" :key="cls.id">
                        <td class="font-bold">{{ cls.name }} <span class="font-mono text-xs text-on-surface-subtle">{{ cls.code }}</span></td>
                        <td>{{ cls.course ?? '—' }}</td>
                        <td>
                            <UiBadge v-if="cls.is_main" color="primary" pill>GV chính</UiBadge>
                            <UiBadge v-if="cls.is_foreign" color="secondary" pill>GVNN</UiBadge>
                        </td>
                        <td class="text-on-surface-variant">{{ cls.status }}</td>
                    </tr>
                    <tr v-if="!myClasses.length">
                        <td colspan="4"><UiEmptyState icon="school" title="Chưa được phân công lớp nào." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <!-- Chi tiết chấm công theo trạng thái -->
        <UiDataTable class="shadow-sm">
            <template #header>
                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary">schedule</span>
                    Chi tiết chấm công tháng {{ pad(month) }}/{{ year }}
                </h2>
            </template>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Ngày dạy</th>
                        <th>Lớp</th>
                        <th>Loại</th>
                        <th class="text-right">Giờ</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ts in timesheets" :key="ts.id">
                        <td class="font-mono text-on-surface-variant">{{ ts.date ?? '—' }}</td>
                        <td class="font-semibold">{{ ts.class_name ?? '—' }}</td>
                        <td>{{ ts.type }}</td>
                        <td class="text-right font-mono font-bold">{{ ts.hours }}</td>
                        <td>
                            <UiBadge :color="ts.status === 'valid' ? 'success' : ts.status === 'invalid' ? 'error' : 'warning'" pill>
                                {{ ts.status === 'valid' ? 'Đã duyệt' : ts.status === 'invalid' ? 'Bị từ chối' : 'Chờ duyệt' }}
                            </UiBadge>
                        </td>
                    </tr>
                    <tr v-if="!timesheets.length">
                        <td colspan="5"><UiEmptyState icon="schedule" title="Chưa có phiên chấm công nào trong tháng." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    <TeacherBottomNav />
</template>
