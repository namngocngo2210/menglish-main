<script setup>
/**
 * Bảng tỉ lệ nộp homework 3 giỏ (docs/kho-syllabus.md §10.2): mỗi học sinh × giỏ (giấy / Quizizz / video) + dòng cả lớp;
 * ô = số bài nộp / số bài được giao (tỉ lệ %). Không có bài được giao → "—".
 */
defineProps({ stats: { type: Object, required: true } });

const baskets = [
    ['paper', 'Bài tập giấy'],
    ['quizizz', 'Quizizz'],
    ['video', 'Video'],
    ['total', 'Tổng'],
];
const rate = (cell) => (cell?.rate === null || cell?.rate === undefined ? '—' : `${cell.rate}%`);
const tone = (cell) => (cell?.rate === null || cell?.rate === undefined ? '' : cell.rate >= 80 ? 'text-success' : cell.rate < 50 ? 'text-error' : 'text-warning');
</script>

<template>
    <UiDataTable min-width="640px">
        <template #header>
            <h2 class="font-h3 text-h3 text-on-surface">Tỉ lệ nộp bài <span class="font-body-base text-body-small text-on-surface-variant">· {{ stats.homeworks }} bài tập được giao</span></h2>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Học sinh</th>
                    <th v-for="[key, label] in baskets" :key="key" class="text-right">{{ label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in stats.students" :key="s.id">
                    <td class="font-semibold">{{ s.name }}</td>
                    <td v-for="[key] in baskets" :key="key" class="whitespace-nowrap text-right">
                        <template v-if="key === 'total' ? s.total.assigned : s.baskets[key]?.assigned">
                            <span :class="['font-semibold', tone(key === 'total' ? s.total : s.baskets[key])]">{{ rate(key === 'total' ? s.total : s.baskets[key]) }}</span>
                            <span class="ml-1 text-xs text-on-surface-subtle">{{ (key === 'total' ? s.total : s.baskets[key]).submitted }}/{{ (key === 'total' ? s.total : s.baskets[key]).assigned }}</span>
                        </template>
                        <span v-else class="text-on-surface-subtle">—</span>
                    </td>
                </tr>
                <tr v-if="!stats.students.length">
                    <td :colspan="baskets.length + 1"><UiEmptyState icon="group" title="Lớp chưa có học viên" /></td>
                </tr>
            </tbody>
            <tfoot v-if="stats.students.length">
                <tr class="bg-surface-container-low font-semibold">
                    <td>Cả lớp</td>
                    <td v-for="[key] in baskets" :key="key" class="whitespace-nowrap text-right">
                        <span :class="tone(stats.class[key])">{{ rate(stats.class[key]) }}</span>
                        <span v-if="stats.class[key]?.assigned" class="ml-1 text-xs font-normal text-on-surface-subtle">{{ stats.class[key].submitted }}/{{ stats.class[key].assigned }}</span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </UiDataTable>
</template>
