<script setup>
/**
 * Form nộp báo cáo trực lớp (mockup "Báo cáo trực lớp" 14/10/2026) — dùng chung modal và trang đầy đủ (id tiền tố "modal-"
 * trong modal, nút Nộp ở footer modal). 4 khối: 1. Thông tin ca trực (chỉ đọc, theo lớp / buổi đã chọn), 2. Nội dung & diễn biến,
 * 3. Ảnh minh chứng (nhiều ảnh), 4. Học sinh cần bổ trợ (bảng động). Quy tắc chuyển trạng thái nằm sau nút "i"; cuối form báo
 * trước kết quả sau khi nộp (có ảnh → hoàn thành ngay, không ảnh → chờ Admin xác nhận).
 * Đổi lớp: tải lại form theo lớp mới (buổi / học sinh / người xác nhận của lớp). Ảnh: gửi multipart, xem trước và bỏ bớt ảnh đã chọn.
 * Lấy từ hệ thống (chủ dự án 15/10/2026): "Hôm nay học gì" điền sẵn theo giáo trình của buổi, sĩ số theo điểm danh của GV,
 * danh sách bổ trợ gợi ý sẵn học sinh vắng buổi này + mini test gần nhất dưới 7 (người nộp xóa / sửa được, chỉ "Kế hoạch xử lý" là nhập tay).
 * Thiết kế lại theo khối (chủ dự án 17/10/2026): khối 2 hiện số liệu tự động của buổi (sĩ số, đi muộn, BTVN đã nộp, nhận xét GV)
 * cạnh ô ghi chú; khối 5 là task follow-up giao thẳng cho người xử lý — việc chưa xong của lớp hiện lại ở báo cáo sau ("Từ dd/mm").
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import DailyMetric from '../../Reports/DailyMetric.vue';
import SectionTasks from '../../Reports/SectionTasks.vue';

const props = defineProps({
    formId: { type: String, required: true },
    classes: { type: Array, default: () => [] },
    selectedClassId: { type: Number, default: null },
    task: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    sessionOptions: { type: Array, default: () => [] },
    defaultSessionId: { type: Number, default: null },
    confirmMode: { type: String, default: 'none' },
    confirmer: { type: String, default: null },
    asModal: { type: Boolean, default: false },
    classInfo: { type: Object, default: null },
    sessionDetails: { type: Object, default: () => ({}) },
    reporter: { type: String, default: null },
    cancelHref: { type: String, default: null },
    testSuggestions: { type: Array, default: () => [] },
    followUps: { type: Array, default: () => [] },
    followUpActions: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    today: { type: String, default: null },
});

const p = props.asModal ? 'modal-' : 'f_';

/** Đổi lớp → tải lại form (trong modal: đổi nội dung modal tại chỗ). */
function pickClass(value) {
    const url = route('tasks.class-reports.create', { class_id: value, ...(props.task ? { task_id: props.task.id } : {}) });
    if (props.asModal) openRemoteModal(url, { size: '2xl' });
    else router.get(url);
}

// Buổi đang chọn → ô "Buổi học thực tế" / "Khung giờ & phòng học"
const sessionId = ref(props.defaultSessionId ?? '');
const session = computed(() => props.sessionDetails?.[sessionId.value] ?? null);

// "Hôm nay học gì": điền theo giáo trình của buổi; đổi buổi thì thay nếu người nộp chưa sửa.
const lessonText = ref(session.value?.lesson_text ?? '');
watch(session, (next, prev) => {
    if (!lessonText.value || lessonText.value === (prev?.lesson_text ?? '')) lessonText.value = next?.lesson_text ?? '';
});

