<script setup>
/**
 * Nhập học phí từ Excel — 3 bước: Tải file → Xem trước (lỗi từng dòng) → Kết quả.
 * Mở từ "Nhập Excel" → modal: mỗi bước gửi form rồi hiện bước kế tiếp ngay trong modal (bước 2–3 nới rộng 4xl);
 * mở thẳng URL → trang đầy đủ (gửi form bằng Inertia như trang thường).
 */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useRemoteModal } from '@/Components/ui/modalContext';
import { closeRemoteModal, remoteModal } from '@/lib/remoteModal';
import { toast } from '@/lib/toast';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhập học phí từ Excel' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    branches: { type: Array, default: () => [] },
    preview: { type: Object, default: null },
    result: { type: Object, default: null },
    templateHeadings: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    flash: { type: Array, default: () => [] },
});

const modal = useRemoteModal();
const page = usePage();
const step = computed(() => (props.result ? 3 : props.preview ? 2 : 1));
const validCount = computed(() => (props.preview ? props.preview.rows.filter((r) => !r.errors.length).length : 0));
const errorCount = computed(() => (props.preview ? props.preview.rows.length - validCount.value : 0));
const steps = [
    [1, 'Tải file'],
    [2, 'Xem trước'],
    [3, 'Kết quả'],
];
const status = computed(() => (modal ? props.flash.find((f) => f.type === 'success')?.message : null));

const fileInput = ref(null);
const fileName = ref('');
const processing = ref(false);
function onDrop(event) {
    if (!fileInput.value) return;
    fileInput.value.files = event.dataTransfer.files;
    fileName.value = event.dataTransfer.files[0]?.name || '';
}

/**
 * Trong modal: gửi form bằng fetch (header Inertia + X-Remote-Modal), server chuyển hướng về tuition.import
 * → hiện bước kế tiếp ngay trong modal. Trang đầy đủ: router.post như form thường.
 */
