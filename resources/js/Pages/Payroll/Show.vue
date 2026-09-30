<script setup>
/**
 * Bảng lương của một kỳ (mockup epic-7/danh-sach-bang-luong-theo-ky): chọn kỳ, tìm nhân sự, lọc loại nhân sự / trạng thái KPI,
 * cảnh báo còn người chưa chốt KPI trước khi chốt bảng lương. Lương buổi có GVNN (Part-time) sửa trong hộp thoại cạnh con số.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { currentQuery, urlWith } from '@/lib/url';
import { money, trimNumber } from './format';
import PeriodTabs from './PeriodTabs.vue';

defineOptions({ layout: { title: 'Bảng lương' } });

const props = defineProps({
    period: { type: Object, required: true },
    stats: { type: Object, required: true },
    kpiPending: { type: Object, required: true },
    records: { type: Object, required: true },
    periodOptions: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
    filtered: { type: Boolean, default: false },
});

const page = usePage();
const periodError = computed(() => page.props.errors?.period ?? null);
const query = computed(() => currentQuery());
const type = computed(() => query.value.get('type'));
const pendingUrl = computed(() => urlWith({ kpi: 'pending', page: null }));
const showPending = computed(() => props.kpiPending.count > 0 && !props.period.locked);
const [statusColor, statusText] = props.period.status === 'paid' ? ['secondary', 'Đã trả'] : props.period.status === 'approved' ? ['success', 'Đã chốt'] : ['info', 'Đang tính'];
const kpiIcons = { done: 'check', pending: 'close', na: 'remove' };
const kpiOptions = [{ value: 'pending', label: 'Chưa chốt KPI' }, { value: 'done', label: 'Đã chốt KPI' }];
const pad = (n) => String(n).padStart(2, '0');
const foreignOpen = ref(null);

function changePeriod(event) {
    router.visit(route('payroll.periods.show', event.target.value));
}
function filter(event) {
    const data = {};
    new FormData(event.target).forEach((value, key) => {
        if (value !== '') data[key] = value;
    });
    router.get(route('payroll.periods.show', props.period.id), data, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader :title="`Bảng lương tháng ${period.month}/${period.year}`" :description="`Quản lý và chốt lương giáo viên, nhân sự theo từng kỳ — ${period.title} (${period.code}, ${formatDate(period.start_date)} – ${formatDate(period.end_date)})`">
        <template #breadcrumbs>
            <Link :href="route('payroll.periods.index')" class="hover:text-primary">Kỳ lương</Link>
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span>
            <span>{{ period.title }}</span>
        </template>
        <template #badges>
            <UiBadge :color="statusColor">{{ statusText }}</UiBadge>
            <UiBadge :color="period.next_color" :dot="false" data-next-step>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ period.status === 'paid' ? 'check_circle' : 'arrow_forward' }}</span>{{ period.next_step }}
            </UiBadge>
            <Link v-if="showPending" :href="pendingUrl" class="font-body-small text-body-small font-semibold text-primary hover:underline">Xem danh sách</Link>
        </template>
        <template #actions>
            <UiForm v-if="can('payroll.calculate') && !period.locked" :action="route('payroll.periods.calculate', period.id)" method="post">
                <UiButton type="submit" variant="secondary" icon="sync">Đồng bộ &amp; Tính lại</UiButton>
            </UiForm>
            <template v-if="can('payroll.approve') && !period.locked">
                <span v-if="kpiPending.count" :title="`Còn ${kpiPending.count} nhân sự chưa chốt KPI`"><UiButton icon="task_alt" disabled>Chốt bảng lương</UiButton></span>
                <UiForm v-else :action="route('payroll.periods.approve', period.id)" method="post">
                    <UiButton type="submit" icon="task_alt">Chốt bảng lương</UiButton>
                </UiForm>
            </template>
            <template v-if="can('payroll.mark_paid')">
                <UiForm v-if="period.status === 'approved'" :action="route('payroll.periods.mark-paid', period.id)" method="post">
                    <UiButton type="submit" variant="secondary" icon="payments">Đánh dấu đã trả</UiButton>
                </UiForm>
                <span v-else-if="period.status !== 'paid'" title="Chỉ kỳ lương đã chốt mới đánh dấu đã trả"><UiButton variant="secondary" icon="payments" disabled>Đánh dấu đã trả</UiButton></span>
            </template>
            <UiButton variant="secondary" icon="download" :href="route('payroll.periods.export', period.id)" native>Xuất Excel</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-lg">
        <UiAlert v-if="periodError" type="error">{{ periodError }}</UiAlert>

        <UiAlert v-if="showPending" type="error" dismissible :title="`Chưa thể chốt bảng lương kỳ ${pad(period.month)}/${period.year} do: Còn ${kpiPending.count} nhân sự chưa chốt KPI`">
            {{ kpiPending.names }}{{ kpiPending.count > 8 ? '…' : '' }}.
            Chọn bậc KPI giữ HS / nhập KPI trên phiếu lương, hoặc chốt đánh giá KPI Học vụ tháng rồi bấm "Đồng bộ &amp; Tính lại".
            <Link :href="pendingUrl" class="font-semibold underline">Xem danh sách</Link>
        </UiAlert>

        <!-- Khối / loại nhân sự -->
        <PeriodTabs :period-id="period.id" :current="type === 'teacher_parttime' ? 'parttime' : type ? null : 'all'" />

        <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            <UiStatCard label="Tổng chi quỹ lương" :value="formatMoney(stats.total_amount)" tone="primary" icon="account_balance_wallet" :hint="`${stats.records} nhân sự nhận lương`" />
            <UiStatCard label="Tổng buổi dạy (Part-time)" :value="`${stats.parttime_sessions} buổi`" icon="event_available" :hint="`${stats.hours} giờ chấm công hợp lệ`" />
            <UiStatCard label="Tổng thưởng KPI / Giữ học sinh" :value="formatMoney(stats.kpi_bonus)" tone="success" icon="trending_up" hint="Giữ HS (PT) · KPI tự do · KPI Học vụ" />
            <UiStatCard label="Tổng khấu trừ & Phạt" :value="'-' + formatMoney(stats.deductions)" tone="error" icon="money_off" hint="BHXH, Công đoàn, TNCN, phạt, thu hồi, khấu trừ tự do" />
        </div>

        <form method="GET" :action="route('payroll.periods.show', period.id)" role="search" class="flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" @submit.prevent="filter">
            <UiSelect label="Kỳ lương" aria-label="Chọn kỳ lương" :options="periodOptions" :value="period.id" @change="changePeriod" />
            <div class="min-w-[220px] flex-1">
                <UiInput type="search" name="search" :value="query.get('search') ?? ''" icon="search" placeholder="Tìm giáo viên / nhân sự..." aria-label="Tìm nhân sự" />
            </div>
            <UiSelect name="type" :options="typeOptions" placeholder="Mọi loại nhân sự" aria-label="Loại nhân sự" />
            <UiSelect name="kpi" :options="kpiOptions" placeholder="Mọi trạng thái KPI" aria-label="Trạng thái KPI" />
            <UiButton type="submit" variant="secondary" icon="filter_list">Lọc</UiButton>
        </form>

        <!-- Không đặt min-width cho khung: cột sticky cần bám vào chính khung cuộn chứa bảng -->
        <UiDataTable>
            <template #header>
                <h3 class="font-h3 text-h3 text-on-surface">Bảng lương từng nhân sự (Kỳ {{ period.month }}/{{ period.year }})</h3>
            </template>
            <table>
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 border-r border-surface-container bg-surface-container-low">Tên giáo viên / nhân sự</th>
                        <th class="text-center">Loại</th>
                        <th>Trạng thái bảng lương</th>
                        <th>Trạng thái KPI</th>
                        <th class="text-right">Lương CB / Buổi dạy</th>
                        <th class="text-right">KPI</th>
                        <th class="text-right">Buổi GVNN</th>
                        <th class="text-right">Hoa hồng</th>
                        <th class="text-right">Tái tục</th>
                        <th class="text-right">Phụ cấp tự do</th>
                        <th class="text-right">BHXH + CĐ</th>
                        <th class="text-right">Thuế TNCN</th>
                        <th class="text-right">Phạt &amp; trừ khác</th>
                        <!-- Thực nhận + Chi tiết gộp một cột cố định mép phải: bảng 15 cột vẫn cuộn ngang nhưng con số chính luôn thấy -->
                        <th class="sticky right-0 z-10 border-l border-surface-container bg-surface-container-low text-right">Thực nhận</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in records.data" :key="r.id">
                        <td class="sticky left-0 z-10 border-r border-surface-container bg-surface-container-lowest">
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="r.name ?? 'U'" size="sm" />
                                <div>
                                    <Link :href="route('payroll.records.show', r.id)" class="font-semibold text-on-surface hover:text-primary hover:underline" title="Xem phiếu lương">{{ r.name }}</Link>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ r.employee_code || r.email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            <UiBadge :color="r.is_part_time ? 'info' : 'neutral'">{{ r.employee_type_label }}</UiBadge>
                            <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ r.salary_role_label }}</p>
                        </td>
                        <td>
                            <span class="inline-flex items-center gap-xs">
                                <UiBadge :color="statusColor">{{ statusText }}</UiBadge>
                                <span v-if="r.kpi_state[0] === 'pending' && !period.locked" class="material-symbols-outlined text-[18px] text-error" title="Chưa chốt KPI" aria-label="Chưa chốt KPI">error</span>
                            </span>
                        </td>
                        <td>
                            <span :class="['inline-flex items-center gap-xs font-body-small text-body-small', r.kpi_state[0] === 'done' ? 'text-tertiary' : r.kpi_state[0] === 'pending' ? 'text-error' : 'text-on-surface-variant']">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ kpiIcons[r.kpi_state[0]] }}</span>{{ r.kpi_state[1] }}
                            </span>
                        </td>
                        <td class="text-right font-mono">
                            <template v-if="r.is_part_time">
                                <span class="text-tertiary">{{ formatMoney(r.teaching_salary) }}</span>
                                <p class="font-caption text-caption text-on-surface-variant">{{ r.teaching_sessions }} buổi</p>
                            </template>
                            <template v-else>{{ formatMoney(r.base_salary + r.teaching_salary) }}</template>
                        </td>
                        <td class="text-right font-mono">
                            {{ formatMoney(r.kpi_bonus) }}
                            <p v-if="r.kpi_source === 'retention'" class="font-caption text-caption text-on-surface-variant">{{ r.retention_students }} HS × {{ r.retention_tier !== null ? money(r.retention_tier) : 'chưa chọn bậc' }}</p>
                            <p v-else-if="r.kpi_source === 'academic_kpi'" class="font-caption text-caption text-on-surface-variant">{{ r.kpi_score !== null ? trimNumber(r.kpi_score, 2, '.', ',') + '% quỹ' : 'chưa chấm' }}</p>
                        </td>
                        <td class="text-right">
                            <!-- Nút sửa đặt ngay cạnh con số nó sửa, để cột thao tác chỉ còn "Chi tiết" -->
                            <div class="flex items-center justify-end gap-xs">
                                <span>{{ r.is_part_time ? formatMoney(r.foreign_session_pay) : '—' }}</span>
                                <template v-if="!period.locked && r.is_part_time && can('payroll.edit')">
                                    <UiButton variant="ghost" size="sm" icon="edit" title="Sửa lương buổi GVNN" aria-label="Sửa lương buổi GVNN" @click="foreignOpen = r.id" />
                                    <UiModal :show="foreignOpen === r.id" title="Lương buổi có GVNN" max-width="md" class="text-left" @close="foreignOpen = null">
                                        <UiForm :id="`foreign-form-${r.id}`" :action="route('payroll.records.update', r.id)" method="post" preserve-state="errors" class="space-y-md text-left">
                                            <p class="font-body-small text-body-small text-on-surface-variant">{{ r.name }}</p>
                                            <UiAlert type="warning"><strong>Chờ BA chốt:</strong> cách tính lương buổi có GVNN chưa được xác nhận. Kế toán nhập tổng tiền cộng cho GV. Kỳ này có {{ r.foreign_teacher_sessions_count }} buổi GVNN cùng lớp.</UiAlert>
                                            <UiInput type="number" name="foreign_session_pay" label="Số tiền (VNĐ)" :value="Math.trunc(r.foreign_session_pay)" min="0" step="1000" required />
                                            <UiTextarea name="notes" label="Ghi chú" rows="2" :value="r.adjustment_notes" placeholder="Ghi chú thêm..." />
                                        </UiForm>
                                        <template #footer>
                                            <UiButton variant="secondary" @click="foreignOpen = null">Hủy</UiButton>
                                            <UiButton type="submit" :form="`foreign-form-${r.id}`">Lưu</UiButton>
                                        </template>
                                    </UiModal>
                                </template>
                            </div>
                        </td>
                        <td class="text-right font-mono text-tertiary">
                            {{ formatMoney(r.commission_bonus) }}
                            <p v-if="r.commission_deferred > 0" class="font-caption text-caption font-semibold text-warning">Hoãn {{ formatMoney(r.commission_deferred) }}</p>
                        </td>
                        <td class="text-right font-mono">{{ formatMoney(r.renew_bonus) }}</td>
                        <td class="text-right font-mono">{{ formatMoney(r.free_allowance) }}</td>
                        <td class="text-right font-mono text-error">-{{ formatMoney(r.insurance_deduction + r.union_deduction) }}</td>
                        <td class="text-right font-mono text-error">-{{ formatMoney(r.tax_deduction) }}</td>
                        <td class="text-right font-mono text-error">-{{ formatMoney(r.other_deductions) }}</td>
                        <td class="sticky right-0 z-10 border-l border-surface-container bg-surface-container-lowest text-right">
                            <div class="flex items-center justify-end gap-xs">
                                <span class="font-bold text-primary">{{ formatMoney(r.net_salary) }}</span>
                                <UiButton variant="ghost" size="sm" icon="visibility" :href="route('payroll.records.show', r.id)">Chi tiết</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!records.data.length">
                        <td colspan="14"><UiEmptyState icon="payments" title="Chưa có chi tiết lương" :description="filtered ? 'Không có nhân sự khớp bộ lọc.' : 'Bấm “Đồng bộ & Tính lại” để tính lương cho kỳ này.'" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="records" unit="nhân sự" /></template>
        </UiDataTable>
    </div>
</template>
