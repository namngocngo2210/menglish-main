<script setup>
/**
 * Nhập khách từ Excel — bước 2 (xem trước + lỗi từng dòng). Dùng chung cho trang đầy đủ và modal.
 * preview: { file_name, branch_name, assigned_user_name, valid_count, error_count, rows[] } (CrmImportController::create).
 * Slot `actions`: nút Hủy / Nhập ở đầu bảng (trang đầy đủ); trong modal nút nằm ở chân modal.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    preview: { type: Object, required: true },
});

const show = ref('all');
const errorRows = computed(() => props.preview.rows.filter((row) => row.errors.length).sort((a, b) => a.line - b.line));
const filters = computed(() => [
    { key: 'all', label: `Tất cả (${props.preview.rows.length})` },
    { key: 'error', label: `Dòng lỗi (${props.preview.error_count})` },
    { key: 'valid', label: `Hợp lệ (${props.preview.valid_count})` },
]);
const visible = (row) => show.value === 'all' || show.value === (row.errors.length ? 'error' : 'valid');
</script>

<template>
    <div class="space-y-md">
        <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
            <UiStatCard label="Tổng số dòng" :value="preview.rows.length" icon="table_rows" />
            <UiStatCard label="Hợp lệ — sẽ nhập" :value="preview.valid_count" tone="success" icon="check_circle" />
            <UiStatCard label="Lỗi — bỏ qua" :value="preview.error_count" tone="error" icon="error" />
        </div>

        <!-- Lỗi hiện NGAY khi xem trước (trước khi bấm Nhập), theo thứ tự dòng trong file, để sửa file rồi tải lại. -->
        <div v-if="preview.error_count > 0" class="rounded-xl border border-l-4 border-error/30 border-l-error bg-error-container/40 p-md" data-import-errors>
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div>
                    <h3 class="flex items-center gap-xs font-body-base text-body-base font-bold text-error">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">error</span>
                        {{ preview.error_count }} dòng lỗi sẽ bị bỏ qua nếu nhập bây giờ
                    </h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">Nên sửa các dòng này trong file Excel rồi chọn "Hủy, chọn file khác" để tải lại, trước khi bấm Nhập.</p>
                </div>
                <UiButton variant="secondary" size="sm" icon="download" :href="route('crm.import.errors')" native>Tải các dòng lỗi (Excel)</UiButton>
            </div>
            <ul class="mt-sm max-h-64 space-y-1 overflow-y-auto font-body-small text-body-small">
                <li v-for="row in errorRows" :key="row.line" class="flex flex-wrap gap-x-sm">
                    <span class="font-code font-semibold text-error">Dòng {{ row.line }}</span>
                    <span class="font-semibold text-on-surface">{{ row.name || '(không có tên)' }}<template v-if="row.phone"> · <span class="font-code">{{ row.phone }}</span></template></span>
                    <span class="text-on-surface-variant">— {{ row.errors.join('; ') }}</span>
                </li>
            </ul>
        </div>

        <UiDataTable min-width="1000px">
            <template #header>
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">Xem trước: {{ preview.file_name }}</h2>
                    <p class="text-caption text-on-surface-variant">Chi nhánh: <strong>{{ preview.branch_name }}</strong> · Người phụ trách mặc định: <strong>{{ preview.assigned_user_name }}</strong></p>
                </div>
                <slot name="actions" />
            </template>
            <div v-if="preview.error_count > 0" class="flex flex-wrap gap-xs px-md pt-sm" role="group" aria-label="Lọc dòng xem trước">
                <button
                    v-for="f in filters"
                    :key="f.key"
                    type="button"
                    :class="['rounded-full border px-sm py-1 font-body-small text-body-small font-semibold', show === f.key ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant']"
                    @click="show = f.key"
                >{{ f.label }}</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Dòng</th>
                        <th>Họ tên</th>
                        <th>SĐT</th>
                        <th>Phụ huynh</th>
                        <th>Email</th>
                        <th>Nguồn</th>
                        <th>Khóa quan tâm</th>
                        <th>Người phụ trách</th>
                        <th>Kết quả kiểm tra</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview.rows" v-show="visible(row)" :key="row.line" :class="row.errors.length ? '!bg-error-container [&>td:first-child]:border-l-4 [&>td:first-child]:border-error' : ''">
                        <td class="font-code">{{ row.line }}</td>
                        <td class="font-semibold">{{ row.name ?? '—' }}</td>
                        <td class="font-code">{{ row.phone ?? '—' }}</td>
                        <td>{{ row.parent_name ?? '' }}<div class="font-code text-caption">{{ row.parent_phone ?? '' }}</div></td>
                        <td>{{ row.email ?? '' }}</td>
                        <td>{{ row.source ?? '' }}</td>
                        <td>{{ row.course_interest ?? '' }}</td>
                        <td>{{ row.owner_name || preview.assigned_user_name }}</td>
                        <td>
                            <UiBadge v-if="!row.errors.length" color="success">Hợp lệ</UiBadge>
                            <ul v-else class="list-disc pl-md text-caption text-error">
                                <li v-for="(error, i) in row.errors" :key="i">{{ error }}</li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
