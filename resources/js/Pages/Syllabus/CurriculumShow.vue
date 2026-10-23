<script setup>
/**
 * Kho syllabus — trang chi tiết giáo trình (docs/kho-syllabus.md): tab Thông tin | Chặng & buổi (?tab=).
 * Cây Giáo trình → Chặng (Big Test cuối chặng) → Unit → Buổi; ô soạn thảo chặng / unit / buổi mở trong hộp thoại theo
 * tham số URL (new_stage, edit_stage, new_unit, edit_unit, new_lesson, edit_lesson); nút Sửa / Thêm giữ vị trí cuộn của
 * cây, đóng hộp thoại thì URL về trang chi tiết.
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import DocumentList from '@/Components/Syllabus/DocumentList.vue';
import DocumentUploadModal from '@/Components/Syllabus/DocumentUploadModal.vue';
import StageImportModal from '@/Components/Syllabus/StageImportModal.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Kho syllabus' } });

const props = defineProps({
    tab: { type: String, default: 'stages' },
    curriculum: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    unitOptions: { type: Array, default: () => [] },
    editor: { type: Object, default: null },
    courses: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    lessonTypes: { type: Array, default: () => [] },
    milestones: { type: Array, default: () => [] },
    documents: { type: Object, default: () => ({ shared: [], stages: {}, lessons: {} }) },
    history: { type: Object, default: null },
});

const stageOptions = computed(() => props.stages.map((s) => ({ value: s.id, label: s.label })));
const lessonCount = (stage) => stage.units.reduce((sum, u) => sum + u.lessons.length, 0);
const showUrl = (params = {}) => route('syllabus.curriculums.show', { id: props.curriculum.id, ...params });
const tabs = { stages: 'Chặng & buổi', documents: 'Tài liệu chung', info: 'Thông tin', history: 'Lịch sử' };
const shortValue = (v) => (v === null || v === undefined || v === '' ? '—' : String(v).length > 80 ? String(v).slice(0, 80) + '…' : String(v));

// Tải tài liệu: phạm vi theo chỗ bấm (buổi / chặng / chung).
const upload = ref(null);
const uploadTo = (scope) => (upload.value = { stageId: null, lessonId: null, ...scope });

// Import chặng từ file Sheet 1: null = đóng; { stage: null } = thêm chặng mới; { stage } = cập nhật chặng.
const stageImport = ref(null);
const pad2 = (n) => String(n).padStart(2, '0');

// Ô soạn chặng: xem trước ảnh / tài liệu tổng quan.
const overviewLink = (editor) => (editor?.type === 'stage' ? (editor.model?.overview_link ?? '') : '');
const link = ref(overviewLink(props.editor));
const preview = ref(link.value);

const editorOpen = ref(!!props.editor);
const editorKey = computed(() => (props.editor ? `${props.editor.type}-${props.editor.model?.id ?? 'new'}-${props.editor.parent?.id ?? ''}` : ''));
// Theo dõi theo định danh ô soạn (như Versions theo selected?.id): lưu lỗi validate trả về cùng ô → giữ link đang nhập.
watch(editorKey, () => {
    editorOpen.value = !!props.editor;
    link.value = overviewLink(props.editor);
    preview.value = link.value;
});
const editorTitle = computed(() => {
    const e = props.editor;
    if (!e) return '';
    if (e.type === 'stage') return 'Thông tin chung chặng học · ' + (e.model ? `Sửa ${e.model.label}` : `Thêm chặng mới (Chặng ${props.totals.next_position})`);
    if (e.type === 'unit') return e.model ? `Sửa Unit ${e.model.unit_number}: ${e.model.title}` : `Thêm Unit vào ${e.parent?.label ?? ''}`;
    return e.model ? `Sửa Buổi ${e.model.session_no}: ${e.model.title}` : `Thêm buổi vào Unit ${e.parent?.unit_number ?? ''}: ${e.parent?.title ?? ''}`;
});
const editorSubmitLabel = computed(() => {
    const isNew = !props.editor?.model;
    return { stage: isNew ? 'Thêm chặng học' : 'Lưu chặng học', unit: isNew ? 'Thêm Unit' : 'Lưu Unit', lesson: isNew ? 'Thêm buổi học vào chặng' : 'Lưu buổi học' }[props.editor?.type] ?? 'Lưu';
});
const isImage = (u) => /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(u || '');

// Trường buổi học theo form syllabus mẫu (Sheet 1); link PPT / Quizizz hiển thị riêng thành liên kết.
const lessonFields = [
    ['objectives', 'Mục tiêu:', 'Chưa cập nhật'],
    ['content', 'Content:', 'Chưa cập nhật'],
    ['procedure', 'Quy trình tiết học:', '—'],
    ['activity_1', 'Hoạt động gợi ý 1:', '—'],
    ['activity_2', 'Hoạt động gợi ý 2:', '—'],
    ['homework_guide', 'Bài tập giấy:', '—'],
    ['homework_quizizz', 'Bài tập Quizizz:', '—'],
    ['homework_video', 'Bài tập Video:', '—'],
    ['output_product', 'Sản phẩm đầu ra:', '—'],
    ['vocabulary_focus', 'Từ vựng:', '—'],
    ['grammar_focus', 'Ngữ pháp:', '—'],
    ['note', 'Ghi chú:', '—'],
];
const milestoneLabel = (value) => props.milestones.find((m) => m.value === value)?.label;
</script>

<template>
    <UiPageHeader :title="curriculum.title" :back="route('syllabus.curriculums.index')">
        <template #meta>{{ curriculum.code }} · {{ curriculum.version }} · {{ stages.length }} chặng · {{ totals.units }} unit · {{ totals.lessons }} buổi</template>
    </UiPageHeader>

    <div class="space-y-6 pb-10">
        <nav class="no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest" aria-label="Tab giáo trình">
            <Link
                v-for="(label, key) in tabs"
                :key="key"
                :href="showUrl({ tab: key })"
                preserve-scroll
                :aria-current="tab === key ? 'page' : undefined"
                :class="['-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-md font-body-medium text-body-medium transition-colors', tab === key ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary']"
            >
                {{ label }}
            </Link>
        </nav>

        <!-- Tab Thông tin: thông tin giáo trình + Trình độ áp dụng -->
        <section v-if="tab === 'info'" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
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

        <!-- Ô soạn thảo chặng / unit / buổi: hộp thoại mở theo tham số URL, đóng thì về trang chi tiết (không tải lại trang). -->
        <UiModal :show="editorOpen" :title="editorTitle" max-width="3xl" :dismiss-url="showUrl()" @close="editorOpen = false">
            <div v-if="editor" id="editor" class="p-md">
                <UiForm v-if="editor.type === 'stage'" id="syllabus-editor-form" :key="editorKey" :action="editor.model ? route('syllabus.stages.update', editor.model.id) : route('syllabus.stages.store')" :method="editor.model ? 'put' : 'post'" class="grid grid-cols-1 gap-lg md:grid-cols-2">
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
                    <UiInput type="number" name="notice_before_sessions" label="Báo trước (buổi)" min="1" max="50" hint="Còn bấy nhiêu buổi đến buổi cuối chặng thì hệ thống báo Học thuật." :value="editor.model?.notice_before_sessions ?? 6" />
                </UiForm>

                <UiForm v-else-if="editor.type === 'unit'" id="syllabus-editor-form" :key="editorKey" :action="editor.model ? route('syllabus.units.update', editor.model.id) : route('syllabus.units.store')" :method="editor.model ? 'put' : 'post'" class="space-y-4">
                    <input v-if="!editor.model" type="hidden" name="curriculum_id" :value="curriculum.id" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <UiInput type="number" name="unit_number" label="Unit số" required min="1" :value="editor.model?.unit_number ?? totals.next_unit_number" />
                        <div class="sm:col-span-3"><UiInput name="title" label="Tên Unit" required :value="editor.model?.title" placeholder="Unit 3: Environment & Climate Change" /></div>
                    </div>
                    <UiSelect name="stage_id" label="Thuộc chặng" required :value="editor.parent?.id ?? ''" :options="stageOptions" />
                    <UiTextarea name="objectives" label="Mô tả / mục tiêu Unit" :rows="2" :value="editor.model?.objectives" />
                </UiForm>

                <UiForm v-else id="syllabus-editor-form" :key="editorKey" :action="editor.model ? route('syllabus.lessons.update', editor.model.id) : route('syllabus.lessons.store')" :method="editor.model ? 'put' : 'post'" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <UiInput type="number" name="session_no" label="Buổi số" required min="1" hint="Đánh liên tục trong cả giáo trình" :value="editor.model?.session_no ?? totals.next_session_no" />
                        <div class="sm:col-span-3"><UiInput name="title" label="Tiêu đề buổi học" required :value="editor.model?.title" placeholder="Buổi 01: Introduction to IELTS & Greetings" /></div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <UiSelect name="unit_id" label="Thuộc Unit" required :value="editor.parent?.id ?? ''" :options="unitOptions" />
                        <UiSelect name="lesson_type" label="Loại buổi" :value="editor.model?.lesson_type ?? 'lesson'" :options="lessonTypes" />
                    </div>
                    <UiTextarea name="objectives" label="Mục tiêu buổi học (Target)" :rows="3" :value="editor.model?.objectives" placeholder="Người học cần đạt được điều gì sau buổi này..." />
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiTextarea name="content" label="Content (nội dung từng tiết)" :rows="5" :value="editor.model?.content" placeholder="Getting started: hội thoại ngày đầu đi học..." />
                        <UiTextarea name="procedure" label="Quy trình tiết học" :rows="5" :value="editor.model?.procedure" placeholder="1. Warm-up (5’)&#10;2. Presentation (10’)..." />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiTextarea name="activity_1" label="Hoạt động gợi ý 1" :rows="2" :value="editor.model?.activity_1" />
                        <UiTextarea name="activity_2" label="Hoạt động gợi ý 2" :rows="2" :value="editor.model?.activity_2" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <UiTextarea name="homework_guide" label="Bài tập giấy" :rows="3" :value="editor.model?.homework_guide" />
                        <UiTextarea name="homework_quizizz" label="Bài tập Quizizz" :rows="3" :value="editor.model?.homework_quizizz" />
                        <UiTextarea name="homework_video" label="Bài tập Video" :rows="3" :value="editor.model?.homework_video" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiInput type="url" name="ppt_url" label="Link PPT" :value="editor.model?.ppt_url" placeholder="https://..." />
                        <UiInput type="url" name="quizizz_url" label="Link Quizizz" :value="editor.model?.quizizz_url" placeholder="https://quizizz.com/..." />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiTextarea name="output_product" label="Sản phẩm đầu ra" :rows="2" :value="editor.model?.output_product" />
                        <UiSelect name="notice_milestone" label="Mốc thông báo" placeholder="-- Không có --" :value="editor.model?.notice_milestone ?? ''" :options="milestones" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <UiTextarea name="vocabulary_focus" label="Trọng tâm từ vựng" :rows="2" :value="editor.model?.vocabulary_focus" />
                        <UiTextarea name="grammar_focus" label="Trọng tâm ngữ pháp" :rows="2" :value="editor.model?.grammar_focus" />
                    </div>
                    <UiTextarea name="note" label="Ghi chú" :rows="2" :value="editor.model?.note" />
                </UiForm>
            </div>
            <template v-if="editor" #footer>
                <UiButton variant="secondary" @click="editorOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="syllabus-editor-form" icon="save">{{ editorSubmitLabel }}</UiButton>
            </template>
        </UiModal>

        <!-- Tab Tài liệu chung: tài liệu không gắn chặng / buổi -->
        <section v-if="tab === 'documents'" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-on-surface">Tài liệu dùng chung cho cả giáo trình</h2>
                <UiButton v-if="canManage" size="sm" icon="upload_file" @click="uploadTo({ label: 'Tài liệu chung — ' + curriculum.title })">Tải tài liệu</UiButton>
            </div>
            <DocumentList :documents="documents.shared" :can-manage="canManage" empty-text="Chưa có tài liệu chung." />
        </section>

        <!-- Tab Lịch sử: ai sửa gì, lúc nào (giáo trình, chặng, unit, buổi, tài liệu) -->
        <UiDataTable v-if="tab === 'history' && history" min-width="760px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">Lịch sử thay đổi</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Người sửa</th>
                        <th>Thao tác</th>
                        <th>Đối tượng</th>
                        <th>Thay đổi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in history.data" :key="row.id" class="align-top">
                        <td class="whitespace-nowrap">{{ formatDate(row.at, 'd/m/Y H:i') }}</td>
                        <td>{{ row.causer || 'Hệ thống' }}</td>
                        <td><UiBadge :color="row.event === 'Xóa' ? 'error' : row.event === 'Tạo' ? 'success' : 'info'">{{ row.event }}</UiBadge></td>
                        <td>{{ row.subject }}</td>
                        <td class="text-body-small">
                            <p v-for="c in row.changes" :key="c.field"><span class="font-code">{{ c.field }}</span>: {{ shortValue(c.old) }} → {{ shortValue(c.new) }}</p>
                            <p v-if="!row.changes.length" class="text-on-surface-variant">{{ row.description }}</p>
                        </td>
                    </tr>
                    <tr v-if="!history.data.length">
                        <td colspan="5"><UiEmptyState icon="history" title="Chưa có thay đổi nào được ghi lại" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="history" unit="thay đổi" /></template>
        </UiDataTable>

        <StageImportModal v-if="canManage" :show="!!stageImport" :curriculum-id="curriculum.id" :stage="stageImport?.stage ?? null" @close="stageImport = null" />

        <DocumentUploadModal
            v-if="canManage"
            :show="!!upload"
            :curriculum-id="curriculum.id"
            :stage-id="upload?.stageId ?? null"
            :lesson-id="upload?.lessonId ?? null"
            :scope-label="upload?.label ?? ''"
            @close="upload = null"
        />

        <!-- Tab Chặng & buổi: cây Chặng → Unit → Buổi -->
        <section v-if="tab === 'stages'" class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">account_tree</span>
                    <h2 class="text-sm font-bold text-on-surface">Chặng học của giáo trình</h2>
                </div>
                <div v-if="canManage" class="flex flex-wrap items-center gap-1">
                    <UiButton variant="secondary" size="sm" icon="download" :href="route('syllabus.stage-import.template')" native>File mẫu chặng</UiButton>
                    <UiButton variant="secondary" size="sm" icon="upload_file" @click="stageImport = { stage: null }">Thêm chặng từ file</UiButton>
                    <UiButton size="sm" icon="add" preserve-scroll :href="showUrl({ new_stage: 1 })">Thêm chặng</UiButton>
                </div>
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
                        <UiButton variant="ghost" size="sm" icon="edit" preserve-scroll :href="showUrl({ edit_stage: stage.id })">Sửa</UiButton>
                        <UiButton variant="ghost" size="sm" icon="add" preserve-scroll :href="showUrl({ new_unit: stage.id })">Unit</UiButton>
                        <UiButton variant="ghost" size="sm" icon="upload_file" @click="stageImport = { stage: { id: stage.id, label: stage.label } }">Import từ file</UiButton>
                        <UiForm :action="route('syllabus.stages.destroy', stage.id)" method="delete" :confirm="`Xóa ${stage.label}?`" confirm-label="Xóa" danger>
                            <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa chặng" />
                        </UiForm>
                    </div>
                </header>

                <div class="space-y-3 p-4">
                    <div class="space-y-2 rounded-xl bg-surface-container-low/60 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-bold uppercase text-on-surface-subtle">Tài liệu chặng</p>
                            <UiButton v-if="canManage" variant="ghost" size="sm" icon="upload_file" @click="uploadTo({ stageId: stage.id, label: stage.label })">Tải tài liệu</UiButton>
                        </div>
                        <DocumentList :documents="documents.stages[stage.id] ?? []" :can-manage="canManage" empty-text="Chưa có tài liệu chặng." />
                    </div>
                    <div v-for="u in stage.units" :key="u.id" class="rounded-xl border border-surface-container-highest">
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-on-surface">Unit {{ u.unit_number }}: {{ u.title }}</p>
                                <p v-if="u.objectives" class="truncate text-xs text-on-surface-variant">{{ u.objectives }}</p>
                            </div>
                            <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                                <UiButton variant="ghost" size="sm" icon="add" preserve-scroll :href="showUrl({ new_lesson: u.id })">Buổi</UiButton>
                                <UiButton variant="ghost" size="sm" icon="edit" preserve-scroll :href="showUrl({ edit_unit: u.id })">Sửa</UiButton>
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
                                        <UiBadge v-if="lesson.lesson_type !== 'lesson'" :color="lesson.lesson_type === 'big_test' ? 'error' : 'warning'">{{ lesson.type_label }}</UiBadge>
                                        <UiBadge v-if="lesson.notice_milestone" color="info">{{ milestoneLabel(lesson.notice_milestone) }}</UiBadge>
                                    </span>
                                    <span class="material-symbols-outlined text-[18px] text-on-surface-subtle transition group-open:rotate-180">expand_more</span>
                                </summary>
                                <div class="mt-3 grid grid-cols-1 gap-3 text-on-surface-variant md:grid-cols-2">
                                    <p v-for="[field, label, empty] in lessonFields" :key="field" class="whitespace-pre-line"><span class="font-semibold">{{ label }}</span> {{ lesson[field] || empty }}</p>
                                </div>
                                <p v-if="lesson.ppt_url || lesson.quizizz_url" class="mt-2 flex flex-wrap gap-3">
                                    <a v-if="lesson.ppt_url" :href="lesson.ppt_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><span class="material-symbols-outlined text-[14px]">slideshow</span>Link PPT</a>
                                    <a v-if="lesson.quizizz_url" :href="lesson.quizizz_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><span class="material-symbols-outlined text-[14px]">quiz</span>Link Quizizz</a>
                                </p>
                                <div class="mt-3 space-y-2">
                                    <p class="font-semibold text-on-surface">Tài liệu buổi</p>
                                    <DocumentList :documents="documents.lessons[lesson.id] ?? []" :can-manage="canManage" empty-text="Chưa có tài liệu buổi." />
                                </div>
                                <div v-if="canManage" class="mt-2 flex justify-end gap-1">
                                    <UiButton variant="ghost" size="sm" icon="upload_file" @click="uploadTo({ lessonId: lesson.id, label: `Buổi ${lesson.session_no}: ${lesson.title}` })">Tải tài liệu</UiButton>
                                    <UiButton variant="ghost" size="sm" icon="edit" preserve-scroll :href="showUrl({ edit_lesson: lesson.id })">Sửa buổi</UiButton>
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
                        preserve-scroll
                        :href="stage.units.length ? showUrl({ new_lesson: stage.units[stage.units.length - 1].id }) : showUrl({ new_unit: stage.id })"
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
    </div>
</template>
