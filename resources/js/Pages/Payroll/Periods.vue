<script setup>
/**
 * Danh sách kỳ lương — vào từng kỳ để xem bảng lương (mockup epic-7/danh-sach-bang-luong-theo-ky).
 * "Tạo kỳ lương mới" mở hộp thoại trong trang; lỗi (vd. trùng tháng) hiện ngay trong hộp thoại và trên đầu trang.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Danh sách bảng lương theo kỳ' } });

defineProps({
    periods: { type: Object, required: true },
    allPeriods: { type: Array, default: () => [] },
    defaultMonth: { type: Number, required: true },
    defaultYear: { type: Number, required: true },
});

const page = usePage();
const monthError = computed(() => page.props.errors?.month ?? null);
const creating = ref(false);

const statusColors = { draft: 'info', reviewing: 'warning', approved: 'success', paid: 'secondary' };
const statusTexts = { draft: 'Đang tính', reviewing: 'Đang soát', approved: 'Đã chốt', paid: 'Đã trả' };
const statusOptions = Object.entries(statusTexts).map(([value, label]) => ({ value, label }));
const monthOptions = Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `Tháng ${String(i + 1).padStart(2, '0')}` }));

function openPeriod(event) {
    if (event.target.value) router.visit(route('payroll.periods.show', event.target.value));
}
function submitFilter(event) {
    event.target.form?.requestSubmit();
}
</script>

<template>
    <UiPageHeader title="Danh sách bảng lương theo kỳ" description="Quản lý, tổng hợp chấm công và chốt lương giáo viên, nhân sự theo từng kỳ.">
        <template v-if="can('payroll.create')" #actions>
            <UiButton icon="add_circle" @click="creating = true">Tạo kỳ lương mới</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="monthError" type="error" class="mb-md">{{ monthError }}</UiAlert>

    <UiAlert type="warning" title="Lưu ý chốt bảng lương định kỳ" class="mb-lg">
        Hoàn tất đối soát <Link :href="route('payroll.timesheets.teachers')" class="font-semibold underline">chấm công giáo viên</Link>,
        chốt KPI (bậc KPI giữ HS, KPI tự do, <Link :href="route('kpi.monthly')" class="font-semibold underline">đánh giá KPI Học vụ</Link>) và
        xử lý <Link :href="route('penalties.index')" class="font-semibold underline">biên bản vi phạm</Link> trước khi bấm "Chốt bảng lương".
    </UiAlert>

    <UiFilterBar :action="route('payroll.periods.index')" placeholder="Tìm kỳ lương / giáo viên...">
        <!-- Chọn kỳ để mở thẳng bảng lương (không thuộc tham số lọc) -->
        <UiSelect label="Mở kỳ lương" :options="allPeriods" placeholder="-- Mở kỳ lương --" @change="openPeriod" />
        <UiSelect name="status" label="Trạng thái" :options="statusOptions" placeholder="Mọi trạng thái" @change="submitFilter" />
    </UiFilterBar>

    <UiDataTable min-width="960px">
        <template #header>
            <h3 class="font-h3 text-h3 text-on-surface">Các kỳ tính lương</h3>
            <span class="font-mono font-body-small text-body-small text-on-surface-variant">{{ periods.total }} kỳ lương</span>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Mã kỳ</th>
                    <th>Tên kỳ tính lương</th>
                    <th>Khoảng thời gian</th>
                    <th class="text-center">Số nhân sự</th>
                    <th class="text-right">Tổng giờ dạy</th>
                    <th class="text-right">Tổng chi lương</th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in periods.data" :key="p.id">
                    <td class="font-code text-code font-semibold">{{ p.code }}</td>
                    <td class="font-semibold">{{ p.title }}</td>
                    <td class="font-code text-code">{{ formatDate(p.start_date) }} – {{ formatDate(p.end_date) }}</td>
                    <td class="text-center">{{ p.staff }} người</td>
                    <td class="text-right font-mono">{{ p.hours }}h</td>
                    <td><UiMoney :value="p.amount" suffix="đ" /></td>
                    <td><UiBadge :color="statusColors[p.status] ?? 'neutral'">{{ statusTexts[p.status] ?? p.status_label }}</UiBadge></td>
                    <td class="text-right">
                        <UiButton variant="ghost" size="sm" icon="visibility" :href="route('payroll.periods.show', p.id)">Chi tiết bảng lương</UiButton>
                    </td>
                </tr>
                <tr v-if="!periods.data.length">
                    <td colspan="8"><UiEmptyState icon="account_balance_wallet" title="Chưa có kỳ tính lương nào" description="Tạo kỳ lương mới để hệ thống tổng hợp chấm công, KPI, hoa hồng và phạt." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="periods" unit="kỳ lương" /></template>
    </UiDataTable>

    <UiModal v-if="can('payroll.create')" :show="creating" title="Tạo kỳ tính lương mới" max-width="md" data-modal="new-period" @close="creating = false">
        <UiForm id="new-period-form" :action="route('payroll.periods.store')" method="post" class="space-y-md">
            <div class="grid grid-cols-2 gap-md">
                <UiSelect name="month" label="Tháng tính lương" required :value="defaultMonth" :options="monthOptions" />
                <UiInput type="number" name="year" label="Năm" required :value="defaultYear" min="2025" max="2100" />
            </div>
            <UiAlert type="info">Hệ thống tự quét chấm công hợp lệ, KPI, hoa hồng (gate kép), thưởng tái tục và phạt quá hạn theo công thức Q3.</UiAlert>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-period-form">Khởi tạo &amp; Tính toán</UiButton>
        </template>
    </UiModal>
</template>
