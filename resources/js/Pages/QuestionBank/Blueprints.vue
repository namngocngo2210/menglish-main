<script setup>
/**
 * Khung đề luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §5.3): lưới khối × kỳ; mỗi ô là thời gian + các dòng
 * {kỹ năng, dạng bài, số câu} kèm số câu đã duyệt hiện có (đỏ khi thiếu). Sửa khung mở modal.
 */
import { computed } from 'vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Khung đề luyện tập' } });

const props = defineProps({
    cells: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const grades = computed(() => [...new Set(props.cells.map((cell) => cell.grade))]);
const cellsOf = (grade) => props.cells.filter((cell) => cell.grade === grade);
const shortCount = (cell) => cell.items.filter((item) => item.short).length;
</script>

<template>
    <UiPageHeader title="Khung đề luyện tập" description="Số câu mỗi dạng bài trong đề luyện tập theo khối × kỳ. Chỉ câu đã duyệt được bốc vào đề." />

    <UiTabs class="mb-lg">
        <UiTab :href="route('question-bank.index')">Câu hỏi</UiTab>
        <UiTab :href="route('question-bank.blueprints.index')" active>Khung đề</UiTab>
        <UiTab :href="route('question-bank.analysis')">Phân tích câu hỏi</UiTab>
    </UiTabs>

    <section v-for="grade in grades" :key="grade" class="mb-xl" :aria-label="`Khối ${grade}`">
        <h2 class="mb-sm font-h3 text-h3 text-on-surface">Khối {{ grade }}</h2>
        <div class="grid grid-cols-1 gap-md xl:grid-cols-2">
            <article v-for="cell in cellsOf(grade)" :key="cell.term" class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm" :data-cell="`${cell.grade}-${cell.term}`">
                <header class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container px-md py-sm">
                    <div>
                        <h3 class="font-body-medium text-body-medium font-semibold text-on-surface">{{ cell.term_label }}</h3>
                        <p class="font-caption text-caption text-on-surface-variant">{{ cell.duration_minutes }} phút · {{ cell.total }} câu</p>
                    </div>
                    <div class="flex items-center gap-xs">
                        <UiBadge v-if="shortCount(cell)" color="danger">Thiếu {{ shortCount(cell) }} dạng</UiBadge>
                        <UiButton v-if="canManage" size="sm" variant="ghost" icon="edit" :href="route('question-bank.blueprints.edit', [cell.grade, cell.term])" modal="2xl" :aria-label="`Sửa khung đề khối ${cell.grade} ${cell.term_label}`">Sửa</UiButton>
                    </div>
                </header>
                <table class="w-full font-body-small text-body-small">
                    <thead>
                        <tr class="text-left text-on-surface-variant">
                            <th class="px-md py-xs font-medium">Kỹ năng / Dạng bài</th>
                            <th class="w-16 px-sm py-xs text-right font-medium">Cần</th>
                            <th class="w-20 px-md py-xs text-right font-medium">Đã duyệt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, i) in cell.items" :key="i" class="border-t border-surface-container">
                            <td class="px-md py-xs">
                                <span class="text-on-surface-variant">{{ item.skill }} ·</span> <span class="text-on-surface">{{ item.type }}</span>
                            </td>
                            <td class="px-sm py-xs text-right font-code">{{ item.count }}</td>
                            <td :class="['px-md py-xs text-right font-code', item.short ? 'font-semibold text-error' : 'text-on-surface']" :data-short="item.short ? 'true' : null">{{ item.available }}</td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </div>
    </section>
</template>
