<script setup>
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Tổng quan lớp' } });
const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    groups: { type: Object, required: true },
    classes: { type: Object, required: true },
});

const summaries = [
    { key: 'branches', filter: 'branch_id', label: 'Theo cơ sở' },
    { key: 'programs', filter: 'program', label: 'Theo chương trình' },
    { key: 'levels', filter: 'level', label: 'Theo trình độ' },
];

function filterBy(key, value) {
    const filters = { ...props.filters };
    if (String(filters[key] ?? '') === String(value)) delete filters[key];
    else filters[key] = value;
    router.get(route('academic.class-overview'), filters, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Tổng quan lớp" icon="school" description="Lớp đang học, chờ lịch và sắp khai giảng trong phạm vi được phân quyền." />
    <div class="space-y-6">
        <div class="grid gap-4 lg:grid-cols-3">
            <section v-for="summary in summaries" :key="summary.key" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4">
                <h2 class="mb-3 font-bold text-on-surface">{{ summary.label }}</h2>
                <div class="flex flex-wrap gap-2">
                    <button v-for="group in groups[summary.key]" :key="group.value" type="button"
                        class="rounded-lg border px-3 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                        :class="String(filters[summary.filter] ?? '') === String(group.value) ? 'border-primary bg-primary/10 text-primary' : 'border-surface-container-highest text-on-surface'"
                        :aria-pressed="String(filters[summary.filter] ?? '') === String(group.value)"
                        @click="filterBy(summary.filter, group.value)">
                        {{ group.label }} <span class="font-bold">{{ group.count }}</span>
                    </button>
                    <p v-if="!groups[summary.key].length" class="text-sm text-on-surface-variant">Chưa có dữ liệu.</p>
                </div>
            </section>
        </div>
        <div v-if="Object.values(filters).some(value => value !== null && value !== '')" class="flex flex-wrap items-center gap-3 text-sm text-on-surface-variant">
            <span>Bộ lọc: {{ [groups.branches.find(group => String(group.value) === String(filters.branch_id))?.label, filters.program, filters.level].filter(Boolean).join(' · ') || 'Không có lớp phù hợp' }}</span>
            <UiButton variant="secondary" :href="route('academic.class-overview')">Bỏ lọc</UiButton>
        </div>
        <UiDataTable min-width="1100px">
            <table>
                <thead>
                    <tr><th>Lớp</th><th>Cơ sở / Chương trình / Trình độ</th><th>Giáo viên</th><th>Tiến độ</th><th>Big Test</th><th>Sĩ số / Học viên</th><th>Dự giờ</th></tr>
                </thead>
                <tbody>
                    <tr v-for="classroom in classes.data" :key="classroom.id">
                        <td><div class="font-semibold">{{ classroom.name }}</div><UiCode>{{ classroom.code }}</UiCode></td>
                        <td>{{ classroom.branch || '—' }}<div class="text-sm text-on-surface-variant">{{ classroom.program || '—' }} · {{ classroom.level || '—' }}</div></td>
                        <td>{{ classroom.teacher || 'Chưa phân công' }}</td>
                        <td>{{ classroom.progress.stage || 'Chưa mở chặng' }}<div class="text-sm text-on-surface-variant">{{ classroom.progress.unit || '—' }}</div></td>
                        <td>
                            <div>Dự kiến: {{ classroom.expected_big_test_date ? formatDate(classroom.expected_big_test_date) : 'Chưa có lịch' }}</div>
                            <div class="text-sm text-on-surface-variant">Đã xếp: {{ classroom.scheduled_big_test_date ? formatDate(classroom.scheduled_big_test_date) : 'Chưa có lịch' }}</div>
                        </td>
                        <td>
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                <span>{{ classroom.enrolled }} học viên</span>
                                <UiBadge v-if="classroom.enrollment_badge" :color="classroom.enrollment_badge === 'Đủ sĩ số' ? 'success' : 'warning'">{{ classroom.enrollment_badge }}</UiBadge>
                            </div>
                            <details v-if="classroom.students.length">
                                <summary class="cursor-pointer text-primary">Danh sách học viên</summary>
                                <ul class="mt-2 space-y-1 text-sm">
                                    <li v-for="student in classroom.students" :key="student.id">{{ student.name }} · {{ student.code }}</li>
                                </ul>
                                <p v-if="classroom.enrolled > classroom.students.length" class="mt-2 text-xs text-on-surface-variant">Hiển thị {{ classroom.students.length }} / {{ classroom.enrolled }} học viên.</p>
                            </details>
                        </td>
                        <td>
                            <template v-if="classroom.observation">
                                <UiBadge :color="classroom.observation.observed ? 'success' : 'neutral'">{{ classroom.observation.observed ? 'Đã dự giờ' : 'Chưa dự giờ' }}</UiBadge>
                                <div class="mt-1 text-sm">Tháng {{ classroom.observation.month }}</div>
                                <div v-if="classroom.observation.observed_on" class="text-sm text-on-surface-variant">{{ formatDate(classroom.observation.observed_on) }}</div>
                            </template>
                            <span v-else class="text-on-surface-variant">Chưa có dữ liệu</span>
                        </td>
                    </tr>
                    <tr v-if="!classes.data.length"><td colspan="7" class="py-8 text-center text-on-surface-variant">Không có lớp phù hợp.</td></tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="classes" :options="[]" unit="lớp" /></template>
        </UiDataTable>
    </div>
</template>
