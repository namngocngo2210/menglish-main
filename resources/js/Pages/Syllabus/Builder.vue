<script setup>
/**
 * Soạn syllabus theo chặng: Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi.
 * Ô soạn thảo chặng / unit / buổi mở theo tham số URL (new_stage, edit_stage, new_unit, edit_unit, new_lesson, edit_lesson).
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Soạn syllabus theo chặng' } });

const props = defineProps({
    curriculums: { type: Array, default: () => [] },
    curriculum: { type: Object, default: null },
    stages: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    unitOptions: { type: Array, default: () => [] },
    editor: { type: Object, default: null },
    courses: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    newCode: { type: String, default: '' },
    canManage: { type: Boolean, default: false },
});

const creating = ref(false);
const stageOptions = computed(() => props.stages.map((s) => ({ value: s.id, label: s.label })));
const lessonCount = (stage) => stage.units.reduce((sum, u) => sum + u.lessons.length, 0);
const builderUrl = (params = {}) => route('syllabus.builder', { curriculum: props.curriculum.id, ...params });
const pad2 = (n) => String(n).padStart(2, '0');

function openCurriculum(event) {
    router.get(route('syllabus.builder'), { curriculum: event.target.value });
}

// Ô soạn chặng: xem trước ảnh / tài liệu tổng quan.
const link = ref(props.editor?.type === 'stage' ? (props.editor.model?.overview_link ?? '') : '');
const preview = ref(link.value);
const isImage = (u) => /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(u || '');

const lessonFields = [
    ['objectives', 'Mục tiêu:', 'Chưa cập nhật'],
    ['content', 'Nội dung chính:', 'Chưa cập nhật'],
    ['homework_guide', 'Bài tập về nhà:', 'Chưa cập nhật'],
    ['vocabulary_focus', 'Từ vựng:', '—'],
    ['grammar_focus', 'Ngữ pháp:', '—'],
];
</script>

<template>
    <UiPageHeader title="Soạn syllabus theo chặng" description="Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi." :back="route('syllabus.documents')">
        <template #meta>Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi. Số buổi đánh liên tục trong cả giáo trình.</template>
        <template #actions>
            <UiButton variant="secondary" icon="assignment_ind" :href="route('syllabus.assignments')">Giao chặng</UiButton>
            <UiButton v-if="canManage" icon="library_add" @click="creating = true">Tạo giáo trình mới</UiButton>
        </template>
    </UiPageHeader>

    <UiModal v-if="canManage" :show="creating" title="Tạo giáo trình mới" max-width="xl" @close="creating = false">
        <UiForm id="new-curriculum-form" :action="route('syllabus.curriculums.store')" method="post" class="grid grid-cols-1 gap-4 p-md md:grid-cols-2" @success="creating = false">
            <UiInput name="code" label="Mã giáo trình" required :value="newCode" />
            <UiInput name="version" label="Phiên bản" required value="v1.0" />
            <UiInput name="title" label="Tên giáo trình" required class="md:col-span-2" placeholder="IELTS Foundation - Level 1" />
            <UiSelect name="course_id" label="Khóa học áp dụng" placeholder="-- Chọn khóa học --" value="" :options="courses" />
            <UiInput name="stage_name" label="Tên chặng đầu tiên" placeholder="Chặng 1: Xây dựng nền tảng" hint="Để trống sẽ đặt là “Chặng 1”." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-curriculum-form" icon="save">Tạo giáo trình</UiButton>
        </template>
    </UiModal>

    <div class="space-y-6 pb-10">
        <!-- Chọn giáo trình đang soạn -->
        <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <form :action="route('syllabus.builder')" method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent>
                <div class="flex-1">
                    <UiSelect name="curriculum" label="Giáo trình đang soạn" :value="curriculum?.id ?? ''" :options="curriculums" @change="openCurriculum" />
                </div>
            </form>
        </section>

        <UiEmptyState v-if="!curriculum" icon="library_books" title="Chưa có giáo trình nào" description="Tạo giáo trình đầu tiên để bắt đầu soạn chặng, unit và buổi học.">
            <UiButton v-if="canManage" icon="library_add" @click="creating = true">Tạo giáo trình mới</UiButton>
        </UiEmptyState>

        <template v-else>
            <!-- Thông tin giáo trình + Trình độ áp dụng -->
            <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                <div class="mb-5 flex items-center justify-between gap-2 border-b border-surface-container-highest pb-3">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">info</span>
                        <h2 class="text-sm font-bold text-on-surface">Thông tin giáo trình</h2>
                        <UiBadge color="primary">{{ stages.length }} chặng · {{ totals.units }} unit · {{ totals.lessons }} buổi</UiBadge>
                    </div>
                    <UiForm v-if="canManage" :action="route('syllabus.curriculums.destroy', curriculum.id)" method="delete" :confirm="`Xóa giáo trình ${curriculum.title} cùng toàn bộ chặng, unit, buổi học và tài liệu?`" confirm-label="Xóa" danger>
                        <UiButton type="submit" variant="danger-text" size="sm" icon="delete">Xóa giáo trình</UiButton>
                    </UiForm>
                </div>

                <UiForm :key="curriculum.id" :action="route('syllabus.curriculums.update', curriculum.id)" method="put" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <input type="hidden" name="levels_submitted" value="1" />
                    <UiInput name="title" label="Tên giáo trình" required :value="curriculum.title" :disabled="!canManage" />
                    <UiInput name="code" label="Mã giáo trình" required :value="curriculum.code" :disabled="!canManage" />
                    <UiInput name="version" label="Phiên bản" required :value="curriculum.version" :disabled="!canManage" />
                    <UiSelect name="course_id" label="Khóa học áp dụng" placeholder="-- Không gắn khóa --" :value="curriculum.course_id ?? ''" :options="courses" :disabled="!canManage" />
                    <div class="md:col-span-2">
                        <UiField label="Trình độ áp dụng" hint="Lớp thuộc trình độ được chọn sẽ mặc định học giáo trình này khi mở chặng.">
                            <div class="flex flex-wrap gap-2">
                                <label v-for="level in levels" :key="level.id" :class="['inline-flex items-center gap-1.5 rounded-lg border border-outline-variant px-2.5 py-1.5 text-xs', level.other ? 'text-on-surface-subtle' : 'text-on-surface-variant']">
                                    <input type="checkbox" name="level_ids[]" :value="level.id" class="rounded border-outline-variant text-primary focus:ring-primary-container" :checked="curriculum.level_ids.includes(level.id)" :disabled="!canManage" />
                                    {{ level.name }} <span class="font-mono text-xs text-on-surface-subtle">{{ level.code }}</span>
                                    <span v-if="level.other" class="text-xs" title="Đang gắn giáo trình khác — chọn sẽ chuyển sang giáo trình này">(đang dùng GT khác)</span>
                                </label>
                                <span v-if="!levels.length" class="text-xs text-on-surface-subtle">Chưa có trình độ nào — tạo ở màn Cấu hình trình độ.</span>
                            </div>
                        </UiField>
                    </div>
                    <div class="md:col-span-2">
                        <UiTextarea name="description" label="Mô tả" :rows="2" :value="curriculum.description" :disabled="!canManage" />
                    </div>
                    <div v-if="canManage" class="flex justify-end md:col-span-2">
                        <UiButton type="submit" icon="save">Lưu thông tin giáo trình</UiButton>
                    </div>
                </UiForm>
            </section>

            <!-- Ô soạn thảo chặng / unit / buổi -->
            <section v-if="editor" id="editor" class="relative overflow-hidden rounded-2xl border border-primary-container/40 bg-surface-container-lowest p-6 shadow-sm">
                <div class="absolute bottom-0 left-0 top-0 w-1 bg-primary-container"></div>
                <template v-if="editor.type === 'stage'">
                    <div class="mb-lg flex flex-wrap items-center gap-sm">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">info</span>
                        <h2 class="font-h3 text-h3">Thông tin chung chặng học</h2>
                        <UiBadge color="primary">{{ editor.model ? `Sửa ${editor.model.label}` : `Thêm chặng mới (Chặng ${totals.next_position})` }}</UiBadge>
                    </div>
                    <UiForm :action="editor.model ? route('syllabus.stages.update', editor.model.id) : route('syllabus.stages.store')" :method="editor.model ? 'put' : 'post'" class="grid grid-cols-1 gap-lg md:grid-cols-2">
                        <input v-if="!editor.model" type="hidden" name="curriculum_id" :value="curriculum.id" />
                        <UiInput name="name" label="Tên chặng học" required :value="editor.model?.name" placeholder="Ví dụ: Chặng 1: Xây dựng nền tảng" />
                        <!-- A6 Q4: chặng chỉ tự mở khi Big Test chặng trước được duyệt & gửi PH — không cho chọn "mở theo tuần / thủ công". -->
                        <UiSelect id="stage_unlock_policy" label="Chính sách mở khóa" hint="Mỗi lớp 1 chặng mở; Học thuật chỉ đóng/chuyển chặng tay khi có ngoại lệ." disabled>
                            <option selected>Hoàn thành Big Test chặng trước (duyệt &amp; gửi PH) mới được mở</option>
                        </UiSelect>
                        <div class="space-y-sm md:col-span-2">
                            <UiField label="Link ảnh/tài liệu tổng quan chặng (overview_link)" name="overview_link">
                                <div class="flex gap-md">
                                    <input v-model="link" type="url" name="overview_link" placeholder="https://example.com/image-syllabus.jpg" class="min-w-0 flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50" />
                                    <UiButton variant="secondary" icon="visibility" @click="preview = link">Xem thử</UiButton>
                                </div>
                            </UiField>
                            <div class="relative flex h-64 w-full flex-col items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low">
                                <img v-if="preview && isImage(preview)" :src="preview" alt="Ảnh tổng quan chặng" class="absolute inset-0 h-full w-full bg-surface-container-lowest object-contain" />
                                <a v-else-if="preview" :href="preview" target="_blank" rel="noopener" class="inline-flex items-center gap-xs font-body-medium text-primary hover:underline">
                                    <span class="material-symbols-outlined">open_in_new</span>Mở tài liệu tổng quan chặng
                                </a>
                                <div v-else class="flex flex-col items-center px-lg text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined mb-xs text-[48px]">image</span>
                                    <p class="font-body-base font-medium">Khu vực hiển thị preview ảnh mục lục tổng quan</p>
                                    <p class="font-caption text-caption">Nhập link bên trên để hiển thị hình ảnh</p>
                                </div>
                            </div>
                        </div>
                        <div class="md:col-span-2"><UiTextarea name="description" label="Mục tiêu / đầu ra của chặng" :rows="2" :value="editor.model?.description" /></div>
                        <UiInput name="big_test_title" label="Big Test cuối chặng" :value="editor.model?.big_test_title" placeholder="Big Test chặng 2 — 4 kỹ năng" />
                        <UiTextarea name="big_test_note" label="Ghi chú Big Test (dạng đề, thời lượng, đầu ra)" :rows="2" :value="editor.model?.big_test_note" />
                        <!-- Thanh hành động dính đáy như mockup (không có "tự động lưu nháp": chỉ lưu khi bấm nút). -->
                        <div class="sticky bottom-0 z-10 -mx-6 -mb-6 mt-md flex items-center justify-end gap-md border-t border-outline-variant bg-surface-container-lowest px-6 py-md shadow-[0_-4px_24px_rgba(0,0,0,0.06)] md:col-span-2">
                            <UiButton variant="secondary" :href="builderUrl()">Hủy</UiButton>
                            <UiButton type="submit" icon="save">{{ editor.model ? 'Lưu chặng học' : 'Thêm chặng học' }}</UiButton>
                        </div>
                    </UiForm>
                </template>

                <template v-else-if="editor.type === 'unit'">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-on-surface">
                        <span class="material-symbols-outlined text-primary">{{ editor.model ? 'edit' : 'add_circle' }}</span>
                        {{ editor.model ? `Sửa Unit ${editor.model.unit_number}: ${editor.model.title}` : `Thêm Unit vào ${editor.parent?.label ?? ''}` }}
                    </h2>
                    <UiForm :action="editor.model ? route('syllabus.units.update', editor.model.id) : route('syllabus.units.store')" :method="editor.model ? 'put' : 'post'" class="space-y-4">
                        <input v-if="!editor.model" type="hidden" name="curriculum_id" :value="curriculum.id" />
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                            <UiInput type="number" name="unit_number" label="Unit số" required min="1" :value="editor.model?.unit_number ?? totals.next_unit_number" />
                            <div class="sm:col-span-3"><UiInput name="title" label="Tên Unit" required :value="editor.model?.title" placeholder="Unit 3: Environment & Climate Change" /></div>
                        </div>
                        <UiSelect name="stage_id" label="Thuộc chặng" required :value="editor.parent?.id ?? ''" :options="stageOptions" />
                        <UiTextarea name="objectives" label="Mô tả / mục tiêu Unit" :rows="2" :value="editor.model?.objectives" />
                        <div class="flex justify-end gap-2 border-t border-surface-container-highest pt-3">
                            <UiButton variant="secondary" :href="builderUrl()">Hủy</UiButton>
                            <UiButton type="submit" icon="save">{{ editor.model ? 'Lưu Unit' : 'Thêm Unit' }}</UiButton>
                        </div>
                    </UiForm>
                </template>

                <template v-else>
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-on-surface">
                        <span class="material-symbols-outlined text-primary">{{ editor.model ? 'edit' : 'add_circle' }}</span>
                        {{ editor.model ? `Sửa Buổi ${editor.model.session_no}: ${editor.model.title}` : `Thêm buổi vào Unit ${editor.parent?.unit_number ?? ''}: ${editor.parent?.title ?? ''}` }}
                    </h2>
                    <UiForm :action="editor.model ? route('syllabus.lessons.update', editor.model.id) : route('syllabus.lessons.store')" :method="editor.model ? 'put' : 'post'" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                            <UiInput type="number" name="session_no" label="Buổi số" required min="1" hint="Đánh liên tục trong cả giáo trình" :value="editor.model?.session_no ?? totals.next_session_no" />
                            <div class="sm:col-span-3"><UiInput name="title" label="Tiêu đề buổi học" required :value="editor.model?.title" placeholder="Buổi 01: Introduction to IELTS & Greetings" /></div>
                        </div>
                        <UiSelect name="unit_id" label="Thuộc Unit" required :value="editor.parent?.id ?? ''" :options="unitOptions" />
                        <UiTextarea name="objectives" label="Mục tiêu buổi học (Target)" :rows="3" :value="editor.model?.objectives" placeholder="Người học cần đạt được điều gì sau buổi này..." />
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <UiTextarea name="content" label="Nội dung bài học chính" :rows="7" :value="editor.model?.content" placeholder="Nhập nội dung chi tiết (hoạt động trên lớp)..." />
                            <UiTextarea name="homework_guide" label="Bài tập về nhà (Homework)" :rows="7" :value="editor.model?.homework_guide" placeholder="Ghi chú bài tập hoặc link bài tập..." />
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <UiTextarea name="vocabulary_focus" label="Trọng tâm từ vựng" :rows="2" :value="editor.model?.vocabulary_focus" />
                            <UiTextarea name="grammar_focus" label="Trọng tâm ngữ pháp" :rows="2" :value="editor.model?.grammar_focus" />
                        </div>
                        <div class="flex justify-end gap-2 border-t border-surface-container-highest pt-3">
                            <UiButton variant="secondary" :href="builderUrl()">Hủy</UiButton>
                            <UiButton type="submit" icon="save">{{ editor.model ? 'Lưu buổi học' : 'Thêm buổi học vào chặng' }}</UiButton>
                        </div>
                    </UiForm>
                </template>
            </section>

            <!-- Cây Chặng → Unit → Buổi -->
            <section class="space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">account_tree</span>
                        <h2 class="text-sm font-bold text-on-surface">Chặng học của giáo trình</h2>
                    </div>
                    <UiButton v-if="canManage" size="sm" icon="add" :href="builderUrl({ new_stage: 1 }) + '#editor'">Thêm chặng</UiButton>
                </div>
                <UiAlert type="info">Mỗi lớp chỉ học 1 chặng tại một thời điểm. Khi Big Test của chặng được duyệt và gửi kết quả cho phụ huynh, chặng đóng và chặng kế tiếp (theo thứ tự dưới đây) tự mở.</UiAlert>

                <article v-for="(stage, index) in stages" :key="stage.id" class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                    <header class="flex flex-col gap-3 border-b border-surface-container-highest bg-surface-container-low/60 p-5 md:flex-row md:items-start md:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-container/10 text-sm font-bold text-primary">{{ stage.position }}</div>
                            <div class="min-w-0">
                                <h3 class="flex flex-wrap items-center gap-sm font-h3 text-h3 text-on-surface">
                                    {{ stage.label }}
                                    <span class="rounded-full bg-primary-fixed/50 px-md py-0.5 font-label text-label text-primary">Tổng số: {{ pad2(lessonCount(stage)) }} buổi</span>
                                </h3>
                                <p class="text-xs text-on-surface-variant">
                                    {{ stage.units.length }} unit · {{ lessonCount(stage) }} buổi
                                    <template v-if="stage.open_classes"> · Đang học: {{ stage.open_classes }}</template>
                                </p>
                                <p v-if="stage.description" class="mt-1 whitespace-pre-line text-xs text-on-surface-variant">{{ stage.description }}</p>
                                <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="inline-flex items-center gap-1 font-semibold text-secondary"><span class="material-symbols-outlined text-[14px]">quiz</span>{{ stage.big_test_title || 'Big Test cuối chặng (chưa đặt tên)' }}</span>
                                    <span v-if="stage.big_test_note" class="text-on-surface-variant">— {{ stage.big_test_note }}</span>
                                    <a v-if="stage.overview_link" :href="stage.overview_link" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><span class="material-symbols-outlined text-[14px]">open_in_new</span>Tổng quan chặng</a>
                                </p>
                            </div>
                        </div>
                        <div v-if="canManage" class="flex shrink-0 flex-wrap items-center gap-1">
                            <UiForm v-if="index > 0" :action="route('syllabus.stages.move', stage.id)" method="post">
                                <input type="hidden" name="direction" value="up" />
                                <UiButton type="submit" variant="ghost" size="sm" icon="arrow_upward" title="Chuyển lên" />
                            </UiForm>
                            <UiForm v-if="index < stages.length - 1" :action="route('syllabus.stages.move', stage.id)" method="post">
                                <input type="hidden" name="direction" value="down" />
                                <UiButton type="submit" variant="ghost" size="sm" icon="arrow_downward" title="Chuyển xuống" />
                            </UiForm>
                            <UiButton variant="ghost" size="sm" icon="edit" :href="builderUrl({ edit_stage: stage.id }) + '#editor'">Sửa</UiButton>
                            <UiButton variant="ghost" size="sm" icon="add" :href="builderUrl({ new_unit: stage.id }) + '#editor'">Unit</UiButton>
                            <UiForm :action="route('syllabus.stages.destroy', stage.id)" method="delete" :confirm="`Xóa ${stage.label}?`" confirm-label="Xóa" danger>
                                <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa chặng" />
                            </UiForm>
                        </div>
                    </header>

                    <div class="space-y-3 p-4">
                        <div v-for="u in stage.units" :key="u.id" class="rounded-xl border border-surface-container-highest">
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-on-surface">Unit {{ u.unit_number }}: {{ u.title }}</p>
                                    <p v-if="u.objectives" class="truncate text-xs text-on-surface-variant">{{ u.objectives }}</p>
                                </div>
                                <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                                    <UiButton variant="ghost" size="sm" icon="add" :href="builderUrl({ new_lesson: u.id }) + '#editor'">Buổi</UiButton>
                                    <UiButton variant="ghost" size="sm" icon="edit" :href="builderUrl({ edit_unit: u.id }) + '#editor'">Sửa</UiButton>
                                    <UiForm :action="route('syllabus.units.destroy', u.id)" method="delete" :confirm="`Xóa Unit ${u.unit_number} cùng ${u.lessons.length} buổi học?`" confirm-label="Xóa" danger>
                                        <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa unit" />
                                    </UiForm>
                                </div>
                            </div>
                            <div class="divide-y divide-surface-container-highest border-t border-surface-container-highest">
                                <details v-for="lesson in u.lessons" :key="lesson.id" class="group px-4 py-2.5 text-xs">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-container/10 text-xs font-bold text-primary">{{ lesson.session_no }}</span>
                                            <span class="truncate font-semibold text-on-surface">Buổi {{ lesson.session_no }}: {{ lesson.title }}</span>
                                        </span>
                                        <span class="material-symbols-outlined text-[18px] text-on-surface-subtle transition group-open:rotate-180">expand_more</span>
                                    </summary>
                                    <div class="mt-3 grid grid-cols-1 gap-3 text-on-surface-variant md:grid-cols-2">
                                        <p v-for="[field, label, empty] in lessonFields" :key="field" class="whitespace-pre-line"><span class="font-semibold">{{ label }}</span> {{ lesson[field] || empty }}</p>
                                    </div>
                                    <div v-if="canManage" class="mt-2 flex justify-end gap-1">
                                        <UiButton variant="ghost" size="sm" icon="edit" :href="builderUrl({ edit_lesson: lesson.id }) + '#editor'">Sửa buổi</UiButton>
                                        <UiForm :action="route('syllabus.lessons.destroy', lesson.id)" method="delete" :confirm="`Xóa Buổi ${lesson.session_no}?`" confirm-label="Xóa" danger>
                                            <UiButton type="submit" variant="danger-text" size="sm" icon="delete">Xóa</UiButton>
                                        </UiForm>
                                    </div>
                                </details>
                                <p v-if="!u.lessons.length" class="px-4 py-3 text-xs text-on-surface-subtle">Unit chưa có buổi học nào.</p>
                            </div>
                        </div>
                        <p v-if="!stage.units.length" class="py-6 text-center text-xs text-on-surface-subtle">Chặng chưa có unit nào.</p>
                        <Link
                            v-if="canManage"
                            :href="stage.units.length ? builderUrl({ new_lesson: stage.units[stage.units.length - 1].id }) + '#editor' : builderUrl({ new_unit: stage.id }) + '#editor'"
                            class="w-full py-lg border-2 border-dashed border-outline-variant rounded-xl flex flex-col items-center justify-center gap-sm text-on-surface-variant hover:bg-surface-container-lowest hover:border-primary/50 hover:text-primary transition-all group"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-container-high transition-colors group-hover:bg-primary-fixed">
                                <span class="material-symbols-outlined text-[24px]">add</span>
                            </span>
                            <span class="font-body-medium">{{ stage.units.length ? 'Thêm buổi học mới vào chặng' : 'Thêm Unit đầu tiên cho chặng' }}</span>
                        </Link>
                    </div>
                </article>
            </section>
        </template>

        <div class="flex items-center justify-end gap-2">
            <UiButton variant="secondary" :href="route('syllabus.documents')">Quay lại</UiButton>
            <UiButton icon="arrow_forward" :href="route('syllabus.assignments')">Tiếp tục: Chặng của lớp</UiButton>
        </div>
    </div>
</template>
