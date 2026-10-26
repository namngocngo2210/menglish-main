<script setup>
/**
 * Một đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): toàn bộ câu theo thứ tự đề kèm đáp án, ngữ liệu hiện một lần
 * trên nhóm câu; sửa tên / thời gian; Mở cho học viên / Ẩn / Về nháp; xoá khi chưa có lượt làm. Sửa câu / ngữ liệu mở
 * form của Ngân hàng câu hỏi (modal), lưu xong quay về đề này.
 */
import { computed } from 'vue';
import { route } from '@/lib/route';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';

defineOptions({ layout: { title: 'Đề cố định' } });

const props = defineProps({
    exam: { type: Object, required: true },
    questions: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const STATUS_COLORS = { draft: 'warning', published: 'success', hidden: 'neutral' };
const returnUrl = computed(() => `/question-bank/exams/${props.exam.id}`);

// câu liền nhau cùng ngữ liệu / cùng yêu cầu → một khối, ngữ liệu / yêu cầu hiện một lần
const blocks = computed(() => {
    const out = [];
    for (const q of props.questions) {
        const key = `${q.passage?.id ?? ''}|${q.instruction ?? ''}`;
        const last = out[out.length - 1];
        if (last && last.key === key) {
            last.questions.push(q);
        } else {
            out.push({ key, instruction: q.instruction, passage: q.passage, questions: [q] });
        }
    }
    return out;
});
</script>

<template>
    <UiPageHeader :title="`Khối ${exam.grade} · ${exam.label}`" :description="`${exam.title} · ${exam.term_label} · ${exam.question_count} câu · ${exam.duration_minutes} phút`">
        <template #actions>
            <UiButton variant="secondary" icon="arrow_back" :href="route('question-bank.exams.index')">Danh sách đề</UiButton>
            <template v-if="canManage">
                <UiForm v-if="exam.status !== 'published'" :action="route('question-bank.exams.status', exam.id)" method="post" :confirm="`Mở ${exam.label} cho học viên khối ${exam.grade}?`" confirm-label="Mở đề">
                    <input type="hidden" name="action" value="publish" />
                    <UiButton type="submit" icon="visibility">Mở cho học viên</UiButton>
                </UiForm>
                <UiForm v-else :action="route('question-bank.exams.status', exam.id)" method="post" :confirm="`Ẩn ${exam.label}? Học viên không bắt đầu được lượt mới.`" confirm-label="Ẩn đề">
                    <input type="hidden" name="action" value="hide" />
                    <UiButton type="submit" variant="secondary" icon="visibility_off">Ẩn đề</UiButton>
                </UiForm>
                <UiForm v-if="exam.status === 'hidden' && !exam.attempts" :action="route('question-bank.exams.status', exam.id)" method="post">
                    <input type="hidden" name="action" value="draft" />
                    <UiButton type="submit" variant="secondary" icon="undo">Về nháp</UiButton>
                </UiForm>
                <UiForm v-if="!exam.attempts" :action="route('question-bank.exams.destroy', exam.id)" method="delete" :confirm="`Xoá ${exam.label} và toàn bộ câu hỏi của đề?`" confirm-label="Xoá" danger>
                    <UiButton type="submit" variant="danger-text" icon="delete">Xoá</UiButton>
                </UiForm>
            </template>
        </template>
    </UiPageHeader>

    <div class="mb-lg flex flex-wrap items-center gap-sm">
        <UiBadge :color="STATUS_COLORS[exam.status] ?? 'neutral'">{{ exam.status_label }}</UiBadge>
        <span class="font-body-small text-body-small text-on-surface-variant">{{ exam.attempts }} lượt làm<template v-if="exam.source_file"> · Nguồn: {{ exam.source_file }}</template></span>
    </div>

    <UiAlert v-if="exam.warnings.length" type="warning" title="Cảnh báo khi import" class="mb-lg">
        <ul class="list-disc space-y-0.5 pl-md">
            <li v-for="(line, i) in exam.warnings" :key="i">{{ line }}</li>
        </ul>
    </UiAlert>

    <section v-if="canManage" class="mb-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
        <UiForm :action="route('question-bank.exams.update', exam.id)" method="put" class="grid grid-cols-1 items-end gap-md sm:grid-cols-[1fr_10rem_auto]">
            <UiInput name="title" label="Tên đề" required :value="exam.title" />
            <UiInput name="duration_minutes" type="number" label="Thời gian (phút)" required :value="exam.duration_minutes" min="5" max="240" />
            <UiButton type="submit" icon="save" variant="secondary">Lưu</UiButton>
        </UiForm>
    </section>

    <div class="space-y-md">
        <article v-for="block in blocks" :key="block.key + block.questions[0].id" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
            <p v-if="block.instruction" class="font-body-small text-body-small font-semibold italic text-on-surface-variant">{{ block.instruction }}</p>
            <div v-if="block.passage" class="rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                <UnderlinedText :text="block.passage.content" class="block whitespace-pre-line font-body-small text-body-small text-on-surface" />
                <UiButton v-if="canManage" size="sm" variant="ghost" icon="edit" :href="route('question-bank.passages.edit', { passage: block.passage.id, return: returnUrl })" modal="2xl" class="mt-xs">Sửa ngữ liệu</UiButton>
            </div>
            <div v-for="q in block.questions" :key="q.id" class="flex items-start gap-sm border-t border-surface-container pt-sm first:border-t-0 first:pt-0" :data-question="q.position">
                <span class="w-14 shrink-0 font-code font-semibold text-on-surface">Câu {{ q.position }}</span>
                <div class="min-w-0 flex-1 space-y-xs">
                    <UnderlinedText v-if="q.stem" :text="q.stem" class="block whitespace-pre-line font-body-medium text-body-medium text-on-surface" />
                    <ol class="grid grid-cols-1 gap-xs sm:grid-cols-2">
                        <li
                            v-for="(text, key) in q.options"
                            :key="key"
                            :class="['flex items-start gap-xs rounded-md px-xs py-0.5 font-body-small text-body-small', key === q.answer ? 'bg-tertiary/10 font-semibold text-tertiary' : 'text-on-surface']"
                        >
                            <span class="font-code">{{ key }}.</span>
                            <UnderlinedText :text="text" />
                            <span v-if="key === q.answer" class="material-symbols-outlined ml-auto text-[16px]" aria-label="Đáp án đúng">check</span>
                        </li>
                    </ol>
                    <p class="font-caption text-caption text-on-surface-variant">
                        {{ q.skill }} · {{ q.type }}
                        <UiBadge v-if="q.edited" color="info" :dot="false" class="ml-xs">Đã sửa</UiBadge>
                    </p>
                </div>
                <UiButton v-if="canManage" variant="ghost" icon="edit" :href="route('question-bank.questions.edit', { question: q.id, return: returnUrl })" modal="2xl" title="Sửa câu hỏi" :aria-label="`Sửa câu ${q.position}`" />
            </div>
        </article>
        <UiEmptyState v-if="!questions.length" icon="quiz" title="Đề chưa có câu hỏi" description="Import lại file đề khi đề còn ở trạng thái Nháp." />
    </div>
</template>