async function submit(event) {
    const form = event.target;
    const data = new FormData(form);
    if (!modal) {
        router.post(form.action, data, { preserveScroll: true, onStart: () => (processing.value = true), onFinish: () => (processing.value = false) });
        return;
    }
    processing.value = true;
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            referrer: route('tuition.import'),
            headers: {
                Accept: 'text/html, application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Inertia': 'true',
                'X-Inertia-Version': page.version ?? '',
                'X-Remote-Modal': 'true',
                'X-CSRF-TOKEN': page.props.csrf ?? '',
            },
        });
        if (response.status === 409 || !response.headers.get('X-Inertia')) {
            window.location.href = response.headers.get('X-Inertia-Location') || route('tuition.import');
            return;
        }
        const next = await response.json();
        if (next.component === 'Tuition/Import') {
            remoteModal.props = next.props;
            remoteModal.url = next.url;
            remoteModal.key++;
        } else {
            closeRemoteModal();
            router.visit(next.url);
        }
    } catch {
        toast('Không kết nối được máy chủ, vui lòng kiểm tra mạng.', 'error');
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <component :is="modal ? 'UiModalFrame' : 'div'" v-bind="modal ? { title: 'Nhập học phí hàng loạt', cancel: 'Đóng', size: step > 1 ? '4xl' : null, description: 'Nhập hồ sơ học phí và các khoản đã đóng từ Excel / CSV. Khoản đã đóng tạo phiếu thu chờ Kế toán duyệt.' } : {}">
        <UiPageHeader v-if="!modal" title="Nhập học phí hàng loạt" description="Nhập hồ sơ học phí và các khoản đã đóng từ file Excel (.xlsx) hoặc CSV. Khoản đã đóng tạo phiếu thu chờ Kế toán duyệt.">
            <template #actions>
                <UiButton variant="secondary" icon="download" :href="route('tuition.import.template')" native>Tải file mẫu (.xlsx)</UiButton>
            </template>
        </UiPageHeader>

        <UiAlert v-if="status" type="success" class="mb-md">{{ status }}</UiAlert>

        <div class="space-y-lg">
            <!-- Stepper -->
            <ol class="flex items-center justify-center gap-md rounded-xl bg-surface-container-low p-md">
                <li v-for="[n, label] in steps" :key="n" class="flex items-center gap-sm">
                    <span
                        :class="[
                            'flex h-8 w-8 items-center justify-center rounded-full border-2 font-body-medium',
                            n === step ? 'border-primary-container text-primary' : n < step ? 'border-tertiary bg-tertiary text-white' : 'border-outline-variant text-on-surface-variant',
                        ]"
                        >{{ n < step ? '✓' : n }}</span
                    >
                    <span :class="['font-body-medium', n === step ? 'text-primary' : 'text-on-surface-variant']">{{ label }}</span>
                    <span v-if="n < 3" class="hidden h-px w-16 bg-outline-variant sm:block" aria-hidden="true"></span>
                </li>
            </ol>

            <!-- Bước 1: tải file -->
            <form
                v-if="step === 1"
                :id="(modal ? 'modal-' : '') + 'tuition-import-form'"
                method="POST"
                :action="route('tuition.import.store')"
                enctype="multipart/form-data"
                :class="['space-y-md', modal ? '' : 'rounded-xl border border-outline-variant bg-surface-container-lowest p-lg']"
                @submit.prevent="submit"
            >
                <UiSelect name="branch_id" :id="modal ? 'modal-tuition-import-branch' : null" label="Chọn chi nhánh" :options="branches" placeholder="-- Vui lòng chọn chi nhánh --" required :error="errors.branch_id" />

                <label class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-xl text-center hover:border-primary-container" @dragover.prevent @drop.prevent="onDrop">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-fixed text-primary">
                        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">upload_file</span>
                    </span>
                    <span class="font-h3 text-h3 text-on-surface">Kéo thả file vào đây hoặc nhấn để chọn</span>
                    <span class="font-body-small text-body-small text-on-surface-variant">Hỗ trợ .xlsx, .xls, .csv (tối đa 10MB, 1.000 dòng)</span>
                    <input ref="fileInput" type="file" name="excel_file" accept=".xlsx,.xls,.csv" class="sr-only" @change="fileName = $event.target.files[0]?.name || ''" />
                    <span v-if="fileName" class="rounded-lg bg-tertiary-fixed/40 px-sm py-xs font-body-small text-body-small text-on-tertiary-fixed-variant">{{ 'Đã chọn: ' + fileName }}</span>
                </label>

                <UiAlert type="info" title="Cột trong file mẫu">
                    {{ templateHeadings }}.
                    <ul class="mt-xs list-disc space-y-0.5 pl-md">
                        <li>Học viên chưa có hồ sơ học phí: bắt buộc "Học phí niêm yết" và "Hạn đóng" (dd/mm/yyyy).</li>
                        <li>Học viên đã có hồ sơ: chỉ nhập "Số tiền đã đóng" — không ghi đè giá trị hợp đồng.</li>
                        <li>Hình thức: <code>tien_mat</code>, <code>chuyen_khoan</code> (bắt buộc mã giao dịch, không trùng).</li>
                    </ul>
                </UiAlert>

                <p v-if="errors.excel_file" class="font-body-small text-body-small text-error" role="alert">{{ errors.excel_file }}</p>

                <div v-if="!modal" class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :href="route('tuition.students')">Hủy</UiButton>
                    <UiButton type="submit" icon="preview" :disabled="processing">Kiểm tra &amp; xem trước</UiButton>
                </div>
            </form>

            <!-- Bước 2: xem trước -->
            <template v-else-if="step === 2">
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiStatCard label="Tổng số dòng" :value="preview.rows.length" icon="table_rows" />
                    <UiStatCard label="Dòng hợp lệ" :value="validCount" tone="success" icon="check_circle" />
                    <UiStatCard label="Dòng lỗi (bỏ qua)" :value="errorCount" tone="error" icon="error" />
                </div>

                <UiDataTable min-width="980px">
                    <template #header>
                        <div>
                            <h2 class="font-h3 text-h3">{{ preview.file_name }}</h2>
                            <p class="font-body-small text-body-small text-on-surface-variant">Chi nhánh: {{ preview.branch_name }}</p>
                        </div>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Dòng</th>
                                <th>Học viên</th>
                                <th>Hồ sơ học phí</th>
                                <th class="text-right">Phải thu</th>
                                <th>Hạn đóng</th>
                                <th class="text-right">Đã đóng</th>
                                <th>Hình thức / Mã GD</th>
                                <th>Kết quả kiểm tra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.rows" :key="row.line" :class="{ '!bg-error-container [&>td:first-child]:border-l-4 [&>td:first-child]:border-error': row.errors.length }">
                                <td class="font-code">{{ row.line }}</td>
                                <td>
                                    <div class="font-body-medium">{{ row.student_name ?? '—' }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">{{ row.student_code }}</div>
                                </td>
                                <td>
                                    <template v-if="row.creates_tuition">
                                        <UiBadge color="info">Tạo mới</UiBadge>
                                        <div v-if="row.class_code" class="font-caption text-caption">Lớp {{ row.class_code }}</div>
                                    </template>
                                    <span v-else class="text-on-surface-variant">Đã có</span>
                                </td>
                                <td><UiMoney :value="row.final_amount" /></td>
                                <td class="font-code">{{ row.due_date ?? '—' }}</td>
                                <td><UiMoney :value="row.paid_amount || null" /></td>
                                <td class="font-caption text-caption">
                                    {{ row.method_label }}
                                    <div v-if="row.transaction_code" class="font-code">{{ row.transaction_code }}</div>
                                </td>
                                <td>
                                    <UiBadge v-if="!row.errors.length" color="success">Hợp lệ</UiBadge>
                                    <ul v-else class="list-disc space-y-0.5 pl-md font-caption text-caption text-error">
                                        <li v-for="(error, i) in row.errors" :key="i">{{ error }}</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <form :id="(modal ? 'modal-' : '') + 'tuition-import-confirm'" method="POST" :action="route('tuition.import.confirm')" class="flex flex-wrap items-center justify-between gap-sm" @submit.prevent="submit">
                    <input type="hidden" name="token" :value="preview.token" />
                    <p class="font-body-small text-body-small text-on-surface-variant">Chỉ {{ validCount }} dòng hợp lệ được nhập; dòng lỗi bị bỏ qua — sửa file rồi tải lên lại.</p>
                    <div v-if="!modal" class="flex gap-sm">
                        <UiButton variant="secondary" :href="route('tuition.import')">Chọn file khác</UiButton>
                        <UiButton type="submit" icon="cloud_done" :disabled="validCount === 0 || processing">Nhập {{ validCount }} dòng hợp lệ</UiButton>
                    </div>
                </form>
            </template>

            <!-- Bước 3: kết quả -->
            <div v-else :class="['space-y-md', modal ? '' : 'rounded-xl border border-outline-variant bg-surface-container-lowest p-lg']">
                <h2 class="font-h3 text-h3">Kết quả nhập file {{ result.file_name }}</h2>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-4">
                    <UiStatCard label="Hồ sơ học phí tạo mới" :value="result.tuitions" tone="success" />
                    <UiStatCard label="Phiếu thu chờ duyệt" :value="result.receipts" tone="primary" />
                    <UiStatCard label="Dòng lỗi bỏ qua" :value="result.skipped" tone="warning" />
                    <UiStatCard label="Lỗi khi ghi" :value="result.failed.length" tone="error" />
                </div>
                <UiAlert v-if="result.failed.length" type="error" title="Các dòng không ghi được">
                    <ul class="list-disc pl-md">
                        <li v-for="failed in result.failed" :key="failed.line">Dòng {{ failed.line }}: {{ failed.error }}</li>
                    </ul>
                </UiAlert>
                <div v-if="!modal" class="flex flex-wrap justify-end gap-sm">
                    <UiButton variant="secondary" icon="upload_file" :href="route('tuition.import')">Nhập file khác</UiButton>
                    <UiButton v-if="result.receipts > 0" variant="secondary" icon="fact_check" :href="route('tuition.receipts.approve', { status: 'pending' })">Duyệt phiếu thu</UiButton>
                    <UiButton icon="list" :href="route('tuition.students')">Danh sách thu phí</UiButton>
                </div>
            </div>
        </div>

        <template v-if="modal" #footer>
            <template v-if="step === 1">
                <UiButton variant="secondary" icon="download" :href="route('tuition.import.template')" native>Tải file mẫu (.xlsx)</UiButton>
                <UiButton type="submit" form="modal-tuition-import-form" icon="preview" :disabled="processing">Kiểm tra &amp; xem trước</UiButton>
            </template>
            <template v-else-if="step === 2">
                <UiButton variant="secondary" :href="route('tuition.import')" modal="lg">Chọn file khác</UiButton>
                <UiButton type="submit" form="modal-tuition-import-confirm" icon="cloud_done" :disabled="validCount === 0 || processing">Nhập {{ validCount }} dòng hợp lệ</UiButton>
            </template>
            <template v-else>
                <UiButton variant="secondary" icon="upload_file" :href="route('tuition.import')" modal="lg">Nhập file khác</UiButton>
                <UiButton v-if="result.receipts > 0" variant="secondary" icon="fact_check" :href="route('tuition.receipts.approve', { status: 'pending' })">Duyệt phiếu thu</UiButton>
                <UiButton icon="list" :href="route('tuition.students')">Danh sách thu phí</UiButton>
            </template>
        </template>
    </component>
</template>
