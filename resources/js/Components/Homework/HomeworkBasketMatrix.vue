<script setup>
/**
 * Tab "Bài tập theo syllabus" ở màn Bài nộp của lớp (docs/kho-syllabus.md §10.2): tỉ lệ nộp theo giỏ × học sinh và cả lớp,
 * bài nộp theo bài tập × học sinh × giỏ; GV chấm điểm + nhận xét (tùy chọn) như các loại bài nộp khác.
 */
import GetForm from '@/Components/Syllabus/GetForm.vue';
import HomeworkRateTable from '@/Components/Homework/HomeworkRateTable.vue';
import { route } from '@/lib/route';

defineProps({
    classId: { type: Number, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    baskets: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    homeworks: { type: Array, default: () => [] },
});

const inputClass = 'rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1 text-on-surface focus:border-primary-container focus:ring-primary-container/50';
</script>

<template>
    <div class="space-y-md">
        <GetForm :action="route('portal.teacher.submissions', { classId })" class="flex flex-wrap items-end gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <input type="hidden" name="type" value="basket" />
            <UiInput type="date" name="from" label="Từ ngày" :value="from" />
            <UiInput type="date" name="to" label="Đến ngày" :value="to" />
            <UiButton type="submit" variant="secondary" icon="filter_alt" data-testid="homework-filter-submit">Lọc</UiButton>
        </GetForm>

        <HomeworkRateTable :stats="stats" />

        <UiEmptyState v-if="!homeworks.length" icon="assignment" title="Chưa giao bài tập nào trong khoảng ngày này" />
        <UiDataTable v-for="hw in homeworks" :key="hw.id" min-width="640px">
            <template #header>
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">{{ hw.title }}</h2>
                    <p class="text-xs text-on-surface-variant">Giao {{ formatDate(hw.created_at) }} · Hạn {{ hw.due_date ? formatDate(hw.due_date) : 'không giới hạn' }}</p>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Học sinh</th>
                        <th v-for="b in baskets.filter((x) => hw.baskets.includes(x.key))" :key="b.key">{{ b.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in hw.rows" :key="row.id">
                        <td class="font-semibold">{{ row.student }}</td>
                        <td v-for="key in hw.baskets" :key="key" class="text-xs">
                            <template v-if="row.baskets[key]">
                                <a v-if="row.baskets[key].file_url" :href="row.baskets[key].file_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">attachment</span>{{ row.baskets[key].original_name ?? 'Bài nộp' }}
                                </a>
                                <span v-else class="font-semibold text-on-surface">Đã làm</span>
                                <p v-if="row.baskets[key].note" class="italic text-on-surface-variant">"{{ row.baskets[key].note }}"</p>
                                <template v-if="row.baskets[key].status === 'reviewed'">
                                    <UiBadge color="success" class="mt-1">{{ row.baskets[key].score ? 'Đã chấm: ' + row.baskets[key].score : 'Đã xem' }}</UiBadge>
                                    <p v-if="row.baskets[key].feedback" class="mt-1 text-on-surface-variant">{{ row.baskets[key].feedback }}</p>
                                </template>
                                <UiForm v-else :action="route('teacher.homework-submissions.review', row.baskets[key].id)" method="post" class="mt-1 flex flex-wrap items-center gap-1">
                                    <input type="text" name="score" maxlength="20" placeholder="Điểm" aria-label="Điểm" :class="['w-16', inputClass]" />
                                    <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" aria-label="Nhận xét" :class="['w-32', inputClass]" />
                                    <UiButton type="submit" size="sm" variant="secondary" icon="done">Chấm</UiButton>
                                </UiForm>
                            </template>
                            <span v-else class="text-error">Chưa nộp</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
