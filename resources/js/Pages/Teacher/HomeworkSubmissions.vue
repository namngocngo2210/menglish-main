<script setup>
/**
 * Cổng GV — Bài nộp homework 3 giỏ (docs/kho-syllabus.md §10.2): bài nộp theo bài tập × học sinh × giỏ, bấm "Đã xem";
 * tỉ lệ nộp theo giỏ × học sinh và cả lớp trong khoảng ngày.
 */
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import HomeworkRateTable from '@/Components/Homework/HomeworkRateTable.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Bài nộp homework' } });

defineProps({
    classroom: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    baskets: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    homeworks: { type: Array, default: () => [] },
});
</script>

<template>
    <UiPageHeader :title="`Bài nộp homework — ${classroom.name}`" icon="assignment_turned_in" :back="route('teacher.homework', classroom.id)" back-label="Giao bài tập">
        <template #actions>
            <GetForm :action="route('teacher.homework-submissions', classroom.id)" class="flex flex-wrap items-end gap-2">
                <UiInput type="date" name="from" label="Từ ngày" :value="from" />
                <UiInput type="date" name="to" label="Đến ngày" :value="to" />
            </GetForm>
        </template>
    </UiPageHeader>

    <div class="space-y-md pb-24 md:pb-0">
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
                    <tr v-for="row in hw.rows" :key="row.student">
                        <td class="font-semibold">{{ row.student }}</td>
                        <td v-for="key in hw.baskets" :key="key" class="text-xs">
                            <template v-if="row.baskets[key]">
                                <a v-if="row.baskets[key].file_url" :href="row.baskets[key].file_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">attachment</span>{{ row.baskets[key].original_name ?? 'Bài nộp' }}
                                </a>
                                <span v-else class="font-semibold text-on-surface">Đã làm</span>
                                <p v-if="row.baskets[key].note" class="italic text-on-surface-variant">"{{ row.baskets[key].note }}"</p>
                                <UiBadge v-if="row.baskets[key].status === 'reviewed'" color="success" class="mt-1">Đã xem</UiBadge>
                                <UiForm v-else :action="route('teacher.homework-submissions.review', row.baskets[key].id)" method="post" class="mt-1">
                                    <UiButton type="submit" size="sm" variant="secondary" icon="done">Đã xem</UiButton>
                                </UiForm>
                            </template>
                            <span v-else class="text-error">Chưa nộp</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <TeacherBottomNav />
</template>
