<script setup>
/**
 * Cổng GV — Bài giảng hôm nay (docs/kho-syllabus.md §8.3): chỉ buổi syllabus hiện tại của lớp có buổi học hôm nay (content,
 * quy trình, hoạt động, homework 3 giỏ, link, PPT trình chiếu trong app, tài liệu buổi). Bấm "Đã dạy" thì tiến độ chặng mới chạy; buổi đã qua chưa
 * đánh dấu hiện nhắc để đánh dấu bù.
 */
import { computed } from 'vue';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Bài giảng hôm nay' } });

const props = defineProps({
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    assignment: { type: Object, default: null },
    session: { type: Object, default: null },
    lesson: { type: Object, default: null },
    taught_today: { type: Boolean, default: false },
    finished: { type: Boolean, default: false },
    unmarked: { type: Array, default: () => [] },
    can_mark: { type: Boolean, default: false },
    can_mark_past: { type: Boolean, default: false },
    mark_url: { type: String, default: null },
    confirmed: { type: Boolean, default: true },
    confirm_url: { type: String, default: null },
    confirm_due: { type: String, default: null },
});

const textFields = [
    ['content', 'Content (nội dung từng tiết)'],
    ['procedure', 'Quy trình tiết học'],
    ['activity_1', 'Hoạt động gợi ý 1'],
    ['activity_2', 'Hoạt động gợi ý 2'],
    ['output_product', 'Sản phẩm đầu ra'],
    ['note', 'Ghi chú'],
];
const baskets = [
    ['homework_guide', 'Bài tập giấy', 'description'],
    ['homework_quizizz', 'Bài tập Quizizz', 'quiz'],
    ['homework_video', 'Bài tập Video', 'videocam'],
];
// [trường link, nhãn, biểu tượng, loại PPT tải lên — có file thì mở trang trình chiếu trong app thay cho link]
const links = [
    ['ppt_url', 'Trình chiếu PPT', 'co_present', 'ppt'],
    ['ppt_foreign_url', 'Trình chiếu PPT GVNN', 'co_present', 'ppt_foreign'],
    ['quizizz_url', 'Link Quizizz', 'quiz'],
    ['test_url', 'Link Mini test / Kiểm tra từ', 'assignment'],
];
const linkUrl = ([field, , , slide]) => (slide && props.lesson?.slides?.[slide]?.url) || props.lesson?.data[field] || null;
const hasLinks = computed(() => props.lesson && links.some(linkUrl));
</script>

