<script setup>
/**
 * Sửa một câu trong ngân hàng: mở từ danh sách → modal; mở thẳng URL → trang đầy đủ (UiModalFrame).
 * Lưu → câu được đánh dấu "Đã sửa" (import lại không ghi đè). Ngữ liệu dùng chung sửa ở modal riêng (PassageForm).
 * `returnUrl` (từ query `return`, server đã kiểm tra) → trang đầy đủ lưu xong / quay lại về màn đã mở.
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';

defineOptions({ layout: { title: 'Ngân hàng câu hỏi' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    question: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    // trang đầy đủ mở từ màn khác của ngân hàng (vd. Phân tích câu hỏi): lưu / quay lại về màn đó
    returnUrl: { type: String, default: null },
});

const OPTION_KEYS = ['A', 'B', 'C', 'D'];
const answers = OPTION_KEYS.map((key) => ({ value: key, label: key }));
const stem = ref(props.question.stem);
const description = computed(() => [props.question.source_key, `Khối ${props.question.grade}`, props.question.term_label, props.question.skill, props.question.type].join(' · '));
</script>

<template>
    <UiModalFrame title="Sửa câu hỏi" :description="description" :action="route('question-bank.questions.update', question.id)" method="put" :back="returnUrl || route('question-bank.index')" submit-icon="save">
        <div class="space-y-md">
            <input v-if="returnUrl" type="hidden" name="return" :value="returnUrl" />
            <div v-if="question.passage" class="rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                <div class="flex items-center justify-between gap-sm">
                    <span class="font-body-small text-body-small font-semibold text-on-surface">Ngữ liệu #{{ question.passage.id }} (dùng chung)</span>
                    <UiButton size="sm" variant="ghost" icon="edit" :href="route('question-bank.passages.edit', returnUrl ? { passage: question.passage.id, return: returnUrl } : question.passage.id)" modal="2xl">Sửa ngữ liệu</UiButton>
                </div>
                <UnderlinedText :text="question.passage.content" class="mt-xs block max-h-40 overflow-y-auto whitespace-pre-line font-body-small text-body-small text-on-surface-variant" />
            </div>
            <img v-if="question.image_url" :src="question.image_url" alt="Ảnh câu hỏi" class="max-h-48 rounded border border-outline-variant" />

            <UiAlert type="info">Viết <code>[x]</code> để gạch chân, ví dụ <code>c[oa]st</code> → c<u>oa</u>st.</UiAlert>

            <UiTextarea name="instruction" label="Yêu cầu" :rows="2" :value="question.instruction" />
            <UiTextarea v-model="stem" name="stem" label="Câu hỏi" :required="!question.in_exam" :rows="3" />
            <p class="font-body-small text-body-small text-on-surface-variant">Xem trước: <UnderlinedText :text="stem" class="whitespace-pre-line text-on-surface" /></p>

            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiInput v-for="key in OPTION_KEYS" :key="key" :name="`options[${key}]`" :label="`Phương án ${key}`" required :value="question.options?.[key]" />
            </div>

            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiSelect name="answer" label="Đáp án đúng" required :options="answers" :value="question.answer" />
                <!-- câu của đề cố định: mở / ẩn theo cả đề -->
                <UiSelect v-if="!question.in_exam" name="status" label="Trạng thái" required :options="statuses" :value="question.status" />
                <UiInput name="knowledge" label="Kiến thức" :value="question.knowledge" />
            </div>
        </div>
    </UiModalFrame>
</template>
