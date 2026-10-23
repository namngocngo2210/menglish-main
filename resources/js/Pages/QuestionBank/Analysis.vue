<script setup>
/**
 * Phân tích câu hỏi (docs/luyen-tap-ngan-hang-cau-hoi.md §7): câu có tỉ lệ sai cao nhất trước, chỉ câu có từ
 * `minAttempts` lượt làm trở lên; mỗi dòng có đáp án đúng, đáp án sai nhiều nhất và link sửa câu (modal). Câu đã sửa
 * chỉ tính lượt bắt đầu sau lần sửa cuối (`edited_at`) — hiện "Tính từ lần sửa …" để biết số liệu đã đặt lại.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { formatDate, formatNumber, formatPercent } from '@/lib/format';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';

defineOptions({ layout: { title: 'Phân tích câu hỏi' } });

const props = defineProps({
    questions: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    minAttempts: { type: Number, default: 10 },
    canManage: { type: Boolean, default: false },
});

const termLabels = computed(() => Object.fromEntries(props.filterOptions.terms.map((t) => [t.value, t.label])));
const rate = (value) => formatPercent(value * 100);
// mở sửa câu ở trang đầy đủ (tab mới / không JS): lưu xong quay về trang phân tích này, giữ bộ lọc
const page = usePage();
const editUrl = (id) => route('question-bank.questions.edit', { question: id, return: page.url });
</script>

<template>
    <UiPageHeader title="Phân tích câu hỏi" :description="`Câu có tỉ lệ làm sai cao nhất (từ ${minAttempts} lượt làm trở lên, tính các lượt đã nộp của mọi học viên). Câu bỏ trống tính là sai. Câu đã sửa chỉ tính các lượt làm sau lần sửa cuối.`" />

    <UiTabs class="mb-lg">
        <UiTab :href="route('question-bank.index')">Câu hỏi</UiTab>
        <UiTab :href="route('question-bank.blueprints.index')">Khung đề</UiTab>
        <UiTab :href="route('question-bank.analysis')" active>Phân tích câu hỏi</UiTab>
    </UiTabs>

    <UiFilterBar>
        <UiSelect name="grade" label="Khối" :options="filterOptions.grades" placeholder="Tất cả khối" />
        <UiSelect name="term" label="Kỳ" :options="filterOptions.terms" placeholder="Tất cả kỳ" />
        <UiSelect name="skill" label="Kỹ năng" :options="filterOptions.skills" placeholder="Tất cả kỹ năng" />
        <UiSelect name="type" label="Dạng bài" :options="filterOptions.types" placeholder="Tất cả dạng bài" />
    </UiFilterBar>

    <UiDataTable min-width="880px">
        <table>
            <thead>
                <tr>
                    <th>Câu hỏi</th>
                    <th class="w-44">Phân loại</th>
                    <th class="w-20 text-right">Lượt làm</th>
                    <th class="w-20 text-right">Sai</th>
                    <th class="w-24 text-right">Tỉ lệ sai</th>
                    <th class="w-28 text-center">Đáp án đúng</th>
                    <th class="w-32 text-center">Sai nhiều nhất</th>
                    <th v-if="canManage" class="w-20 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="q in questions.data" :key="q.question_id" class="align-top" data-question-row>
                    <td><UnderlinedText :text="q.stem" class="block whitespace-pre-line font-body-small text-body-small text-on-surface" /></td>
                    <td class="font-body-small text-body-small text-on-surface-variant">
                        Khối {{ q.grade }} · {{ termLabels[q.term] ?? q.term }}<br />{{ q.skill }} · {{ q.type }}
                        <span v-if="q.edited_at" class="mt-xs block italic" data-edited-since>Tính từ lần sửa {{ formatDate(q.edited_at) }}</span>
                    </td>
                    <td class="text-right tabular-nums">{{ formatNumber(q.attempts) }}</td>
                    <td class="text-right tabular-nums">{{ formatNumber(q.wrong) }}</td>
                    <td class="text-right tabular-nums font-semibold text-error">{{ rate(q.wrong_rate) }}</td>
                    <td class="text-center font-code">{{ q.answer }}</td>
                    <td class="text-center font-code">{{ q.top_wrong_choice ?? '—' }}</td>
                    <td v-if="canManage" class="text-right">
                        <UiButton variant="ghost" icon="edit" :href="editUrl(q.question_id)" modal="2xl" title="Sửa câu hỏi" :aria-label="`Sửa câu #${q.question_id}`" />
                    </td>
                </tr>
                <tr v-if="!questions.data.length">
                    <td :colspan="canManage ? 8 : 7">
                        <UiEmptyState icon="analytics" title="Chưa có câu đủ dữ liệu" :description="`Chưa có câu nào được làm từ ${minAttempts} lượt trở lên với bộ lọc này.`" />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="questions" unit="câu hỏi" /></template>
    </UiDataTable>
</template>
