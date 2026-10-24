<script setup>
/**
 * Sửa ngữ liệu dùng chung (đoạn văn / bài đọc) của một nhóm câu: mở từ danh sách → modal; mở thẳng URL → trang đầy đủ.
 * Lưu → mọi câu của ngữ liệu được đánh dấu "Đã sửa" (import lại không ghi đè).
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';

defineOptions({ layout: { title: 'Ngân hàng câu hỏi' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    passage: { type: Object, required: true },
});

const content = ref(props.passage.content);
const description = computed(() => [`Khối ${props.passage.grade}`, props.passage.term_label, props.passage.skill, props.passage.type].join(' · '));
</script>

<template>
    <UiModalFrame :title="`Sửa ngữ liệu #${passage.id}`" :description="description" :action="route('question-bank.passages.update', passage.id)" method="put" :back="route('question-bank.index')" submit-icon="save">
        <div class="space-y-md">
            <UiAlert type="info">Ngữ liệu dùng chung cho {{ passage.questions.length }} câu bên dưới. Viết <code>[x]</code> để gạch chân.</UiAlert>
            <img v-if="passage.image_url" :src="passage.image_url" alt="Ảnh ngữ liệu" class="max-h-48 rounded border border-outline-variant" />

            <UiTextarea name="instruction" label="Yêu cầu" :rows="2" :value="passage.instruction" />
            <UiTextarea v-model="content" name="content" label="Ngữ liệu" required :rows="10" />
            <div class="rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                <p class="mb-xs font-caption text-caption text-on-surface-variant">Xem trước</p>
                <UnderlinedText :text="content" class="block whitespace-pre-line font-body-small text-body-small text-on-surface" />
            </div>

            <div>
                <h3 class="mb-xs font-body-medium text-body-medium font-semibold text-on-surface">Câu hỏi của ngữ liệu</h3>
                <ol class="space-y-xs">
                    <li v-for="q in passage.questions" :key="q.id" class="flex items-start gap-sm font-body-small text-body-small">
                        <span class="font-code text-on-surface-variant">{{ q.position }}.</span>
                        <UnderlinedText :text="q.stem" class="flex-1 text-on-surface" />
                        <UiBadge :dot="false">{{ q.status_label }}</UiBadge>
                    </li>
                </ol>
            </div>
        </div>
    </UiModalFrame>
</template>
