<script setup>
/**
 * Chi tiết một buổi học dạng bảng (cột nhãn | cột nội dung), chia nhóm theo form syllabus mẫu.
 * Trường trống hiện "Chưa cập nhật" mờ; trên điện thoại nhãn nằm trên nội dung. PPT đã tải lên (lesson.slides) mở trang trình
 * chiếu trong app, thay cho link PPT cũ.
 */
const props = defineProps({
    lesson: { type: Object, required: true },
});

const groups = [
    ['Mục tiêu & nội dung', [['objectives', 'Mục tiêu'], ['content', 'Content'], ['output_product', 'Sản phẩm đầu ra']]],
    ['Tiến trình buổi học', [['procedure', 'Quy trình tiết học'], ['activity_1', 'Hoạt động gợi ý 1'], ['activity_2', 'Hoạt động gợi ý 2']]],
    ['Bài tập về nhà', [['homework_guide', 'Bài tập giấy'], ['homework_quizizz', 'Bài tập Quizizz'], ['homework_video', 'Bài tập Video']]],
    ['Kiến thức trọng tâm', [['vocabulary_focus', 'Từ vựng'], ['grammar_focus', 'Ngữ pháp']]],
    ['Khác', [['note', 'Ghi chú']]],
];
// [trường link, nhãn, biểu tượng, loại PPT tải lên thay cho link]
const links = [
    ['ppt_url', 'PPT', 'slideshow', 'ppt'],
    ['ppt_foreign_url', 'PPT GVNN', 'slideshow', 'ppt_foreign'],
    ['quizizz_url', 'Quizizz', 'quiz'],
    ['test_url', 'Mini test / Kiểm tra từ', 'assignment'],
];
const hasValue = (field) => String(props.lesson[field] ?? '').trim() !== '';
const linkUrl = ([field, , , slide]) => (slide && props.lesson.slides?.[slide]?.url) || (hasValue(field) ? props.lesson[field] : null);
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-surface-container-highest">
        <section v-for="[title, fields] in groups" :key="title">
            <h4 class="border-b border-surface-container-highest bg-surface-container-high/60 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wider text-on-surface-subtle">{{ title }}</h4>
            <dl class="divide-y divide-surface-container-highest border-b border-surface-container-highest">
                <div v-for="[field, label] in fields" :key="field" class="grid grid-cols-1 sm:grid-cols-[11rem_1fr]">
                    <dt class="px-3 pt-2 font-semibold text-on-surface sm:bg-surface-container-low/60 sm:py-2">{{ label }}</dt>
                    <dd v-if="hasValue(field)" class="whitespace-pre-line break-words px-3 pb-2 pt-0.5 leading-relaxed text-on-surface-variant sm:py-2">{{ lesson[field] }}</dd>
                    <dd v-else class="px-3 pb-2 pt-0.5 italic text-on-surface-subtle sm:py-2">Chưa cập nhật</dd>
                </div>
            </dl>
        </section>
        <div class="grid grid-cols-1 sm:grid-cols-[11rem_1fr]">
            <div class="px-3 pt-2 font-semibold text-on-surface sm:bg-surface-container-low/60 sm:py-2">Link học liệu</div>
            <div class="px-3 pb-2 pt-1 sm:py-2">
                <div v-if="links.some(linkUrl)" class="flex flex-wrap gap-2">
                    <template v-for="link in links" :key="link[0]">
                        <a v-if="linkUrl(link)" :href="linkUrl(link)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-container/10 px-2.5 py-1 font-semibold text-primary hover:bg-primary-container/20">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ link[2] }}</span>{{ link[1] }}
                        </a>
                    </template>
                </div>
                <span v-else class="italic text-on-surface-subtle">Chưa có link</span>
            </div>
        </div>
    </div>
</template>
