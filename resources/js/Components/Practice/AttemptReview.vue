<script setup>
/**
 * Xem lại bài luyện tập (chỉ xem): từng câu kèm ngữ liệu / ảnh, phương án đã chọn và đáp án đúng; câu sai tô đỏ.
 * Dùng chung cho trang kết quả ở cổng học viên và màn báo cáo kết quả luyện tập.
 *   <AttemptReview :items="items" />   items: [{ position, skill, instruction, passage, image_url, stem, options, chosen, answer, is_correct, knowledge }]
 */
import { computed } from 'vue';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';
import { groupPracticeItems } from '@/lib/practiceGroups';

const props = defineProps({ items: { type: Array, default: () => [] } });

const sections = computed(() => groupPracticeItems(props.items));

function optionClass(item, key) {
    if (key === item.answer) return 'border-tertiary bg-tertiary/10 text-on-surface';
    if (key === item.chosen) return 'border-error bg-error/10 text-on-surface';
    return 'border-surface-container-highest text-on-surface-variant';
}
</script>

<template>
    <div class="space-y-5">
        <section v-for="section in sections" :key="section.skill + section.blocks[0].key" class="space-y-3">
            <h3 class="text-sm font-bold uppercase tracking-wide text-primary">{{ section.skill }}</h3>

            <div v-for="block in section.blocks" :key="block.key" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <p v-if="block.instruction" class="text-sm font-semibold text-on-surface"><UnderlinedText :text="block.instruction" /></p>
                <div v-if="block.passage" class="space-y-2 rounded-xl bg-surface-container-low p-3">
                    <img v-if="block.passage.image_url" :src="block.passage.image_url" alt="Hình của ngữ liệu" class="mx-auto max-h-80 rounded-lg" loading="lazy" />
                    <p v-if="block.passage.content" class="whitespace-pre-line text-sm leading-relaxed text-on-surface"><UnderlinedText :text="block.passage.content" /></p>
                </div>

                <div v-for="item in block.items" :key="item.position" :class="['space-y-2 rounded-xl border-l-4 pl-3', item.is_correct ? 'border-tertiary' : 'border-error']">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm text-on-surface">
                            <span class="font-bold">Câu {{ item.position }}.</span>
                            <UnderlinedText v-if="item.stem" :text="item.stem" class="ml-1 whitespace-pre-line" />
                        </p>
                        <UiBadge v-if="item.is_correct" color="success" pill class="shrink-0">Đúng</UiBadge>
                        <UiBadge v-else-if="item.chosen" color="error" pill class="shrink-0">Sai</UiBadge>
                        <UiBadge v-else color="warning" pill class="shrink-0">Chưa trả lời</UiBadge>
                    </div>
                    <img v-if="item.image_url" :src="item.image_url" :alt="`Hình của câu ${item.position}`" class="mx-auto max-h-64 rounded-lg" loading="lazy" />

                    <ul class="grid gap-1.5">
                        <li v-for="(text, key) in item.options" :key="key" :class="['flex items-center gap-2 rounded-lg border px-3 py-2 text-sm', optionClass(item, key)]">
                            <span class="font-bold">{{ key }}.</span>
                            <UnderlinedText :text="text" class="flex-1" />
                            <span v-if="key === item.answer" class="flex shrink-0 items-center gap-0.5 text-xs font-bold text-tertiary">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>Đáp án đúng
                            </span>
                            <span v-else-if="key === item.chosen" class="flex shrink-0 items-center gap-0.5 text-xs font-bold text-error">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">cancel</span>Đã chọn
                            </span>
                        </li>
                    </ul>
                    <p v-if="item.knowledge" class="text-xs text-on-surface-subtle">Kiến thức: {{ item.knowledge }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
