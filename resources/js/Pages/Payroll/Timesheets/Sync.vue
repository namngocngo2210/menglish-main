<script setup>
/**
 * Lịch sử đồng bộ chấm công (mockup epic-7/lich-su-dong-bo-cham-cong): các đợt đồng bộ AppSheet / máy chấm công,
 * lọc theo khoảng ngày + trạng thái; chi tiết lỗi từng đợt mở trong hộp thoại (kèm "Xuất file Excel lỗi").
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { formatNumber } from '@/lib/format';

defineOptions({ layout: { title: 'Lịch sử đồng bộ chấm công' } });

defineProps({
    syncLogs: { type: Object, required: true },
    hasAnyLog: { type: Boolean, default: false },
    statusOptions: { type: Array, default: () => [] },
});

const statusStyles = {
    success: ['check_circle', 'text-tertiary'],
    partial: ['warning', 'text-warning'],
    failed: ['error', 'text-error'],
};
const styleOf = (status) => statusStyles[status] ?? ['help', 'text-on-surface-variant'];
const errorsOpen = ref(null);
const num = (v) => formatNumber(v, 0);
</script>

<template>
    <div>
        <UiPageHeader title="Lịch sử đồng bộ chấm công" description="Theo dõi các đợt đồng bộ dữ liệu chấm công tự động (AppSheet / máy chấm công) vào hệ thống.">
            <template #actions>
                <UiButton variant="secondary" icon="refresh" :href="urlWith()">Làm mới</UiButton>
                <span title="Chưa kết nối AppSheet / máy chấm công — chưa thể đồng bộ">
                    <UiButton icon="sync" disabled aria-disabled="true">Đồng bộ ngay</UiButton>
                </span>
            </template>
        </UiPageHeader>

        <UiAlert v-if="!hasAnyLog" type="info" title="Chưa kết nối nguồn đồng bộ" class="mb-lg">
            Hệ thống hiện <strong>chưa tích hợp</strong> AppSheet hay thiết bị vân tay / FaceID nào, nên chưa có đợt đồng bộ nào và nút
            "Đồng bộ ngay" đang khóa. Chấm công giáo viên đang được ghi nhận qua <strong>check-in theo buổi học</strong> trên cổng giáo viên
            và <strong>chấm công thủ công</strong> của Học vụ (xem tại
            <Link :href="route('payroll.timesheets.teachers')" class="font-semibold underline">Chi tiết chấm công giáo viên</Link>).
        </UiAlert>

        <UiFilterBar :search="false" submit-label="Lọc dữ liệu">
            <UiDateRange label="Khoảng ngày" />
            <UiSelect name="status" label="Trạng thái" :options="statusOptions" placeholder="Tất cả trạng thái" />
        </UiFilterBar>

        <UiDataTable min-width="900px">
            <table>
                <thead>
                    <tr>
                        <th>Thời điểm chạy</th>
                        <th>Nguồn / Cơ sở</th>
                        <th class="text-center">Tổng số dòng</th>
                        <th class="text-center">Thành công</th>
                        <th class="text-center">Lỗi</th>
                        <th class="text-center">
                            <span class="inline-flex items-center gap-xs">Bỏ qua
                                <span class="material-symbols-outlined cursor-help text-[16px]" title="Hệ thống bỏ qua không ghi đè dữ liệu của các nhân sự đã chốt kỳ lương.">info</span>
                            </span>
                        </th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in syncLogs.data" :key="log.id">
                        <td class="font-mono">{{ log.created_at }}</td>
                        <td>
                            <span class="block font-semibold">{{ log.device_name }}</span>
                            <span class="block font-body-small text-body-small text-on-surface-variant">{{ log.branch }}</span>
                        </td>
                        <td class="text-center font-mono">{{ num(log.records_count) }}</td>
                        <td class="text-center font-mono text-tertiary">{{ num(log.matched_count) }}</td>
                        <td :class="['text-center font-mono', log.failed_count ? 'text-error font-semibold' : '']">{{ num(log.failed_count) }}</td>
                        <td class="text-center font-mono">{{ num(log.skipped_count) }}</td>
                        <td>
                            <span :class="['inline-flex items-center gap-xs font-body-medium text-body-medium', styleOf(log.status)[1]]">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ styleOf(log.status)[0] }}</span>{{ log.status_label }}
                            </span>
                            <span v-if="log.status === 'partial' && log.failed_count" class="block font-body-small text-body-small text-on-surface-variant">{{ log.failed_count }} dòng lỗi</span>
                            <span v-else-if="log.status === 'failed' && log.error_code" class="block font-body-small text-body-small text-on-surface-variant">{{ log.error_code }}</span>
                        </td>
                        <td class="text-right">
                            <template v-if="log.has_errors">
                                <UiButton variant="ghost" size="sm" icon="visibility" @click="errorsOpen = log.id">Xem chi tiết lỗi</UiButton>
                                <!-- Chi tiết lỗi từng đợt đồng bộ: mở trong hộp thoại thay vì bung dòng ngay dưới bảng. -->
                                <UiModal :show="errorsOpen === log.id" :title="`Chi tiết lỗi đồng bộ lúc ${log.created_at}`" max-width="3xl" class="text-left" @close="errorsOpen = null">
                                    <template v-if="log.error_rows.length">
                                        <p class="mb-sm flex items-center gap-xs font-body-semibold text-body-semibold text-warning">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>{{ log.error_rows.length }} dòng lỗi · {{ log.device_name }}
                                        </p>
                                        <UiDataTable min-width="560px">
                                            <table>
                                                <thead><tr><th>Mã NV</th><th>Tên nhân viên</th><th>Mã lỗi</th><th>Nội dung chi tiết</th></tr></thead>
                                                <tbody>
                                                    <tr v-for="(row, i) in log.error_rows" :key="i">
                                                        <td class="font-mono">{{ row.employee_code ?? '—' }}</td>
                                                        <td>{{ row.employee_name ?? '—' }}</td>
                                                        <td><UiBadge color="error">{{ row.code ?? 'ERROR' }}</UiBadge></td>
                                                        <td>{{ row.message }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </UiDataTable>
                                    </template>
                                    <UiAlert v-else type="error">
                                        <p class="font-semibold">{{ log.error_code || 'Lỗi hệ thống' }}</p>
                                        <p class="mt-1">{{ log.error_message }}</p>
                                    </UiAlert>
                                    <template v-if="log.error_rows.length" #footer>
                                        <UiButton variant="secondary" icon="download" :href="route('payroll.timesheets.sync-history.errors', log.id)" native>Xuất file Excel lỗi</UiButton>
                                    </template>
                                </UiModal>
                            </template>
                            <span v-else class="font-body-small text-body-small text-on-surface-variant">Không có lỗi</span>
                        </td>
                    </tr>
                    <tr v-if="!syncLogs.data.length">
                        <td colspan="8">
                            <UiEmptyState icon="history" :title="hasAnyLog ? 'Không có đợt đồng bộ khớp bộ lọc' : 'Chưa có lịch sử đồng bộ'"
                                          :description="hasAnyLog ? 'Thử đổi khoảng ngày hoặc trạng thái.' : 'Màn này sẽ hiển thị các đợt đồng bộ thật khi có tích hợp AppSheet / máy chấm công.'" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="syncLogs" unit="đợt đồng bộ" /></template>
        </UiDataTable>
    </div>
</template>
