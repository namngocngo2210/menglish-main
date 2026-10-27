<script setup>
/**
 * Đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2): mỗi khối một bảng mã đề (kỳ, số câu, thời gian, trạng thái,
 * số lượt / điểm TB để so giữa các mã đề), import file Excel + Word hoặc ZIP chạy nền: trang tự tải lại mỗi 3 giây
 * khi còn lần import đang chờ / đang chạy, xong thì hiện kết quả từng đề.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { confirmDialog } from '@/lib/confirm';

defineOptions({ layout: { title: 'Đề cố định' } });

const props = defineProps({
    grades: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
    imports: { type: Array, default: () => [] },
});

const STATUS_COLORS = { draft: 'warning', published: 'success', hidden: 'neutral' };
const IMPORT_COLORS = { queued: 'neutral', running: 'info', done: 'success', failed: 'error' };
const scoreText = (score) => (score === null ? '—' : Number(score).toLocaleString('vi-VN', { maximumFractionDigits: 2 }));

// Chọn nhiều đề (mọi khối) để mở / ẩn một lần
const selected = ref([]);
const allIds = computed(() => props.grades.flatMap((g) => g.exams.map((e) => e.id)));
watch(allIds, (ids) => (selected.value = selected.value.filter((id) => ids.includes(id))));
function gradeIds(g) {
    return g.exams.map((e) => e.id);
}
function gradeAllSelected(g) {
    const ids = gradeIds(g);
    return ids.length > 0 && ids.every((id) => selected.value.includes(id));
}
function toggleGrade(g, event) {
    const ids = gradeIds(g);
    selected.value = event.target.checked ? [...new Set([...selected.value, ...ids])] : selected.value.filter((id) => !ids.includes(id));
}
const BULK = {
    publish: { label: 'Mở cho học viên', verb: 'Mở', icon: 'visibility' },
    hide: { label: 'Ẩn đề', verb: 'Ẩn', icon: 'visibility_off' },
};
const busy = ref(false);
async function bulk(action) {
    if (!selected.value.length) return;
    const ok = await confirmDialog({
        title: `${BULK[action].verb} ${selected.value.length} đề?`,
        message: action === 'publish' ? 'Học viên của khối tương ứng sẽ thấy và làm được các đề này.' : 'Học viên không bắt đầu được lượt mới ở các đề này.',
        confirmLabel: BULK[action].label,
    });
    if (!ok) return;
    router.post(route('question-bank.exams.bulk'), { action, ids: selected.value }, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => (selected.value = []),
    });
}

// Import: bấm là đóng modal ngay; file tải lên ở nền, tiến độ (đang tải lên % → chờ / đang xử lý → hoàn thành /
// thất bại) hiện ở danh sách import phía trên các khối.
const importOpen = ref(false);
const pickedFiles = ref([]);
const upload = ref(null); // { files, percent, error }
function onPick(event) {
    pickedFiles.value = [...(event.target.files ?? [])];
}
function submitImport() {
    if (!pickedFiles.value.length) return;
    const files = pickedFiles.value;
    upload.value = { files: files.map((f) => f.name), percent: 0, error: null };
    importOpen.value = false;
    pickedFiles.value = [];
    router.post(route('question-bank.exams.import'), { files }, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (e) => upload.value && (upload.value.percent = Math.round(e?.percentage ?? 0)),
        onError: (errors) => upload.value && (upload.value.error = errors.files || errors['files.0'] || Object.values(errors)[0] || 'Tải file lên thất bại.'),
        onSuccess: () => (upload.value = null),
        onNetworkError: () => upload.value && (upload.value.error = 'Mất kết nối khi tải file lên.'),
    });
}

const latest = computed(() => props.imports[0] ?? null);
const report = computed(() => latest.value?.report ?? null);
const pending = computed(() => props.imports.some((i) => i.pending));

let timer = null;
watch(
    pending,
    (on) => {
        if (on && !timer && typeof window !== 'undefined') {
            timer = window.setInterval(() => router.reload({ only: ['imports', 'grades'] }), 3000);
        } else if (!on && timer) {
            window.clearInterval(timer);
            timer = null;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => timer && window.clearInterval(timer));
</script>

<template>
    <UiPageHeader title="Đề cố định" description="Nguyên bộ đề theo mã (vd. đề GK1 khối 6 mã 601). Học viên chỉ thấy đề đã mở của khối mình và chọn từng đề để làm.">
        <template v-if="canManage" #actions>
            <UiButton icon="upload_file" :disabled="!!(upload && !upload.error)" @click="importOpen = true">Import đề</UiButton>
        </template>
    </UiPageHeader>

    <UiTabs class="mb-lg">
        <UiTab :href="route('question-bank.index')">Câu hỏi</UiTab>
        <UiTab :href="route('question-bank.blueprints.index')">Khung đề</UiTab>
        <UiTab :href="route('question-bank.exams.index')" active>Đề cố định</UiTab>
        <UiTab :href="route('question-bank.analysis')">Phân tích câu hỏi</UiTab>
    </UiTabs>

    <section v-if="imports.length || upload" class="mb-lg space-y-sm" data-imports>
        <div v-if="upload" class="flex flex-wrap items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm" data-import="uploading">
            <span v-if="!upload.error" class="material-symbols-outlined animate-spin text-[18px] text-primary" aria-hidden="true">progress_activity</span>
            <UiBadge :color="upload.error ? 'error' : 'info'">{{ upload.error ? 'Thất bại' : `Đang tải lên ${upload.percent}%` }}</UiBadge>
            <span class="min-w-0 flex-1 truncate font-body-small text-body-small text-on-surface" :title="upload.files.join(', ')">{{ upload.files.join(', ') }}</span>
            <UiButton v-if="upload.error" size="sm" variant="ghost" @click="upload = null">Đóng</UiButton>
            <p v-if="upload.error" class="w-full font-body-small text-body-small text-error" role="alert">{{ upload.error }}</p>
        </div>
        <div
            v-for="imp in imports"
            :key="imp.id"
            class="flex flex-wrap items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm"
            :data-import="imp.status"
        >
            <span v-if="imp.pending" class="material-symbols-outlined animate-spin text-[18px] text-primary" aria-hidden="true">progress_activity</span>
            <UiBadge :color="IMPORT_COLORS[imp.status] ?? 'neutral'">{{ imp.status_label }}</UiBadge>
            <span class="min-w-0 flex-1 truncate font-body-small text-body-small text-on-surface" :title="imp.files.join(', ')">{{ imp.files.join(', ') }}</span>
            <span class="font-caption text-caption text-on-surface-variant">{{ imp.user }} · {{ imp.created_at }}</span>
            <p v-if="imp.message" class="w-full font-body-small text-body-small text-on-surface-variant">{{ imp.message }}</p>
            <p v-if="imp.stalled" class="w-full font-body-small text-body-small text-error" role="alert">
                Chờ quá 2 phút chưa chạy: tiến trình xử lý nền (queue) có thể chưa bật. Báo quản trị kiểm tra.
            </p>
        </div>
    </section>

    <section v-if="report" class="mb-lg space-y-sm" data-import-report>
        <UiAlert v-if="report.saved.length" type="success" title="Đã nhập (trạng thái Nháp — xem lại rồi bấm Mở cho học viên)">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.saved" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="report.kept.length" type="info" title="Giữ nguyên">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.kept" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="report.warnings.length" type="warning" title="Cần kiểm tra">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.warnings" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="report.errors.length" type="error" title="Không nhập được">
            <ul class="list-disc space-y-0.5 pl-md">
                <li v-for="(line, i) in report.errors" :key="i">{{ line }}</li>
            </ul>
        </UiAlert>
    </section>

    <div v-if="canManage" class="sticky top-0 z-10 mb-md flex flex-wrap items-center gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm" data-bulk-actions>
        <span class="font-body-small text-body-small text-on-surface-variant">Đã chọn {{ selected.length }} đề</span>
        <UiButton v-for="(cfg, action) in BULK" :key="action" size="sm" variant="secondary" :icon="cfg.icon" :disabled="!selected.length || busy" @click="bulk(action)">{{ cfg.label }}</UiButton>
        <UiButton v-if="selected.length" size="sm" variant="ghost" :disabled="busy" @click="selected = []">Bỏ chọn</UiButton>
    </div>

    <section v-for="g in grades" :key="g.grade" class="mb-xl" :aria-label="`Khối ${g.grade}`" :data-grade="g.grade">
        <h2 class="mb-sm font-h3 text-h3 text-on-surface">Khối {{ g.grade }}</h2>
        <UiDataTable min-width="760px">
            <table>
                <thead>
                    <tr>
                        <th v-if="canManage" class="w-10">
                            <input type="checkbox" class="rounded" :checked="gradeAllSelected(g)" :disabled="!g.exams.length" :aria-label="`Chọn tất cả đề khối ${g.grade}`" @change="toggleGrade(g, $event)" />
                        </th>
                        <th>Đề</th>
                        <th class="w-32">Kỳ</th>
                        <th class="w-28 text-right">Câu / phút</th>
                        <th class="w-28">Trạng thái</th>
                        <th class="w-36 text-right">Lượt làm</th>
                        <th class="w-24 text-right">Điểm TB</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="exam in g.exams" :key="exam.id" :data-exam="exam.code">
                        <td v-if="canManage">
                            <input v-model="selected" type="checkbox" :value="exam.id" class="rounded" :aria-label="`Chọn ${exam.label}`" />
                        </td>
                        <td>
                            <Link :href="route('question-bank.exams.show', exam.id)" class="font-semibold text-primary hover:underline">{{ exam.label }}</Link>
                            <div class="font-caption text-caption text-on-surface-variant">{{ exam.title }}</div>
                            <UiBadge v-if="exam.warnings.length" color="warning" :dot="false" class="mt-xs">{{ exam.warnings.length }} cảnh báo</UiBadge>
                        </td>
                        <td>{{ exam.term_label }}</td>
                        <td class="text-right font-code">{{ exam.question_count }} / {{ exam.duration_minutes }}</td>
                        <td><UiBadge :color="STATUS_COLORS[exam.status] ?? 'neutral'">{{ exam.status_label }}</UiBadge></td>
                        <td class="text-right font-code">{{ exam.attempts }}<span class="text-on-surface-variant"> ({{ exam.students }} HV)</span></td>
                        <td class="text-right font-code">{{ scoreText(exam.avg_score) }}</td>
                        <td class="text-right">
                            <UiButton variant="ghost" icon="visibility" :href="route('question-bank.exams.show', exam.id)" :aria-label="`Xem ${exam.label}`" title="Xem đề" />
                        </td>
                    </tr>
                    <tr v-if="!g.exams.length">
                        <td :colspan="canManage ? 8 : 7" class="text-center font-body-small text-body-small text-on-surface-variant">Chưa có đề cố định cho khối {{ g.grade }}.</td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </section>

    <UiModal v-if="canManage" :show="importOpen" title="Import đề cố định" max-width="lg" @close="importOpen = false">
        <form class="space-y-md" @submit.prevent="submitImport">
            <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-lg text-center hover:border-primary-container">
                <span class="material-symbols-outlined text-[32px] text-primary" aria-hidden="true">upload_file</span>
                <span class="font-body-medium text-body-medium text-on-surface">Chọn file .zip, hoặc các file .xlsx + .docx (tối đa 50MB mỗi file)</span>
                <input type="file" name="files[]" accept=".zip,.xlsx,.docx" multiple class="sr-only" required @change="onPick" />
                <span v-if="pickedFiles.length" class="font-body-small text-body-small text-on-surface-variant">Đã chọn: {{ pickedFiles.map((f) => f.name).join(', ') }}</span>
            </label>
            <UiAlert type="info" title="Lưu ý">
                <ul class="list-disc space-y-0.5 pl-md">
                    <li>File Excel đề kèm đáp án: mỗi sheet một mã đề, tiêu đề dạng "ĐỀ KIỂM TRA GIỮA HỌC KÌ I … TIẾNG ANH 6 – MÃ ĐỀ 601".</li>
                    <li>Kèm file Word của đề để giữ gạch chân câu Phát âm (Excel không lưu gạch chân).</li>
                    <li>Đề mới vào trạng thái <b>Nháp</b>. Đề đã mở / đã ẩn giữ nguyên khi import lại.</li>
                    <li>Bấm Import là đóng cửa sổ này; tiến độ (đang tải lên, đang xử lý, hoàn thành / thất bại) hiện ở danh sách đề.</li>
                </ul>
            </UiAlert>
            <div class="flex justify-end gap-sm">
                <UiButton variant="secondary" @click="importOpen = false">Hủy</UiButton>
                <UiButton type="submit" icon="upload_file" :disabled="!pickedFiles.length || !!(upload && !upload.error)">Import</UiButton>
            </div>
        </form>
    </UiModal>
</template>