// Học sinh cần bổ trợ: dòng gợi ý (`auto`: 'session' theo buổi đang chọn, 'test' theo mini test) + dòng thêm tay.
let seq = 1;
const row = (s = {}, auto = null) => ({ id: seq++, student_id: s.student_id ? String(s.student_id) : '', absence_session: s.absence_session ?? '', reason: s.reason ?? '', action_plan: '', auto });
const supports = ref([...(session.value?.suggested ?? []).map((s) => row(s, 'session')), ...props.testSuggestions.map((s) => row(s, 'test'))]);
const addSupport = () => supports.value.push(row());
const removeSupport = (index) => supports.value.splice(index, 1);
watch(session, (next) => {
    const kept = supports.value.filter((s) => s.auto !== 'session' || s.action_plan);
    const have = new Set(kept.map((s) => s.student_id));
    supports.value = [...(next?.suggested ?? []).filter((s) => !have.has(String(s.student_id))).map((s) => row(s, 'session')), ...kept];
});

// Task follow-up: dòng mới gửi cùng form theo `tasks[i][…]`.
let uid = 0;
const newTasks = ref([]);
const addTask = () => newTasks.value.push({ uid: ++uid, title: null, action: '' });
const removeTask = (t) => (newTasks.value = newTasks.value.filter((x) => x.uid !== t.uid));
const indexOf = (t) => newTasks.value.indexOf(t);

// Ảnh bảng / lớp: xem trước + bỏ từng ảnh
const photos = ref(null);
const previews = ref([]);
function syncPreviews() {
    previews.value.forEach((pv) => URL.revokeObjectURL(pv.url));
    previews.value = Array.from(photos.value?.files ?? []).map((f) => ({ name: f.name, url: URL.createObjectURL(f) }));
}
function removePreview(index) {
    const input = photos.value;
    const dt = new DataTransfer();
    Array.from(input.files).forEach((f, i) => {
        if (i !== index) dt.items.add(f);
    });
    input.files = dt.files;
    syncPreviews();
}
onBeforeUnmount(() => previews.value.forEach((pv) => URL.revokeObjectURL(pv.url)));

const pick = (errors, prefix) => Object.entries(errors).filter(([key]) => key === prefix || key.startsWith(prefix + '.')).map(([, msg]) => msg);
const selectCls = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small';
const labelCls = 'mb-xs block font-label text-label uppercase text-on-surface-variant';
</script>

