<script setup>
/**
 * Bảng KPI tự động — chỉ số tính từ dữ liệu thật trong kỳ được chọn (KpiBoardService).
 * Phạm vi Công việc "Của tôi" → chỉ KPI của chính mình; "Chi nhánh" → nhân sự chi nhánh mình.
 */
import { urlWith } from '@/lib/url';

defineOptions({ layout: { title: 'Bảng KPI tự động' } });

defineProps({
    staff: { type: Object, required: true },
    staffOptions: { type: Array, default: () => [] },
    month: { type: String, required: true },
    monthTo: { type: String, required: true },
    periodLabel: { type: String, default: '' },
    canSeeStaff: { type: Boolean, default: false },
});

const columns = [
    ['retention', 80],
    ['attendance', 85],
    ['homework', 70],
    ['tasks', 80],
];
const percent = (value) => new Intl.NumberFormat('vi-VN', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) + '%';
</script>

<template>
    <UiPageHeader title="Bảng KPI tự động" description="Theo dõi các chỉ số hiệu suất chính của nhân sự giảng dạy, tính tự động từ điểm danh, bài tập và công việc.">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="urlWith({ export: 1, page: null })" native>Xuất Excel KPI</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :search="false" :action="route('tasks.kpi-dashboard')">
        <UiSelect v-if="canSeeStaff" name="user_id" label="Chọn nhân sự" :options="staffOptions" placeholder="Tất cả nhân sự" />
        <UiDateRange label="Kỳ báo cáo" type="month" from="month" to="month_to" :from-value="month" :to-value="monthTo" />
        <span class="self-center font-body-small text-body-small text-on-surface-variant sm:col-span-2">{{ periodLabel }}</span>
    </UiFilterBar>
    <UiAlert v-if="!canSeeStaff" type="info" class="mb-md">Bạn đang xem KPI của chính mình. KPI toàn bộ nhân sự chỉ dành cho người có quyền duyệt công việc.</UiAlert>

    <UiDataTable min-width="860px">
        <table>
            <thead>
                <tr>
                    <th>Nhân sự</th>
                    <th class="text-center">Lớp phụ trách</th>
                    <th class="text-right">Tỷ lệ giữ chân học viên</th>
                    <th class="text-right">Tỷ lệ chuyên cần</th>
                    <th class="text-right">Tỷ lệ hoàn thành bài tập</th>
                    <th class="text-right">Hoàn thành công việc</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in staff.data" :key="row.id" :data-user-id="row.id">
                    <td>
                        <div class="flex items-center gap-sm">
                            <UiAvatar :name="row.name" />
                            <div>
                                <div class="font-semibold text-on-surface">{{ row.name }}</div>
                                <div class="font-code text-caption text-on-surface-variant">{{ row.code }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="text-center font-code">{{ row.classes }}</td>
                    <td v-for="[key, threshold] in columns" :key="key" class="text-right">
                        <span v-if="row[key] === null || row[key] === undefined" class="font-body-small text-body-small italic text-on-surface-variant" title="Không có dữ liệu trong kỳ">Chưa có dữ liệu</span>
                        <template v-else>
                            <span :class="['font-code font-semibold', row[key] < threshold ? 'text-error' : 'text-on-surface']">{{ percent(row[key]) }}</span>
                            <span class="block font-caption text-caption text-on-surface-variant">{{ row[key + '_detail'] }}</span>
                        </template>
                    </td>
                </tr>
                <tr v-if="!staff.data.length">
                    <td colspan="6">
                        <UiEmptyState icon="insights" title="Chưa có nhân sự để tính KPI" description="Bảng KPI theo dõi giáo viên, trợ giảng và học vụ đang hoạt động." />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer>
            <UiPagination :paginator="staff" unit="nhân sự" />
            <p class="px-md pb-md font-caption text-caption text-on-surface-variant">
                Chuyên cần = lượt có mặt/đi muộn ÷ lượt điểm danh trong kỳ (lớp phụ trách). Bài tập = bài học viên nộp ÷ (bài giao có hạn trong kỳ × sĩ số). Công việc = việc có hạn trong kỳ đã hoàn thành. Giữ chân = học viên
                chưa thôi học ÷ học viên đã vào lớp.
            </p>
        </template>
    </UiDataTable>
</template>
