<script setup>
/**
 * Lương của tôi (mockup epic-7/luong-cua-toi-teacher-portal) — chỉ hiện kỳ lương đã duyệt / đã chi trả (A6):
 * tổng thu nhập (so với tháng trước), khoản trừ, thực nhận, chi tiết thu nhập / khoản trừ, buổi dạy hợp lệ (lọc theo lớp, xem thêm).
 * "Tải phiếu lương": in bản phiếu lương Blade (printHtml) — ẩn trên màn hình, chỉ hiện khi in.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { money } from './format';

defineOptions({ layout: { title: 'Lương của tôi' } });

const props = defineProps({
    record: { type: Object, default: null },
    periodOptions: { type: Array, default: () => [] },
    penalties: { type: Array, default: () => [] },
    timesheets: { type: Array, default: () => [] },
    printHtml: { type: String, default: null },
});

const description = computed(() =>
    props.record ? `Kỳ lương hiện tại: ${props.record.period_status_label} — ${props.record.period_title}` : 'Tra cứu phiếu lương cá nhân đã được duyệt.',
);
const deductionRows = computed(() =>
    (props.record?.deduction_lines ?? []).filter((line) => {
        if (line.key === 'penalty_deduction' && props.penalties.length) return false;
        if (Number(line.amount) === 0 && line.key !== 'penalty_deduction') return false;
        return true;
    }),
);
const classes = computed(() => [...new Set(props.timesheets.map((ts) => ts.class).filter(Boolean))]);
const classOptions = computed(() => classes.value.map((c) => ({ value: c, label: c })));
const showAll = ref(false);
const cls = ref('');

function changePeriod(event) {
    router.get(route('portal.my-salary'), { period_id: event.target.value });
}
const printPayslip = () => window.print();
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- bản in do server render bằng Blade (đã escape) -->
    <div v-if="printHtml" v-html="printHtml"></div>

    <UiPageHeader title="Lương của tôi" :description="description">
        <template v-if="periodOptions.length || record" #actions>
            <form v-if="periodOptions.length" method="GET" :action="route('portal.my-salary')" @submit.prevent>
                <UiSelect name="period_id" aria-label="Chọn kỳ lương" :options="periodOptions" :value="record?.payroll_period_id ?? ''" @change="changePeriod" />
            </form>
            <UiButton v-if="record" variant="secondary" icon="download" @click="printPayslip">Tải phiếu lương</UiButton>
        </template>
    </UiPageHeader>

    <UiEmptyState v-if="!record" icon="wallet" title="Chưa có kỳ lương đã duyệt" description="Phiếu lương chỉ hiển thị sau khi Giám đốc duyệt bảng lương của kỳ." />
    <template v-else>
        <div class="mb-lg grid grid-cols-1 gap-md md:grid-cols-3">
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold text-on-surface-variant">Tổng thu nhập</h3><span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono text-on-surface">{{ money(record.gross_income) }} đ</p>
                <p v-if="record.change !== null" :class="['mt-xs inline-flex items-center gap-xs font-body-small text-body-small', record.change >= 0 ? 'text-tertiary' : 'text-error']">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ record.change >= 0 ? 'trending_up' : 'trending_down' }}</span>{{ record.change >= 0 ? '+' : '' }}{{ record.change }}% so với tháng trước
                </p>
                <p v-else class="mt-xs font-body-small text-body-small text-on-surface-variant">Chưa có kỳ trước để so sánh</p>
            </div>
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold text-on-surface-variant">Tổng khoản trừ</h3><span class="material-symbols-outlined text-error" aria-hidden="true">money_off</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono text-error">{{ money(record.total_deductions) }} đ</p>
                <p class="mt-xs inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>Bao gồm phạt và các khoản khác</p>
            </div>
            <div class="rounded-xl bg-primary-container p-lg text-white">
                <div class="flex items-start justify-between"><h3 class="font-body-semibold text-body-semibold">Thực nhận</h3><span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span></div>
                <p class="mt-sm font-h2 text-h2 font-mono">{{ money(record.net_salary) }} đ</p>
                <p class="mt-xs font-body-small text-body-small text-white/80">{{ record.period_status === 'paid' ? 'Đã chi trả qua tài khoản ngân hàng' : 'Đã duyệt — chờ chi trả qua tài khoản ngân hàng' }}</p>
            </div>
        </div>

        <div class="mb-lg grid grid-cols-1 gap-lg lg:grid-cols-2">
            <UiDataTable>
                <template #header><h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">account_balance</span>Chi tiết thu nhập</h3></template>
                <table>
                    <thead><tr><th>Hạng mục</th><th class="text-right">Số lượng</th><th class="text-right">Thành tiền (đ)</th></tr></thead>
                    <tbody>
                        <tr v-for="(line, i) in record.earning_lines" :key="`e-${i}`">
                            <td>{{ line.label }}<span v-if="line.hint" class="block font-caption text-caption text-on-surface-variant">{{ line.hint }}</span></td>
                            <td class="text-right font-mono">{{ line.quantity }}</td>
                            <td class="text-right font-mono">{{ money(line.amount) }}</td>
                        </tr>
                        <tr class="font-semibold"><td colspan="2">Tổng thu nhập</td><td class="text-right font-mono">{{ money(record.gross_income) }}</td></tr>
                    </tbody>
                </table>
            </UiDataTable>

            <UiDataTable>
                <template #header><h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">money_off</span>Các khoản trừ</h3></template>
                <table>
                    <thead><tr><th>Lý do</th><th>Trạng thái</th><th class="text-right">Số tiền (đ)</th></tr></thead>
                    <tbody>
                        <tr v-for="pen in penalties" :key="`pen-${pen.id}`">
                            <td>{{ pen.violation_type }}<span class="block font-caption text-caption text-on-surface-variant">Ngày {{ pen.violation_date }}{{ pen.class ? ` - Lớp ${pen.class}` : '' }} · Biên bản {{ pen.code }}</span></td>
                            <td><UiBadge color="error">Trừ lương (quá hạn nộp)</UiBadge></td>
                            <td class="text-right font-mono text-error">-{{ money(pen.amount) }}</td>
                        </tr>
                        <tr v-for="(line, i) in deductionRows" :key="`d-${i}`">
                            <td>{{ line.label }}<span v-if="line.hint" class="block font-caption text-caption text-on-surface-variant">{{ line.hint }}</span></td>
                            <td><UiBadge color="neutral">{{ line.key.startsWith('manual_') ? 'Trừ khác' : 'Tự động' }}</UiBadge></td>
                            <td class="text-right font-mono text-error">-{{ money(line.amount) }}</td>
                        </tr>
                        <tr class="font-semibold"><td colspan="2">Tổng khoản trừ</td><td class="text-right font-mono text-error">-{{ money(record.total_deductions) }}</td></tr>
                    </tbody>
                </table>
                <template v-if="record.adjustment_notes" #footer><p class="p-sm font-body-small text-body-small italic text-on-surface-variant">Ghi chú kế toán: {{ record.adjustment_notes }}</p></template>
            </UiDataTable>
        </div>

        <UiDataTable :min-width="timesheets.length ? '640px' : null">
            <template #header>
                <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">history_edu</span>Chi tiết buổi dạy ({{ timesheets.length }})</h3>
                <label v-if="classes.length > 1" class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">filter_list</span>Lọc
                    <UiSelect v-model="cls" placeholder="Tất cả lớp" :options="classOptions" />
                </label>
            </template>
            <!-- Trạng thái trống đặt ngoài bảng để không bị cắt chữ trong khung cuộn ngang trên điện thoại -->
            <UiEmptyState v-if="!timesheets.length" icon="schedule" title="Không có buổi dạy hợp lệ trong kỳ" />
            <table v-else>
                <thead><tr><th>Ngày dạy</th><th>Thời gian</th><th>Lớp học</th><th class="text-right">Đơn giá (đ)</th><th class="text-right">Thành tiền (đ)</th></tr></thead>
                <tbody>
                    <tr v-for="(ts, i) in timesheets" v-show="(showAll || i < 10) && (!cls || cls === ts.class)" :key="ts.id">
                        <td class="font-code text-code">{{ ts.date }}</td>
                        <td class="font-mono">{{ ts.time }}</td>
                        <td>
                            {{ ts.class ?? '—' }}
                            <UiBadge v-if="ts.type === 'sub'" color="info">Cover</UiBadge>
                            <span v-else-if="ts.type !== 'regular'" class="font-caption text-caption text-on-surface-variant">{{ ts.type_label }}</span>
                        </td>
                        <td class="text-right font-mono">{{ money(ts.rate) }}<span class="font-caption text-caption text-on-surface-variant">/{{ ts.unit === 'session' ? 'buổi' : 'giờ' }}</span></td>
                        <td class="text-right font-mono">
                            <span v-if="record.is_full_time" class="font-caption text-caption text-on-surface-variant">Trong lương cơ bản</span>
                            <template v-else>{{ money(ts.amount) }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template v-if="timesheets.length > 10" #footer>
                <div class="p-sm text-center"><UiButton variant="ghost" size="sm" @click="showAll = !showAll">{{ showAll ? 'Thu gọn' : 'Xem thêm các buổi khác' }}</UiButton></div>
            </template>
        </UiDataTable>

        <p class="mt-md font-body-small text-body-small text-on-surface-variant">
            Mọi thắc mắc về số buổi dạy, KPI hoặc khoản trừ, vui lòng phản hồi phòng Kế toán trước ngày 03 hằng tháng. Trạng thái kỳ: <UiBadge :color="record.period_status === 'paid' ? 'secondary' : 'success'">{{ record.period_status_label }}</UiBadge>
        </p>
    </template>
</template>
