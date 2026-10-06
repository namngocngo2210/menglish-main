<script setup>
/**
 * Form nộp báo cáo trực lớp — dùng chung modal và trang đầy đủ (id tiền tố "modal-" trong modal, nút Nộp ở footer modal).
 * Đổi lớp: tải lại form theo lớp mới (buổi / học sinh / người xác nhận của lớp). Ảnh: gửi multipart, xem trước và bỏ bớt ảnh đã chọn.
 */
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

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
});

const p = props.asModal ? 'modal-' : 'f_';

/** Đổi lớp → tải lại form (trong modal: đổi nội dung modal tại chỗ). */
function pickClass(value) {
    const url = route('tasks.class-reports.create', { class_id: value, ...(props.task ? { task_id: props.task.id } : {}) });
    if (props.asModal) openRemoteModal(url, { size: '2xl' });
    else router.get(url);
}

// Học sinh cần bổ trợ
let seq = 1;
const supports = ref([]);
const addSupport = () => supports.value.push({ id: seq++, student_id: '', absence_session: '', reason: '', action_plan: '' });
const removeSupport = (index) => supports.value.splice(index, 1);

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
        <!-- Đổi lớp: tải lại để lấy danh sách buổi / học sinh / người xác nhận của lớp -->
        <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
            <div class="flex items-center gap-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary"><span class="material-symbols-outlined" aria-hidden="true">class</span></span>
                <div class="min-w-0 flex-1">
                    <UiSelect :id="p + 'pick_class_id'" label="Lớp học" aria-label="Lớp học" :model-value="selectedClassId ?? ''" :options="classes" :disabled="!!task?.class_id" @update:model-value="pickClass" />
                </div>
            </div>
            <p v-if="task" class="mt-sm flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">assignment</span>
                Đầu việc: <span class="font-semibold text-on-surface">{{ task.title }}</span> · giao bởi {{ task.creator ?? '—' }}
            </p>
        </div>

        <UiForm :id="formId" :action="route('tasks.class-reports.store')" method="post" class="space-y-md" #default="{ errors }">
            <input type="hidden" name="class_id" :value="selectedClassId" />
            <input v-if="task" type="hidden" name="task_id" :value="task.id" />
            <UiErrors :messages="[errors.class_id, errors.task_id]" />

            <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <div class="flex items-start gap-sm">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-secondary-fixed text-secondary"><span class="material-symbols-outlined" aria-hidden="true">event_note</span></span>
                    <div class="min-w-0 flex-1 space-y-xs">
                        <span class="block font-label text-label uppercase text-on-surface-variant">Buổi học</span>
                        <UiSelect v-if="sessionOptions.length" :id="p + 'class_session_id'" name="class_session_id" placeholder="-- Nhập tên buổi bên dưới --" aria-label="Buổi học" :value="defaultSessionId" :options="sessionOptions" />
                        <UiInput
                            :id="p + 'session_name'"
                            name="session_name"
                            :value="task?.lesson_session"
                            maxlength="255"
                            aria-label="Tên buổi"
                            :placeholder="sessionOptions.length ? 'Hoặc nhập tên buổi (để trống = theo buổi đã chọn)' : 'VD: Buổi 5 - Listening Practice'"
                        />
                        <UiErrors :messages="[errors.session_name, errors.class_session_id]" />
                    </div>
                </div>
            </div>

            <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <UiTextarea :id="p + 'hom_nay_hoc_gi'" name="hom_nay_hoc_gi" label="Hôm nay học gì" required :rows="3" placeholder="Tóm tắt nội dung chính đã giảng dạy..." />
                <UiTextarea :id="p + 'nhat_ky_day'" name="nhat_ky_day" label="Nhật ký dạy (Tùy chọn)" :rows="3" placeholder="Ghi chú về thái độ học tập, vấn đề phát sinh..." />

                <div class="space-y-sm">
                    <span class="block font-body-small text-body-small font-medium text-on-surface">Đính kèm hình ảnh bảng/lớp <span class="text-on-surface-variant">(không bắt buộc)</span></span>
                    <div class="flex flex-wrap gap-sm">
                        <div v-for="(pv, i) in previews" :key="pv.url" class="relative h-20 w-20 overflow-hidden rounded-lg border border-outline-variant">
                            <img :src="pv.url" :alt="pv.name" class="h-full w-full object-cover" />
                            <button type="button" class="absolute right-0.5 top-0.5 rounded-full bg-black/60 p-[2px] text-white" aria-label="Bỏ ảnh" @click="removePreview(i)">
                                <span class="material-symbols-outlined text-[14px]">close</span>
                            </button>
                        </div>
                        <label class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant text-on-surface-variant hover:border-primary-container hover:text-primary">
                            <span class="material-symbols-outlined" aria-hidden="true">add_a_photo</span>
                            <span class="text-xs font-semibold">Thêm ảnh</span>
                            <input ref="photos" type="file" name="board_images[]" accept="image/*" multiple class="sr-only" @change="syncPreviews" />
                        </label>
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant">JPG, PNG, WEBP — tối đa 10 ảnh, mỗi ảnh ≤ 10MB.</p>
                    <UiErrors :messages="pick(errors, 'board_images')" />
                </div>
            </div>

            <!-- Học sinh cần bổ trợ -->
            <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">warning</span> Học sinh cần bổ trợ</h2>
                    <span class="font-caption text-caption text-on-surface-variant">{{ supports.length }} học sinh</span>
                </div>
                <UiErrors :messages="pick(errors, 'supports')" />
                <p v-if="!supports.length" class="text-center font-body-small text-body-small italic text-on-surface-variant">Chưa có học sinh cần bổ trợ.</p>
                <div v-for="(sup, idx) in supports" :key="sup.id" class="relative space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                    <UiButton variant="ghost" icon="delete" title="Xóa" aria-label="Xóa" class="absolute right-xs top-xs p-xs hover:bg-error-container hover:text-error" @click="removeSupport(idx)" />
                    <div class="grid grid-cols-1 gap-sm pr-lg sm:grid-cols-2">
                        <label>
                            <span :class="labelCls">Học sinh</span>
                            <select v-model="sup.student_id" :name="`supports[${idx}][student_id]`" :class="selectCls">
                                <option value="">-- Chọn học sinh --</option>
                                <option v-for="st in students" :key="st.value" :value="String(st.value)">{{ st.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span :class="labelCls">Buổi vắng (Tùy chọn)</span>
                            <select v-model="sup.absence_session" :name="`supports[${idx}][absence_session]`" :class="selectCls">
                                <option value="">-- Chọn buổi vắng --</option>
                                <option v-for="opt in sessionOptions" :key="opt.value" :value="opt.label">{{ opt.label }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span :class="labelCls">Lý do <span class="text-error">*</span></span>
                        <textarea v-model="sup.reason" :name="`supports[${idx}][reason]`" rows="2" placeholder="Mô tả lý do cần bổ trợ..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                    </label>
                    <label class="block">
                        <span :class="labelCls">Kế hoạch xử lý</span>
                        <textarea v-model="sup.action_plan" :name="`supports[${idx}][action_plan]`" rows="2" placeholder="Nhập kế hoạch xử lý..." class="w-full rounded-lg border border-secondary-fixed bg-secondary-fixed/20 px-sm py-xs font-body-small text-body-small"></textarea>
                    </label>
                </div>
                <UiButton variant="secondary" icon="add" class="w-full border-dashed" @click="addSupport()">Thêm học sinh cần bổ trợ</UiButton>
            </div>

            <div class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md text-center">
                <UiButton v-if="!asModal" type="submit" icon="send" class="w-full">Nộp báo cáo</UiButton>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    <span class="font-semibold text-tertiary">Có ảnh đính kèm</span> → hoàn thành ngay.
                    <span class="font-semibold text-error">Không có ảnh</span> →
                    <template v-if="confirmMode === 'teacher' || confirmMode === 'creator'">chờ Admin xác nhận.</template>
                    <template v-else>lớp chưa có GV chính và chưa gắn đầu việc được giao — cần đính kèm ít nhất 1 ảnh.</template>
                </p>
            </div>
        </UiForm>
    </template>
</template>