<template>
    <UiPageHeader title="Bài giảng hôm nay" icon="co_present">
        <template v-if="assignment" #meta>{{ assignment.stage_label }} · {{ assignment.curriculum }} · đã dạy {{ assignment.taught }} / {{ assignment.total }} buổi</template>
        <template #actions>
            <GetForm v-if="classes.length > 1" :action="route('teacher.lesson-today')">
                <UiSelect name="class" inline-label="Lớp" :value="classId" :options="classes" />
            </GetForm>
        </template>
    </UiPageHeader>

    <div class="space-y-md pb-24 md:pb-0">
        <UiEmptyState v-if="!classes.length" icon="event_busy" title="Bạn không có lớp nào đang học chặng" description="Học thuật giao chặng cho lớp; bài giảng của buổi học hôm nay sẽ hiện ở đây." />

        <!-- Chặng chưa XÁC NHẬN: chưa bấm "Đã dạy" được; quá hạn (1 giờ trước buổi đầu chặng) bị lập biên bản. -->
        <UiAlert v-if="assignment && !confirmed" type="error">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>
                    Chặng {{ assignment.stage_label }} chưa được XÁC NHẬN — chưa đánh dấu Đã dạy được.
                    <template v-if="confirm_due"> Hạn xác nhận: <strong>{{ confirm_due }}</strong> (quá hạn bị lập biên bản).</template>
                </span>
                <UiButton v-if="confirm_url" size="sm" icon="menu_book" :href="confirm_url">ĐỌC &amp; XÁC NHẬN</UiButton>
            </div>
        </UiAlert>

        <UiAlert v-for="s in unmarked" :key="s.id" type="warning">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>Buổi {{ formatDate(s.date) }} chưa đánh dấu Đã dạy — tiến độ chặng chưa chạy.</span>
                <UiForm v-if="can_mark_past && mark_url" :action="mark_url" method="post" class="inline">
                    <input type="hidden" name="class_session_id" :value="s.id" />
                    <UiButton type="submit" size="sm" variant="secondary" icon="task_alt">Đánh dấu Đã dạy</UiButton>
                </UiForm>
            </div>
        </UiAlert>

        <template v-if="classes.length">
            <UiAlert v-if="!session" type="info">Hôm nay lớp không có buổi học — GV chỉ xem bài giảng của buổi học hôm nay.</UiAlert>
            <UiAlert v-else-if="finished && !lesson" type="success">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>Lớp đã dạy hết các buổi của chặng — ôn tập và tổ chức Big Test cuối chặng.</span>
                    <UiForm v-if="can_mark" :action="mark_url" method="post" class="inline">
                        <input type="hidden" name="class_session_id" :value="session.id" />
                        <UiButton type="submit" size="sm" icon="task_alt">Đã dạy (buổi ôn / Big Test)</UiButton>
                    </UiForm>
                </div>
            </UiAlert>
            <UiAlert v-else-if="taught_today && !lesson" type="success">Đã đánh dấu Đã dạy (buổi giãn tiến độ) cho buổi hôm nay.</UiAlert>

            <section v-if="lesson" class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-surface-container-highest pb-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">{{ session.time }} · {{ lesson.unit }}</p>
                        <h2 class="font-h3 text-h3 text-on-surface">Buổi {{ lesson.session_no }}: {{ lesson.data.title }}</h2>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <UiBadge v-if="lesson.data.lesson_type !== 'lesson'" color="warning">{{ lesson.type_label }}</UiBadge>
                            <UiBadge v-if="lesson.milestone_label" color="info">{{ lesson.milestone_label }}</UiBadge>
                            <UiBadge v-if="lesson.overridden">Chỉnh sửa riêng cho lớp</UiBadge>
                            <UiBadge v-if="taught_today" color="success">Đã dạy</UiBadge>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <UiButton v-if="lesson.score_url" icon="grading" :href="lesson.score_url">Nhập điểm</UiButton>
                        <UiButton variant="secondary" icon="assignment" :href="lesson.homework_url">Giao bài tập theo syllabus</UiButton>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    <div v-for="[field, label] in textFields" :key="field" v-show="lesson.data[field]">
                        <dt class="text-xs font-bold uppercase text-on-surface-subtle">{{ label }}</dt>
                        <dd class="mt-1 whitespace-pre-line text-on-surface">{{ lesson.data[field] }}</dd>
                    </div>
                </dl>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div v-for="[field, label, icon] in baskets" :key="field" class="rounded-xl border border-surface-container-highest p-3 text-sm">
                        <p class="flex items-center gap-1 text-xs font-bold uppercase text-on-surface-subtle"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ icon }}</span>{{ label }}</p>
                        <p class="mt-1 whitespace-pre-line text-on-surface">{{ lesson.data[field] || '—' }}</p>
                    </div>
                </div>

                <p v-if="hasLinks" class="flex flex-wrap gap-3 text-sm">
                    <template v-for="link in links" :key="link[0]">
                        <a v-if="linkUrl(link)" :href="linkUrl(link)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ link[2] }}</span>{{ link[1] }}</a>
                    </template>
                </p>

                <div v-if="lesson.documents.length" class="space-y-1">
                    <p class="text-xs font-bold uppercase text-on-surface-subtle">Tài liệu buổi</p>
                    <a v-for="d in lesson.documents" :key="d.id" :href="d.url" target="_blank" rel="noopener" class="flex items-center gap-2 text-sm text-primary hover:underline">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ d.icon }}</span>{{ d.title }}
                    </a>
                </div>

                <UiForm v-if="can_mark" :action="mark_url" method="post" class="space-y-3 border-t border-surface-container-highest pt-4">
                    <input type="hidden" name="class_session_id" :value="session.id" />
                    <UiTextarea name="pace_note" label="Ghi chú tiến độ (nếu dạy chậm / nhanh)" :rows="2" placeholder="VD: Lớp chậm, chưa xong phần Speaking — cần thêm 15 phút buổi sau." />
                    <label v-if="assignment.extra_left > 0" class="flex items-center gap-2 text-sm text-on-surface">
                        <input type="checkbox" name="use_extra" value="1" class="rounded border-outline-variant text-primary focus:ring-primary-container" />
                        Dạy chưa xong — dùng 1 buổi giãn đã duyệt (còn {{ assignment.extra_left }} buổi), buổi syllabus giữ nguyên
                    </label>
                    <div class="flex justify-end">
                        <UiButton type="submit" icon="task_alt">Đã dạy</UiButton>
                    </div>
                </UiForm>
            </section>
        </template>
    </div>

    <TeacherBottomNav />
</template>
