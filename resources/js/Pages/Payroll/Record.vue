<script setup>
/**
 * Phiếu lương từng người — 4 mẫu mockup theo loại nhân sự (epic-7/chi-tiet-bang-luong = GV Part-time,
 * chi-tiet-bang-luong-gv-fulltime, chi-tiet-bang-luong-hoc-vu (+ roundcuoi 02/11), chi-tiet-bang-luong-hoc-thuat).
 * Sale / nhân sự khác dùng mẫu Full-time. Công thức theo A6 (Q3).
 * Kỳ chưa khoá + quyền payroll.edit: cả trang là form "Lưu điều chỉnh" (các khoản nhập tay giữ khi Đồng bộ & Tính lại).
 * "In phiếu lương / Xuất PDF": bản in Blade (printHtml) ẩn trên màn hình, chỉ hiện khi in.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiForm from '@/Components/ui/UiForm.vue';
import { useBackLink } from '@/lib/backLink';
import { route } from '@/lib/route';
import { money, trimNumber } from './format';
import PayslipLines from './PayslipLines.vue';

defineOptions({ layout: { title: 'Phiếu lương' } });

const props = defineProps({
    record: { type: Object, required: true },
    period: { type: Object, required: true },
    variant: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    lines: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    currentRate: { type: Object, default: null },
    lostStudents: { type: String, default: '' },
    timesheets: { type: Array, default: () => [] },
    kpiGroups: { type: Array, default: () => [] },
    renewalClasses: { type: Array, default: () => [] },
    lateLines: { type: Array, default: () => [] },
    lateInfo: { type: Object, default: () => ({ threshold: 15, per_minute: 5000, total: 0 }) },
    dailyAttendance: { type: Object, default: null },
    commissionTiers: { type: Array, default: () => [] },
    penalties: { type: Array, default: () => [] },
    clawbacks: { type: Array, default: () => [] },
    paidCommission: { type: Array, default: () => [] },
    deferredCommission: { type: Array, default: () => [] },
    commissionReceipts: { type: Array, default: () => [] },
    printHtml: { type: String, default: '' },
});
const back = useBackLink(() => route('payroll.periods.show', props.period.id), 'Quay lại danh sách');

const page = usePage();
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
const pct = (v) => trimNumber(v);
const pad = (n) => String(n).padStart(2, '0');
const isPT = computed(() => props.record.is_part_time);
const isFT = computed(() => props.record.is_full_time);
const key = computed(() => props.variant.key);
const [statusColor, statusText] = props.period.status === 'paid' ? ['secondary', 'Đã trả'] : props.period.status === 'approved' ? ['success', 'Đã chốt'] : ['info', 'Đang tính'];
const [kpiStateKey, kpiStateLabel] = props.record.kpi_state;
const editLines = ref(props.lines.map((line) => ({ ...line })));
const tierOptions = computed(() => props.settings.retention_tiers.map((t) => ({ value: String(Math.trunc(t)), label: `${money(t)}đ / HS` })));
const tierHint = computed(() => 'Gợi ý: ' + props.settings.retention_tiers.map((t) => money(t)).join(' / ') + 'đ/hs/tháng');
const showAllSessions = ref(false);
const showTiers = ref(false);
const showCommissionBlock = computed(
    () => props.record.salary_role === 'sales' || props.record.commission_bonus !== 0 || props.record.commission_deferred !== 0 || props.record.renew_bonus !== 0 || props.renewalClasses.length > 0,
);
const printPayslip = () => window.print();

function sessionLabel(ts) {
    return ts.scheduled_time ? 'Ca ' + ts.scheduled_time : (ts.checkin_time ?? '') + (ts.checkout_time ? ' - ' + ts.checkout_time : '');
}
function sessionNote(ts) {
    return (ts.type === 'sub' ? 'Dạy thay' : ts.type === 'regular' ? '' : ts.type_label) + (ts.source === 'manual' ? ' · Chấm tay' : '');
}
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- bản in do server render bằng Blade (đã escape) -->
    <div v-html="printHtml"></div>

    <UiPageHeader :title="variant.title">
        <template #breadcrumbs>
            <Link :href="back.href" data-back-link class="inline-flex items-center gap-xs hover:text-primary">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_back</span>{{ back.label }}
            </Link>
            <span aria-hidden="true">/</span>
            <span>{{ period.title }}</span>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="picture_as_pdf" @click="printPayslip">In phiếu lương / Xuất PDF</UiButton>
            <UiForm v-if="can('payroll.mark_paid') && period.status === 'approved'" :action="route('payroll.periods.mark-paid', period.id)" method="post">
                <UiButton type="submit" variant="secondary" icon="payments">Đánh dấu đã trả</UiButton>
            </UiForm>
            <UiForm v-if="can('payroll.approve') && !period.locked" :action="route('payroll.periods.approve', period.id)" method="post" :confirm="`Chốt toàn bộ bảng lương ${period.title}? Dữ liệu kỳ sẽ bị khóa và nhân sự nhận thông báo.`">
                <UiButton type="submit" icon="check_circle">Chốt bảng lương</UiButton>
            </UiForm>
        </template>
    </UiPageHeader>

    <!-- Người nhận + kỳ + loại + trạng thái -->
    <div class="mb-lg flex flex-wrap items-center gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <UiAvatar :name="record.name ?? 'U'" />
        <div class="min-w-0">
            <h2 class="font-h2 text-h2 text-on-surface">Phiếu lương: {{ record.name ?? 'Nhân sự' }}</h2>
            <div class="mt-xs flex flex-wrap items-center gap-md font-body-small text-body-small text-on-surface-variant">
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">badge</span>{{ record.employee_code || 'Chưa có mã NV' }}</span>
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">calendar_month</span>Kỳ lương {{ pad(period.month) }}/{{ period.year }}</span>
                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">work</span>{{ variant.type }} · {{ record.employee_type_label }}</span>
                <UiBadge :color="statusColor">{{ statusText }}</UiBadge>
            </div>
        </div>
    </div>

    <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>

    <UiAlert v-if="record.uses_q3" type="info" class="mb-md">
        <template v-if="isPT">Công thức Part-time: <strong>số buổi × đơn giá buổi riêng + KPI giữ HS + buổi có GVNN + phụ cấp tự do − khoản trừ</strong>. Không trừ BHXH / Công đoàn / TNCN.</template>
        <template v-else>Công thức Full-time: <strong>lương cơ bản + các khoản cộng − BHXH {{ pct(settings.insurance_rate_percent) }}% − Công đoàn {{ pct(settings.union_rate_percent) }}% (trên lương cơ bản) − thuế TNCN (nhập tay) − trừ vi phạm</strong>.</template>
    </UiAlert>

    <component :is="canEdit ? UiForm : 'div'" v-bind="canEdit ? { id: 'payslip-form', action: route('payroll.records.adjust', record.id), method: 'post', preserveState: 'errors' } : {}">
        <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
            <div class="space-y-lg lg:col-span-8">
                <template v-if="isPT">
                    <!-- GV Part-time: tổng thu nhập buổi dạy -->
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
                        <h3 class="mb-md flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">account_balance_wallet</span>Tổng thu nhập <span class="ml-auto font-mono text-primary">{{ money(record.gross_income) }} đ</span></h3>
                        <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                            <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Số buổi dạy</p><p class="font-h3 text-h3">{{ record.teaching_sessions }} buổi</p></div>
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Đơn giá cơ bản</p>
                                <p class="font-h3 text-h3">{{ currentRate ? money(currentRate.rate) + 'đ' : '—' }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ currentRate ? `${currentRate.unit_label} · hiệu lực ${formatDate(currentRate.effective_from)}` : 'Chưa có đơn giá riêng' }}</p>
                            </div>
                            <div class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Thành tiền</p><p class="font-h3 text-h3 text-tertiary">{{ money(record.teaching_salary) }} đ</p></div>
                        </div>
                    </section>

                    <div class="grid grid-cols-1 gap-lg md:grid-cols-2">
                        <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                            <h3 class="font-h3 text-h3 text-on-surface">Bậc KPI giữ học sinh</h3>
                            <UiSelect v-if="canEdit" name="retention_tier" label="Đơn giá KPI (đ/hs/tháng)" :options="tierOptions" :value="record.retention_tier !== null ? String(Math.trunc(record.retention_tier)) : ''" placeholder="— Chưa chọn bậc —" :hint="tierHint" />
                            <p v-else class="font-body-medium text-body-medium">Đơn giá KPI: {{ record.retention_tier !== null ? money(record.retention_tier) + 'đ/hs/tháng' : 'chưa chọn bậc' }}</p>
                            <div>
                                <p class="font-body-small text-body-small text-on-surface-variant">Số học sinh duy trì</p>
                                <p class="font-h3 text-h3">{{ record.retention_students }} / {{ record.retention_base_students }} HS</p>
                                <p class="font-caption text-caption text-on-surface-variant">Nghỉ trong kỳ: {{ record.retention_lost }} HS{{ lostStudents ? ` (${lostStudents})` : '' }}</p>
                            </div>
                            <div class="flex items-center justify-between">
                                <span :class="['inline-flex items-center gap-xs font-body-small text-body-small', kpiStateKey === 'done' ? 'text-tertiary' : kpiStateKey === 'pending' ? 'text-error' : 'text-on-surface-variant']">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ kpiStateKey === 'done' ? 'check' : 'info' }}</span>{{ kpiStateLabel }}
                                </span>
                                <UiButton v-if="canEdit" type="submit" size="sm" name="intent" value="kpi" icon="task_alt">Chốt KPI</UiButton>
                            </div>
                        </section>

                        <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                            <h3 class="font-h3 text-h3 text-on-surface">Phụ cấp mở rộng</h3>
                            <UiInput v-if="canEdit" type="number" name="foreign_session_pay" label="Lớp GVNN đan xen (đ) — chờ BA chốt" min="0" step="1000" :value="Math.trunc(record.foreign_session_pay)" :hint="`${record.foreign_teacher_sessions_count} buổi có GVNN cùng lớp trong kỳ. Kế toán nhập tổng tiền.`" />
                            <p v-else class="flex justify-between font-body-medium text-body-medium"><span>Lớp GVNN đan xen</span><span class="font-mono">{{ money(record.foreign_session_pay) }} đ</span></p>
                            <PayslipLines kind="earning" :lines="editLines" :saved="record.manual_lines" :can-edit="canEdit" add-label="Thêm phụ cấp" placeholder="VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác" />
                        </section>
                    </div>

                    <!-- Chi tiết buổi dạy -->
                    <UiDataTable min-width="640px">
                        <template #header><h3 class="font-h3 text-h3 text-on-surface">Chi tiết buổi dạy</h3></template>
                        <table>
                            <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th><th>Ghi chú</th></tr></thead>
                            <tbody>
                                <tr v-for="(ts, i) in timesheets" v-show="showAllSessions || i < 10" :key="ts.id">
                                    <td class="font-code text-code">{{ formatDate(ts.date) }}</td>
                                    <td>{{ sessionLabel(ts) }}</td>
                                    <td>{{ ts.class_code ?? '—' }}</td>
                                    <td class="text-right font-code text-code">{{ money(ts.rate) }}{{ ts.unit === 'session' ? 'đ/buổi' : 'đ/giờ' }}</td>
                                    <td><UiMoney :value="ts.amount" suffix="đ" /></td>
                                    <td class="font-body-small text-body-small text-on-surface-variant">{{ sessionNote(ts) }}</td>
                                </tr>
                                <tr v-if="!timesheets.length"><td colspan="6"><UiEmptyState icon="schedule" title="Không có buổi dạy hợp lệ trong kỳ" /></td></tr>
                            </tbody>
                        </table>
                        <template v-if="timesheets.length > 10" #footer>
                            <div class="p-sm text-center"><UiButton variant="ghost" size="sm" @click="showAllSessions = !showAllSessions">{{ showAllSessions ? 'Thu gọn' : `Xem toàn bộ (${timesheets.length} buổi)` }}</UiButton></div>
                        </template>
                    </UiDataTable>
                </template>

                <template v-else>
                    <!-- Full-time (GV Full-time / Học vụ / Học thuật / Sale / khác) -->
                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                            <span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span>
                            {{ key === 'academic_staff' ? 'Thu nhập cố định & KPI' : key === 'academic_lead' ? 'Thành phần Học thuật' : 'Thu nhập chính' }}
                        </h3>
                        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">{{ key === 'academic_lead' ? 'Lương cứng (VNĐ)' : 'Lương cơ bản (VNĐ)' }}</p>
                                <p class="font-h3 text-h3 font-mono">{{ money(record.base_salary) }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Theo hồ sơ nhân sự · căn cứ tính BHXH, Công đoàn</p>
                            </div>
                            <template v-if="record.kpi_source === 'manual'">
                                <UiInput v-if="canEdit" type="number" name="kpi_manual_amount" label="Lương KPI (VNĐ) — nhập tự do" min="0" step="1000" :value="record.kpi_manual_amount !== null ? Math.trunc(record.kpi_manual_amount) : null" :hint="`${kpiStateLabel} · Admin / Kế toán nhập (0đ vẫn tính là đã chốt).`" />
                                <div v-else class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Lương KPI (VNĐ)</p><p class="font-h3 text-h3 font-mono">{{ money(record.kpi_bonus) }}</p></div>
                            </template>
                            <div v-else-if="record.kpi_source === 'academic_kpi'" class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Lương KPI (tự động theo 6 nhóm / 15 mục)</p>
                                <p class="font-h3 text-h3 font-mono">{{ money(record.kpi_bonus) }}</p>
                                <p :class="['font-caption text-caption', record.kpi_score !== null ? 'text-on-surface-variant' : 'text-error']">
                                    {{ record.kpi_score !== null ? `Quỹ ${money(record.kpi_fund)}đ × ${pct(record.kpi_score)}% điểm KPI · Đã chốt KPI tháng` : 'Chưa chốt đánh giá KPI tháng' }}
                                </p>
                            </div>
                        </div>

                        <details v-if="record.kpi_source === 'academic_kpi'" class="rounded-lg border border-surface-container" :open="!kpiGroups.length">
                            <summary class="cursor-pointer px-md py-sm font-body-medium text-body-medium text-primary">Xem bảng kê chi tiết 6 nhóm / 15 mục</summary>
                            <div class="border-t border-surface-container p-md space-y-sm">
                                <div v-for="(group, g) in kpiGroups" :key="group.group">
                                    <p class="flex justify-between font-body-semibold text-body-semibold">
                                        <span>{{ g + 1 }}. {{ group.group }} (trọng số {{ pct(group.weight) }}%)</span>
                                        <span class="font-mono">{{ money(group.amount) }} đ</span>
                                    </p>
                                    <ul class="ml-md list-disc font-body-small text-body-small text-on-surface-variant">
                                        <li v-for="(item, i) in group.items" :key="i">{{ item.code }} {{ item.name }} — điểm {{ pct(item.score) }}% × trọng số {{ pct(item.weight) }}% = {{ money(item.amount) }} đ</li>
                                    </ul>
                                </div>
                                <p v-if="!kpiGroups.length" class="font-body-small text-body-small text-on-surface-variant">Chưa có đánh giá KPI tháng {{ period.month }}/{{ period.year }} được chốt.</p>
                                <UiButton v-if="can('kpi.view')" variant="ghost" size="sm" icon="open_in_new" :href="route('kpi.evaluate', { userId: record.user_id, month: period.month, year: period.year })">Chốt / xem đánh giá KPI tháng</UiButton>
                            </div>
                        </details>
                    </section>

                    <section v-if="showCommissionBlock" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">trending_up</span>Hoa hồng tuyển sinh &amp; Thưởng tái tục (chỉ đọc)</h3>
                        <UiAlert type="info">Dữ liệu này được hệ thống tính toán tự động, không thể chỉnh sửa thủ công.</UiAlert>
                        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Hoa hồng tuyển sinh</p>
                                <p class="font-h3 text-h3 font-mono">{{ money(record.commission_bonus) }} đ</p>
                                <p class="font-caption text-caption text-on-surface-variant">
                                    {{ record.commission_closed_count }} HS chốt trong kỳ{{ record.commission_percent !== null ? ` · bậc ${pct(record.commission_percent)}%` : '' }}{{ record.commission_deferred > 0 ? ` · hoãn ${money(record.commission_deferred)}đ` : '' }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Thưởng tái tục</p>
                                <p class="font-h3 text-h3 font-mono">{{ money(record.renew_bonus) }} đ</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ renewalClasses.length }} lớp phụ trách</p>
                            </div>
                        </div>
                        <template v-if="commissionTiers.length">
                            <UiButton variant="ghost" size="sm" @click="showTiers = !showTiers">Chi tiết bậc áp dụng <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ showTiers ? 'expand_less' : 'expand_more' }}</span></UiButton>
                            <table v-show="showTiers" class="w-full text-left font-body-small text-body-small">
                                <thead><tr class="text-on-surface-variant"><th class="py-xs">Bậc</th><th class="py-xs">Ngưỡng số HS chốt</th><th class="py-xs text-right">Tỷ lệ %</th></tr></thead>
                                <tbody>
                                    <tr v-for="tier in commissionTiers" :key="tier.id" :class="['border-t border-surface-container', tier.applied ? 'font-semibold text-tertiary' : '']">
                                        <td class="py-xs">{{ tier.name }} <span v-if="tier.applied" class="material-symbols-outlined align-middle text-[16px]" aria-label="Bậc áp dụng">check_circle</span></td>
                                        <td class="py-xs">{{ tier.range }}</td>
                                        <td class="py-xs text-right font-mono">{{ pct(tier.percent) }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </section>

                    <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">add_circle</span>Phụ cấp mở rộng</h3>
                        <PayslipLines kind="earning" :lines="editLines" :saved="record.manual_lines" :can-edit="canEdit" add-label="Thêm phụ cấp mới" placeholder="VD: Phụ cấp trách nhiệm, Thưởng khác" />
                    </section>
                </template>

                <!-- Trừ vi phạm & khoản trừ -->
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">gavel</span>{{ isPT ? 'Các khoản trừ' : 'Trừ vi phạm' }}</h3>
                        <UiButton v-if="can('violation.create')" variant="ghost" size="sm" icon="open_in_new" :href="route('penalties.index')">Theo danh mục vi phạm</UiButton>
                    </div>
                    <table class="w-full text-left font-body-medium text-body-medium">
                        <thead><tr class="font-label text-label uppercase text-on-surface-variant"><th class="py-xs">Lý do / Hạng mục</th><th class="py-xs text-right">Số tiền (VNĐ)</th><th class="py-xs">Trạng thái</th></tr></thead>
                        <tbody>
                            <tr v-for="pen in penalties" :key="'p' + pen.id" class="border-t border-surface-container">
                                <td class="py-xs">{{ pen.violation_type }} <span class="block font-caption text-caption text-on-surface-variant">Biên bản {{ pen.code }} · ngày {{ formatDate(pen.violation_date) }} · quá hạn nộp {{ pen.due_date ? formatDate(pen.due_date) : '—' }}</span></td>
                                <td class="py-xs text-right font-mono text-error">-{{ money(pen.amount) }}</td>
                                <td class="py-xs"><span class="inline-flex items-center gap-xs text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check</span>Đã xác nhận</span></td>
                            </tr>
                            <tr v-for="adj in clawbacks" :key="'c' + adj.id" class="border-t border-surface-container">
                                <td class="py-xs">{{ adj.reason }} <span class="block font-caption text-caption text-on-surface-variant">Thu hồi hoa hồng</span></td>
                                <td class="py-xs text-right font-mono text-error">{{ money(adj.amount) }}</td>
                                <td class="py-xs">Tự động</td>
                            </tr>
                            <tr v-if="!penalties.length && !clawbacks.length"><td colspan="3" class="py-sm text-on-surface-variant">Không có biên bản phạt quá hạn hay thu hồi hoa hồng trong kỳ.</td></tr>
                        </tbody>
                    </table>
                    <p class="font-caption text-caption text-on-surface-variant">Phạt từ biên bản vi phạm đã chốt, quá hạn nộp 2 ngày chưa nộp → trừ lương (xử lý ở màn Danh sách vi phạm). Khoản trừ khác (tạm ứng…) nhập bên dưới.</p>
                    <PayslipLines kind="deduction" :lines="editLines" :saved="record.manual_lines" :can-edit="canEdit" add-label="Thêm khoản trừ" placeholder="VD: Tạm ứng, Vi phạm nội quy" />
                </section>

                <section v-if="isFT || !record.uses_q3" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-error" aria-hidden="true">remove_circle</span>Khấu trừ bắt buộc</h3>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <template v-if="record.uses_q3">
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">BHXH ({{ pct(record.rate_insurance) }}% lương CB)</p>
                                <p class="font-h3 text-h3 font-mono text-error">-{{ money(record.insurance_deduction) }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Tự động tính, chỉ đọc</p>
                            </div>
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <p class="font-body-small text-body-small text-on-surface-variant">Phí Công đoàn ({{ pct(record.rate_union) }}% lương CB)</p>
                                <p class="font-h3 text-h3 font-mono text-error">-{{ money(record.union_deduction) }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">Tự động tính, chỉ đọc</p>
                            </div>
                        </template>
                        <UiInput v-if="canEdit" type="number" name="tax_deduction" label="Thuế TNCN (VNĐ)" min="0" step="1000" :value="Math.trunc(record.tax_deduction)" hint="Admin / Kế toán nhập thủ công." />
                        <div v-else class="rounded-lg bg-surface-container-low p-md"><p class="font-body-small text-body-small text-on-surface-variant">Thuế TNCN (VNĐ)</p><p class="font-h3 text-h3 font-mono text-error">-{{ money(record.tax_deduction) }}</p></div>
                    </div>
                </section>

                <!-- Căn cứ chi tiết -->
                <UiDataTable v-if="!isPT && timesheets.length" min-width="480px">
                    <template #header><h3 class="font-h3 text-h3 text-on-surface">Buổi dạy trong kỳ ({{ timesheets.length }}) — đối soát, đã gồm trong lương cơ bản</h3></template>
                    <table>
                        <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th class="text-right">Giờ</th></tr></thead>
                        <tbody>
                            <tr v-for="ts in timesheets" :key="ts.id">
                                <td class="font-code text-code">{{ formatDate(ts.date) }}</td>
                                <td>{{ ts.scheduled_time ? 'Ca ' + ts.scheduled_time : '—' }}</td>
                                <td>{{ ts.class_name ?? '—' }} <span class="font-caption text-caption text-on-surface-variant">{{ ts.type_label }}</span></td>
                                <td class="text-right font-code text-code">{{ ts.hours }}</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <UiDataTable v-if="lateLines.length" data-testid="late-lines">
                    <template #header>
                        <h3 class="font-h3 text-h3 text-on-surface">Đi muộn / về sớm — khoản giảm tiền công</h3>
                        <p class="font-caption text-caption text-on-surface-variant">Có báo trước: trả theo phút thực dạy · không báo trước dưới {{ lateInfo.threshold }} phút: trừ {{ lateInfo.per_minute.toLocaleString('vi-VN') }}đ/phút · từ {{ lateInfo.threshold }} phút: không tính buổi.</p>
                    </template>
                    <table>
                        <thead><tr><th>Ngày / lớp</th><th class="text-right">Số phút</th><th>Cách tính</th><th class="text-right">Giảm</th></tr></thead>
                        <tbody>
                            <tr v-for="row in lateLines" :key="row.timesheet_id">
                                <td>{{ row.date }} <span class="block font-caption text-caption text-on-surface-variant">{{ row.class }}</span></td>
                                <td class="text-right font-code text-code">{{ row.minutes }}</td>
                                <td>{{ { notified: 'Có báo trước — trả theo phút thực dạy', deduct: 'Không báo trước — trừ theo phút', void: 'Không báo trước, quá ngưỡng — không tính buổi' }[row.rule] }}</td>
                                <td><UiMoney :value="row.deduction" suffix="đ" /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <UiDataTable v-if="renewalClasses.length">
                    <template #header><h3 class="font-h3 text-h3 text-on-surface">Thưởng tái tục theo lớp</h3></template>
                    <table>
                        <thead><tr><th>Lớp</th><th class="text-right">HS nghỉ</th><th class="text-right">%</th><th class="text-right">Doanh thu lớp</th><th class="text-right">Thưởng</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in renewalClasses" :key="i">
                                <td>{{ row.class }} <span class="block font-caption text-caption text-on-surface-variant">{{ row.base }} HS đầu kỳ</span></td>
                                <td class="text-right font-code text-code">{{ row.quits }}</td>
                                <td class="text-right font-code text-code">{{ pct(row.percent) }}%<span v-if="row.pending" class="block font-caption text-caption text-warning">chờ BA</span></td>
                                <td><UiMoney :value="row.revenue" suffix="đ" /></td>
                                <td><UiMoney :value="row.amount" suffix="đ" /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <UiDataTable v-if="record.uses_q3 && (paidCommission.length || deferredCommission.length || record.salary_role === 'sales')">
                    <template #header>
                        <h3 class="font-h3 text-h3 text-on-surface">Hoa hồng tuyển sinh — từng khoản</h3>
                        <p class="font-caption text-caption text-on-surface-variant">Trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.</p>
                    </template>
                    <table>
                        <thead><tr><th>Học viên / phiếu</th><th class="text-right">Thực thu</th><th class="text-right">%</th><th class="text-right">Hoa hồng</th></tr></thead>
                        <tbody>
                            <tr v-for="item in paidCommission" :key="'paid' + item.id">
                                <td>{{ item.student ?? '—' }} <span class="block font-caption text-caption text-on-surface-variant">{{ item.receipt_number }} · phát sinh {{ item.earned }} · Trả trong kỳ</span></td>
                                <td><UiMoney :value="item.base_amount" suffix="đ" /></td>
                                <td class="text-right font-code text-code">{{ pct(item.percent) }}%</td>
                                <td><UiMoney :value="item.amount" suffix="đ" /></td>
                            </tr>
                            <tr v-for="item in deferredCommission" :key="'deferred' + item.id">
                                <td>{{ item.student ?? '—' }} <span class="block font-caption text-caption text-warning">{{ item.receipt_number }} · {{ item.deferred_reason ?? 'Hoãn sang kỳ sau' }}</span></td>
                                <td><UiMoney :value="item.base_amount" suffix="đ" /></td>
                                <td class="text-right font-code text-code">{{ pct(item.percent) }}%</td>
                                <td class="text-right font-code text-code text-on-surface-variant">Hoãn {{ money(item.amount) }} đ</td>
                            </tr>
                            <tr v-if="!paidCommission.length && !deferredCommission.length"><td colspan="4"><UiEmptyState icon="receipt_long" title="Không có hoa hồng trả / hoãn trong kỳ" /></td></tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <UiDataTable v-else-if="!record.uses_q3">
                    <template #header><h3 class="font-h3 text-h3 text-on-surface">Phiếu thu tính hoa hồng ({{ commissionReceipts.length }})</h3></template>
                    <table>
                        <thead><tr><th>Ngày duyệt</th><th>Học viên</th><th class="text-right">Thực thu</th></tr></thead>
                        <tbody>
                            <tr v-for="receipt in commissionReceipts" :key="receipt.id"><td class="font-code text-code">{{ formatDate(receipt.approved_at) }}</td><td>{{ receipt.student ?? '—' }}</td><td><UiMoney :value="receipt.amount" suffix="đ" /></td></tr>
                            <tr v-if="!commissionReceipts.length"><td colspan="3"><UiEmptyState icon="receipt_long" title="Không có phiếu thu khách mới trong kỳ" /></td></tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </div>

            <!-- Tổng kết -->
            <aside class="space-y-md lg:sticky lg:top-20 lg:col-span-4">
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-sm">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">receipt_long</span>Tổng kết thực nhận</h3>
                    <div class="space-y-xs font-body-small text-body-small">
                        <div v-for="line in record.earning_lines" :key="line.key" class="flex justify-between gap-sm">
                            <span>{{ line.label }}<span v-if="line.hint" class="block font-caption text-caption text-on-surface-variant">{{ line.hint }}</span></span>
                            <span class="whitespace-nowrap font-mono">{{ money(line.amount) }}</span>
                        </div>
                    </div>
                    <div class="flex justify-between border-t border-surface-container pt-sm font-body-semibold text-body-semibold"><span>Tổng thu nhập</span><span class="font-mono text-tertiary">{{ money(record.gross_income) }}</span></div>
                    <div class="space-y-xs font-body-small text-body-small">
                        <div v-for="line in record.deduction_lines" :key="line.key" class="flex justify-between gap-sm">
                            <span>{{ line.label }}<span v-if="line.hint" class="block font-caption text-caption text-on-surface-variant">{{ line.hint }}</span></span>
                            <span class="whitespace-nowrap font-mono text-error">-{{ money(line.amount) }}</span>
                        </div>
                    </div>
                    <div class="flex justify-between border-t border-surface-container pt-sm font-body-semibold text-body-semibold"><span>Tổng khoản trừ</span><span class="font-mono text-error">-{{ money(record.total_deductions) }}</span></div>
                    <div class="flex items-end justify-between rounded-lg bg-primary-fixed/40 p-md">
                        <span class="font-body-semibold text-body-semibold">Thực nhận</span>
                        <span class="font-h2 text-h2 font-mono text-primary">{{ money(record.net_salary) }} đ</span>
                    </div>

                    <template v-if="canEdit">
                        <UiTextarea name="adjustment_notes" label="Ghi chú" rows="2" :value="record.adjustment_notes" hint="Các khoản nhập tay được giữ khi bấm Đồng bộ & Tính lại." />
                        <UiButton type="submit" icon="save" class="w-full">Lưu điều chỉnh</UiButton>
                        <UiAlert type="info">Bảng lương này đang ở trạng thái tính toán. Chốt bảng lương để khóa dữ liệu và gửi thông báo cho nhân sự.</UiAlert>
                    </template>
                    <p v-else class="font-body-small text-body-small text-on-surface-variant">
                        {{ record.adjustment_notes || 'Không có ghi chú điều chỉnh.' }}
                        <span v-if="period.locked" class="block font-caption text-caption">Kỳ lương đã {{ period.status_label.toLocaleLowerCase('vi') }} — phiếu lương đã khoá.</span>
                    </p>
                    <p class="font-caption text-caption text-on-surface-variant">Diễn giải tự động: {{ record.notes }}</p>
                </section>

                <!-- Chấm công hằng ngày (điện thoại): căn cứ đối soát, đi muộn bị phạt qua biên bản -->
                <section v-if="dailyAttendance" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-sm" data-daily-attendance>
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">fingerprint</span>Chấm công hằng ngày</h3>
                    <dl class="grid grid-cols-2 gap-sm font-body-small text-body-small">
                        <div><dt class="text-on-surface-variant">Ngày có chấm công</dt><dd class="font-body-semibold text-body-semibold">{{ dailyAttendance.days }}</dd></div>
                        <div><dt class="text-on-surface-variant">Nghỉ có phép</dt><dd class="font-body-semibold text-body-semibold">{{ dailyAttendance.leave_days }} ngày</dd></div>
                        <div><dt class="text-on-surface-variant">Đi muộn (tính lỗi)</dt><dd class="font-body-semibold text-body-semibold" :class="dailyAttendance.late_count ? 'text-error' : ''">{{ dailyAttendance.late_count }} lần · {{ dailyAttendance.late_minutes }} phút</dd></div>
                        <div><dt class="text-on-surface-variant">Muộn có phép</dt><dd class="font-body-semibold text-body-semibold">{{ dailyAttendance.excused_late }} lần</dd></div>
                        <div><dt class="text-on-surface-variant">Về sớm</dt><dd class="font-body-semibold text-body-semibold">{{ dailyAttendance.early_count }} lần</dd></div>
                        <div><dt class="text-on-surface-variant">Quên chấm ra</dt><dd class="font-body-semibold text-body-semibold">{{ dailyAttendance.missing_out }} ngày</dd></div>
                    </dl>
                    <p class="font-caption text-caption text-on-surface-variant">Đi muộn tự lập biên bản chờ giải trình; tiền phạt (nếu có) trừ qua mục "Trừ vi phạm".</p>
                    <UiButton v-if="can('staff_checkin.view')" variant="ghost" size="sm" icon="open_in_new" :href="route('staff-attendance.index', { view: 'month', month: period.start_date?.slice(0, 7), user_id: record.user_id })">Xem bảng công</UiButton>
                </section>
            </aside>
        </div>
    </component>
</template>