<template>
    <UiEmptyState v-if="!classes.length" icon="class" title="Bạn chưa phụ trách lớp nào" description="Chỉ GV / GVNN / trợ giảng của lớp (hoặc người quản lý lớp) được nộp báo cáo trực lớp." />
    <template v-else>
        <!-- Người nộp + quy tắc (sau nút "i") -->
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <span class="inline-flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-low px-sm py-xs font-body-small text-body-small text-on-surface-variant">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">person</span>Người nộp: <strong class="text-on-surface">{{ reporter }}</strong>
            </span>
            <span class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                Quy tắc chuyển trạng thái
                <UiInfo title="Quy tắc chuyển trạng thái nhiệm vụ" align="right" label="Quy tắc chuyển trạng thái nhiệm vụ">
                    <p><strong class="text-on-surface">Có ít nhất 1 ảnh đính kèm:</strong> đầu việc trực lớp tự chuyển sang <strong class="text-tertiary">Hoàn thành</strong> ngay.</p>
                    <p><strong class="text-on-surface">Không có ảnh:</strong> chuyển sang <strong class="text-warning">Chờ xác nhận</strong> (chờ Admin xác nhận ở màn duyệt).</p>
                    <p v-if="confirmMode === 'none'">Lớp này chưa có GV chính và báo cáo chưa gắn đầu việc được giao, nên cần đính kèm ít nhất 1 ảnh.</p>
                </UiInfo>
            </span>
        </div>

        <UiForm :id="formId" :action="route('tasks.class-reports.store')" method="post" class="space-y-md" #default="{ errors }">
            <input type="hidden" name="class_id" :value="selectedClassId" />
            <input v-if="task" type="hidden" name="task_id" :value="task.id" />
            <UiErrors :messages="[errors.class_id, errors.task_id]" />

            <!-- 1. Thông tin ca trực gán sẵn -->
            <section class="space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-report-section="info">
                <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>1. Thông tin ca trực</h2>
                    <span v-if="task?.class_id" class="font-caption text-caption italic text-on-surface-variant">Khóa theo đầu việc được giao</span>
                </div>
                <div v-if="!task?.class_id || sessionOptions.length" class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                    <UiSelect v-if="!task?.class_id" :id="p + 'pick_class_id'" label="Lớp học" :model-value="selectedClassId ?? ''" :options="classes" @update:model-value="pickClass" />
                    <UiSelect v-if="sessionOptions.length" :id="p + 'class_session_id'" v-model="sessionId" name="class_session_id" label="Buổi học" placeholder="-- Nhập tên buổi bên dưới --" :options="sessionOptions" />
                </div>
                <div class="grid grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Lớp học</p>
                        <p class="mt-xs flex items-start gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">school</span>{{ classInfo?.name ?? '—' }}</p>
                        <p v-if="classInfo?.code" class="font-caption text-caption text-on-surface-variant">Mã: {{ classInfo.code }}</p>
                    </div>
                    <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Buổi học thực tế</p>
                        <p class="mt-xs flex items-start gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">event_note</span>
                            <template v-if="session?.no">Buổi {{ session.no }}<template v-if="classInfo?.total_sessions"> / {{ classInfo.total_sessions }}</template></template>
                            <template v-else-if="session">{{ formatDate(session.date) }}</template>
                            <template v-else>Chưa chọn buổi</template>
                        </p>
                        <p v-if="session?.title" class="font-caption text-caption text-on-surface-variant">{{ session.title }}</p>
                    </div>
                    <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Khung giờ & phòng học</p>
                        <p class="mt-xs flex items-start gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">schedule</span>{{ session?.time ?? '—' }}</p>
                        <p v-if="session?.room || session?.branch" class="font-caption text-caption text-on-surface-variant">{{ [session.room ? 'Phòng ' + session.room : null, session.branch].filter(Boolean).join(' · ') }}</p>
                    </div>
                    <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                        <p class="font-label text-label uppercase text-on-surface-variant">Giáo viên chính</p>
                        <p class="mt-xs flex items-start gap-xs font-body-medium text-body-medium font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">badge</span>{{ classInfo?.teacher ?? 'Chưa có' }}</p>
                        <p v-if="task?.creator" class="font-caption text-caption text-on-surface-variant">Người giao việc: {{ task.creator }}</p>
                    </div>
                </div>
                <div v-if="!sessionId">
                    <UiInput
                        :id="p + 'session_name'"
                        name="session_name"
                        label="Tên buổi"
                        :value="task?.lesson_session"
                        maxlength="255"
                        :placeholder="sessionOptions.length ? 'Để trống nếu đã chọn buổi ở trên' : 'VD: Buổi 5 - Listening Practice'"
                    />
                </div>
                <UiErrors :messages="[errors.session_name, errors.class_session_id]" />
            </section>

            <!-- 2. Nội dung & diễn biến buổi học -->
            <section class="space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-report-section="content">
                <h2 class="flex items-center gap-sm border-b border-surface-container pb-sm font-h3 text-h3 text-on-surface"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>2. Nội dung & Diễn biến buổi học</h2>
                <UiTextarea
                    :id="p + 'hom_nay_hoc_gi'"
                    v-model="lessonText"
                    name="hom_nay_hoc_gi"
                    label="Hôm nay học gì"
                    required
                    :rows="3"
                    :hint="session?.lesson_text ? 'Điền sẵn theo giáo trình của buổi. Sửa lại nếu buổi thực tế dạy khác.' : null"
                    placeholder="VD: Tên bài học, dạng bài, kiến thức ngữ pháp / từ vựng cốt lõi đã dạy trên lớp..."
                />
                <div data-report-metrics>
                    <p class="mb-xs flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">insights</span>Số liệu buổi học (lấy từ hệ thống)
                    </p>
                    <div v-if="session?.metrics?.length" class="grid grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-4">
                        <DailyMetric v-for="item in session.metrics" :key="item.key" :item="item" />
                    </div>
                    <p v-else class="rounded-xl border border-outline-variant bg-surface-container-low p-sm font-body-small text-body-small italic text-on-surface-variant">Chọn buổi học để xem sĩ số, đi muộn, BTVN và nhận xét của GV.</p>
                </div>
                <UiTextarea :id="p + 'nhat_ky_day'" name="nhat_ky_day" label="Ghi chú thêm (tùy chọn)" :rows="3" placeholder="Không khí lớp, nề nếp, thiết bị phòng học, điều số liệu chưa thể hiện..." />
            </section>

            <!-- 3. Ảnh minh chứng ca trực -->
            <section class="space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-report-section="photos">
                <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>3. Ảnh minh chứng ca trực (Nhiều ảnh)</h2>
                    <span v-if="previews.length" class="rounded-lg border border-tertiary/20 bg-tertiary-container/10 px-sm py-[2px] font-caption text-caption font-medium text-tertiary">✓ Đã có {{ previews.length }} ảnh đính kèm (đủ điều kiện Hoàn thành ngay)</span>
                    <span v-else class="rounded-lg border border-warning/20 bg-warning-container px-sm py-[2px] font-caption text-caption font-medium text-on-warning-container">Chưa có ảnh</span>
                </div>
                <label class="flex cursor-pointer flex-col items-center justify-center gap-xs rounded-xl border-2 border-dashed border-outline-variant px-md py-lg text-center hover:border-primary-container">
                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined" aria-hidden="true">add_photo_alternate</span></span>
                    <span class="font-body-medium text-body-medium font-semibold text-on-surface">Bấm để tải ảnh lên hoặc chụp trực tiếp từ điện thoại</span>
                    <span class="font-caption text-caption text-on-surface-variant">JPG, PNG, WEBP · tối đa 10 ảnh, mỗi ảnh ≤ 10MB</span>
                    <input ref="photos" type="file" name="board_images[]" accept="image/*" multiple class="sr-only" @change="syncPreviews" />
                </label>
                <div v-if="previews.length" class="grid grid-cols-2 gap-sm sm:grid-cols-4">
                    <div v-for="(pv, i) in previews" :key="pv.url" class="relative aspect-[4/3] overflow-hidden rounded-xl border border-outline-variant">
                        <img :src="pv.url" :alt="pv.name" class="h-full w-full object-cover" />
                        <span class="absolute left-xs top-xs rounded bg-black/60 px-xs font-caption text-caption text-white">Ảnh #{{ i + 1 }}</span>
                        <button type="button" class="absolute right-xs top-xs flex h-6 w-6 items-center justify-center rounded-full bg-error text-white" aria-label="Bỏ ảnh" @click="removePreview(i)">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">close</span>
                        </button>
                    </div>
                </div>
                <UiErrors :messages="pick(errors, 'board_images')" />
            </section>

            <!-- 4. Học sinh cần bổ trợ -->
            <section class="space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-report-section="supports">
                <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                    <div>
                        <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>4. Học sinh cần bổ trợ (tùy chọn)</h2>
                        <p class="font-caption text-caption text-on-surface-variant">Học sinh cần phụ đạo, kèm bài hoặc vắng buổi trước cần bù bài</p>
                    </div>
                    <UiButton variant="secondary" size="sm" icon="add_circle" @click="addSupport()">Thêm học sinh cần bổ trợ</UiButton>
                </div>
                <UiErrors :messages="pick(errors, 'supports')" />
                <p v-if="!supports.length" class="text-center font-body-small text-body-small italic text-on-surface-variant">Chưa có học sinh cần bổ trợ.</p>
                <div v-for="(sup, idx) in supports" :key="sup.id" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center gap-xs">
                            <span class="inline-flex items-center gap-xs rounded-md border border-outline-variant bg-surface-container-lowest px-sm py-[2px] font-caption text-caption font-semibold text-on-surface"><span class="h-1.5 w-1.5 rounded-full bg-primary-container" aria-hidden="true"></span>Học sinh #{{ idx + 1 }}</span>
                            <UiBadge v-if="sup.auto" color="info" :dot="false" data-support-suggested>{{ sup.auto === 'test' ? 'Gợi ý: mini test dưới 7' : 'Gợi ý: vắng buổi này' }}</UiBadge>
                        </span>
                        <button type="button" class="inline-flex items-center gap-xs font-caption text-caption font-semibold text-error hover:underline" @click="removeSupport(idx)"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span>Xóa dòng</button>
                    </div>
                    <div class="grid grid-cols-1 gap-sm md:grid-cols-3">
                        <label>
                            <span :class="labelCls">Học sinh <span class="text-error">*</span></span>
                            <select v-model="sup.student_id" :name="`supports[${idx}][student_id]`" :class="selectCls">
                                <option value="">-- Chọn học sinh --</option>
                                <option v-for="st in students" :key="st.value" :value="String(st.value)">{{ st.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span :class="labelCls">Buổi vắng (tùy chọn)</span>
                            <select v-model="sup.absence_session" :name="`supports[${idx}][absence_session]`" :class="selectCls">
                                <option value="">-- Không chọn buổi vắng --</option>
                                <option v-for="opt in sessionOptions" :key="opt.value" :value="opt.label">{{ opt.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span :class="labelCls">Lý do cần bổ trợ <span class="text-error">*</span></span>
                            <textarea v-model="sup.reason" :name="`supports[${idx}][reason]`" rows="2" placeholder="Mô tả lý do cần bổ trợ..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                        </label>
                    </div>
                    <label class="block">
                        <span :class="labelCls">Kế hoạch xử lý / Nội dung bù bài</span>
                        <textarea v-model="sup.action_plan" :name="`supports[${idx}][action_plan]`" rows="2" placeholder="VD: Dành 15 phút đầu giờ buổi tới để hướng dẫn lại..." class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small"></textarea>
                    </label>
                </div>
            </section>

            <!-- 5. Task follow-up -->
            <section class="space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-report-section="tasks">
                <div>
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>5. Task follow-up (tùy chọn)</h2>
                    <p class="font-caption text-caption text-on-surface-variant">Việc cần người khác xử lý sau buổi. Việc chưa xong của lớp sẽ hiện lại ở báo cáo sau.</p>
                </div>
                <SectionTasks
                    title="Task của lớp"
                    :tasks="followUps"
                    :rows="newTasks"
                    :action-options="followUpActions"
                    :quick-actions="!asModal"
                    :index-of="indexOf"
                    :assignees="assignees"
                    :today="today"
                    hint="Cần Học vụ kiểm tra thiết bị, xếp buổi bù hay gọi phụ huynh thì tạo task giao thẳng cho người đó."
                    @add="addTask"
                    @remove="removeTask"
                />
                <UiErrors :messages="pick(errors, 'tasks')" />
            </section>

            <!-- Kết quả dự kiến + nút nộp -->
            <div class="flex flex-col gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm sm:flex-row sm:items-center sm:justify-between" data-report-outcome>
                <p class="flex items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                    <span :class="['h-2.5 w-2.5 shrink-0 rounded-full', previews.length ? 'bg-tertiary' : 'bg-warning']" aria-hidden="true"></span>
                    <span>
                        Kết quả dự kiến sau khi bấm nộp:
                        <strong v-if="previews.length" class="text-tertiary">Hoàn thành ngay</strong>
                        <template v-else-if="confirmMode !== 'none'"><strong class="text-warning">Chờ xác nhận</strong> (chờ Admin xác nhận)</template>
                        <strong v-else class="text-error">Cần đính kèm ít nhất 1 ảnh</strong>
                    </span>
                </p>
                <div v-if="!asModal" class="flex gap-sm">
                    <UiButton v-if="cancelHref" variant="secondary" :href="cancelHref">Hủy bỏ</UiButton>
                    <UiButton type="submit" icon="send">Nộp báo cáo</UiButton>
                </div>
            </div>
        </UiForm>
    </template>
</template>
