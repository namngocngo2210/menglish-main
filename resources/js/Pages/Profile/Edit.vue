<script setup>
/**
 * Trang cá nhân ("cổng" của từng người): thẻ số liệu, tab và lối tắt theo công việc thực của vai trò (ProfileController::edit).
 * GV / TA: giờ dạy, lớp, ca dạy; Học vụ: việc, báo cáo ngày, việc cần duyệt; Học thuật: đề xuất chờ duyệt, báo cáo tuần;
 * Học viên: chỉ tài khoản & mật khẩu + lối về cổng học viên. Tab "Cài đặt tài khoản": sửa hồ sơ + đổi mật khẩu (error bag updatePassword).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/format';

// Lỗi đổi mật khẩu (bag updatePassword) hiện ngay dưới từng ô như bản Blade — không lặp lại ở khung lỗi chung.
defineOptions({ layout: { title: 'Hồ sơ cá nhân', hideErrors: true } });

const props = defineProps({
    account: { type: Object, required: true },
    canSendVerification: { type: Boolean, default: false },
    initialTab: { type: String, default: 'settings' },
    status: { type: String, default: null },
    portal: { type: String, required: true },
    teaches: { type: Boolean, default: false },
    roleLabels: { type: Array, default: () => [] },
    quickLinks: { type: Array, default: () => [] },
    statCards: { type: Array, default: () => [] },
    showPayroll: { type: Boolean, default: false },
    showTickets: { type: Boolean, default: false },
    showOperations: { type: Boolean, default: false },
    commission: { type: Object, default: null },
    kpi: { type: Object, default: null },
    payrollPeriodLabel: { type: String, default: null },
    latestPayroll: { type: Object, default: null },
    recentPayrolls: { type: Array, default: () => [] },
    recentTimesheets: { type: Array, default: () => [] },
    myTasks: { type: Array, default: () => [] },
    tasksUrl: { type: String, default: null },
    assignedClasses: { type: Array, default: () => [] },
    myTickets: { type: Array, default: () => [] },
    myActivities: { type: Array, default: () => [] },
});

const page = usePage();
const activeTab = ref(props.initialTab);
const tabClass = (tab) => ['pb-3 px-2 text-xs font-bold transition whitespace-nowrap flex items-center gap-1.5 border-b-2', activeTab.value === tab ? 'border-primary-container text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'];

// Lỗi của form hồ sơ (bag mặc định) — như khung lỗi chung của layout Blade (không gồm bag updatePassword).
const defaultErrors = computed(() => Object.values(page.props.errors ?? {}).filter((v) => typeof v === 'string'));

// Dòng "Đã … thành công!" hiện 2,5 giây sau khi lưu.
const shownStatus = ref(props.status);
let timer = null;
function flashStatus(value) {
    shownStatus.value = value;
    window.clearTimeout(timer);
    if (value) timer = window.setTimeout(() => (shownStatus.value = null), 2500);
}
if (typeof window !== 'undefined' && props.status) flashStatus(props.status);
watch(() => props.status, (value) => flashStatus(value));
const onSaved = (p) => flashStatus(p?.props?.status ?? null);
onBeforeUnmount(() => typeof window !== 'undefined' && window.clearTimeout(timer));

const pct = (v) => String(Math.round(Number(v) * 100) / 100).replace('.', ',') + '%';
const currentTierIndex = computed(() => props.commission?.tiers?.findIndex((t) => t.current) ?? -1);
const barWidth = (value, max) => `${max > 0 ? Math.min(100, Math.max(0, (Number(value) / Number(max)) * 100)) : 0}%`;
const kpiStatus = computed(() => (props.kpi?.status === 'confirmed' ? ['success', 'Đã chốt'] : props.kpi?.status === 'draft' ? ['secondary', 'Đang chấm'] : ['neutral', 'Chưa chấm']));
const kpiTotal = computed(() => (props.kpi ? Object.values(props.kpi.counts).reduce((a, b) => a + b, 0) : 0));
const kpiLevel = {
    full: ['success', 'Đạt Ngưỡng 100'],
    half: ['primary', 'Đạt Ngưỡng 50'],
    low: ['warning', 'Dưới Ngưỡng 50'],
    zero: ['error', 'Không đạt'],
    pending: ['neutral', 'Chưa chấm'],
};
const taskBadge = (status) => (status === 'completed' ? ['success', 'Hoàn thành'] : status === 'in_progress' ? ['secondary', 'Đang làm'] : ['warning', 'Chờ xử lý']);
const priorityColor = (p) => (p === 'urgent' ? 'error' : p === 'high' ? 'primary' : 'neutral');
const ticketColor = (s) => (s === 'resolved' ? 'success' : s === 'in_progress' ? 'secondary' : 'warning');
</script>

<template>
    <!-- Theo trạng thái tài khoản (không theo ?force_password) để tắt ngay sau khi đổi mật khẩu -->
    <div v-if="account.must_change_password" class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
        <div class="rounded-xl border border-warning/30 bg-warning-container p-4 text-sm font-semibold text-on-warning-container">
            Đây là mật khẩu tạm. Vui lòng đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.
        </div>
    </div>

    <UiAlert v-if="defaultErrors.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-lg" dismissible data-global-errors>
        <ul class="list-disc space-y-xs pl-md">
            <li v-for="message in defaultErrors" :key="message">{{ message }}</li>
        </ul>
    </UiAlert>

    <div class="space-y-6">
        <!-- Banner + thông tin người dùng -->
        <div class="overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <div class="relative h-28 bg-gradient-to-r from-[#0d1527] via-[#1a2c4e] to-secondary p-6 sm:h-32">
                <div class="absolute inset-0 bg-[radial-gradient(#ffffff_1px,transparent_1px)] opacity-10 [background-size:16px_16px]"></div>
            </div>

            <div class="relative px-6 pb-6 sm:px-8">
                <div class="-mt-12 flex flex-col justify-between gap-4 border-b border-surface-container-highest pb-6 sm:-mt-14 sm:flex-row sm:items-end">
                    <div class="flex items-end gap-4">
                        <div class="relative">
                            <div class="flex h-24 w-24 items-center justify-center rounded-2xl border-4 border-white bg-gradient-to-br from-primary-container via-primary-container to-warning/70 text-3xl font-black text-white shadow-xl sm:h-28 sm:w-28 sm:text-4xl">
                                {{ account.initial }}
                            </div>
                            <span class="absolute bottom-1 right-1 h-4 w-4 rounded-full border-2 border-white bg-tertiary" title="Đang hoạt động"></span>
                        </div>

                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-xl font-black text-on-surface sm:text-2xl">{{ account.name }}</h1>
                                <UiBadge v-for="roleLabel in roleLabels" :key="roleLabel" color="secondary" pill>{{ roleLabel }}</UiBadge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-on-surface-variant">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-on-surface-subtle">mail</span>
                                    <span>{{ account.email }}</span>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm text-on-surface-subtle">domain</span>
                                    <span>{{ account.branch_name ?? 'Toàn hệ thống ME Education' }}</span>
                                </span>
                                <span v-if="portal !== 'student'" class="flex items-center gap-1 font-mono">
                                    <span class="material-symbols-outlined text-sm text-on-surface-subtle">badge</span>
                                    <span>{{ account.staff_code }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 self-start sm:self-auto">
                        <UiButton v-if="portal === 'student'" variant="secondary" icon="cottage" :href="route('portal.student.home')">Về Cổng học viên</UiButton>
                        <UiButton v-else-if="showTickets && can('support_ticket.create')" variant="secondary" icon="bug_report" :href="route('tickets.create')">Báo lỗi / Ticket</UiButton>
                        <UiButton icon="manage_accounts" @click="activeTab = 'settings'">Cài đặt tài khoản</UiButton>
                    </div>
                </div>

                <!-- Thẻ số liệu theo công việc của vai trò -->
                <div v-if="statCards.length" class="grid grid-cols-2 gap-3.5 pt-5 md:grid-cols-4">
                    <template v-for="card in statCards" :key="card.label">
                        <Link v-if="card.href" :href="card.href" class="block rounded-xl transition hover:shadow-md">
                            <UiStatCard class="h-full" :label="card.label" :icon="card.icon" :tone="card.tone" :value="card.value" :hint="card.hint" />
                        </Link>
                        <UiStatCard v-else :label="card.label" :icon="card.icon" :tone="card.tone" :value="card.value" :hint="card.hint" />
                    </template>
                </div>

                <!-- Tab -->
                <div class="scrollbar-none mt-6 flex items-center gap-2 overflow-x-auto border-b border-surface-container-highest pt-2 sm:gap-4">
                    <button v-if="showOperations" type="button" :class="tabClass('operations')" @click="activeTab = 'operations'">
                        <span class="material-symbols-outlined text-[18px]">dashboard</span>
                        <span>{{ ['teacher', 'assistant'].includes(portal) ? 'Giảng dạy & Nhiệm vụ' : 'Công việc của tôi' }}</span>
                    </button>
                    <button v-if="showPayroll" type="button" :class="tabClass('payroll')" @click="activeTab = 'payroll'">
                        <span class="material-symbols-outlined text-[18px]">payments</span>
                        <span>Lương &amp; Phiếu lương cá nhân</span>
                    </button>
                    <button v-if="showTickets" type="button" :class="tabClass('tickets')" @click="activeTab = 'tickets'">
                        <span class="material-symbols-outlined text-[18px]">bug_report</span>
                        <span>Báo lỗi &amp; Ticket ({{ myTickets.length }})</span>
                    </button>
                    <button type="button" :class="tabClass('settings')" @click="activeTab = 'settings'">
                        <span class="material-symbols-outlined text-[18px]">settings</span>
                        <span>Cài đặt tài khoản &amp; Bảo mật</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- TAB 1: CÔNG VIỆC CỦA TÔI (nội dung theo vai trò) -->
        <div v-if="showOperations" v-show="activeTab === 'operations'" class="space-y-6">
            <div v-if="quickLinks.length" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">apps</span>
                    Màn hình công việc của bạn
                </h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <Link v-for="link in quickLinks" :key="link.url" :href="link.url" class="flex items-center gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-3 text-xs font-bold text-on-surface transition hover:border-primary-container hover:bg-surface-container-lowest">
                        <span class="material-symbols-outlined text-[20px] text-primary">{{ link.icon }}</span>
                        <span class="min-w-0">{{ link.label }}</span>
                    </Link>
                </div>
            </div>

            <!-- Hoa hồng tuyển sinh TẠM TÍNH tháng này (người phụ trách khách); hoa hồng thực ở bảng lương -->
            <section v-if="commission" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm" data-commission-panel>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h2 class="flex flex-wrap items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-[20px] text-tertiary">trending_up</span>
                            Hoa hồng tuyển sinh tháng {{ commission.month_label }}
                            <UiBadge color="warning" pill>Tạm tính</UiBadge>
                        </h2>
                        <p class="text-xs text-on-surface-variant">
                            Mỗi HS mang % của mốc ứng với thứ tự chốt trong tháng (theo lúc chốt, không theo lúc đóng tiền). Hoa hồng chỉ ghi nhận khi hệ thống nhận tiền về (phiếu thu được duyệt), tính trên học phí không gồm sách / Thu khác.
                            Hoa hồng thực xem ở bảng lương.
                        </p>
                    </div>
                    <Link v-if="can('kpi.view')" :href="route('payroll.kpi-leaderboard')" class="text-xs font-bold text-primary hover:underline">Bảng xếp hạng &rarr;</Link>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">HS đã chốt</div>
                        <div class="font-mono text-xl font-black text-on-surface">{{ commission.closed }}</div>
                        <div class="text-xs text-on-surface-variant">{{ commission.fully_paid }}/{{ commission.closed }} đã thu đủ</div>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">Mốc hiện tại</div>
                        <div class="font-mono text-xl font-black text-on-surface">{{ pct(commission.percent) }}</div>
                        <div class="text-xs text-on-surface-variant">{{ commission.range ?? 'Chưa cấu hình mốc' }}</div>
                    </div>
                    <div class="rounded-2xl border border-tertiary/30 bg-tertiary/10 p-4">
                        <div class="text-xs text-on-surface-variant">Tạm tính khi thu đủ</div>
                        <UiMoney :value="commission.expected" align="left" tone="success" class="!text-xl font-black" />
                        <div class="text-xs text-on-surface-variant">HS chốt trong tháng</div>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">Đã ghi nhận (tiền đã về)</div>
                        <UiMoney :value="commission.earned_closed" align="left" class="!text-xl font-black" />
                        <div class="text-xs text-on-surface-variant">Của các HS trên</div>
                    </div>
                </div>

                <!-- Mốc tiến độ: mốc đã qua / đang ở / chưa tới -->
                <ol v-if="commission.tiers.length" class="flex flex-wrap gap-2" aria-label="Các mốc hoa hồng" data-commission-steps>
                    <li
                        v-for="(tier, i) in commission.tiers"
                        :key="tier.range"
                        class="min-w-[8rem] flex-1 rounded-xl border p-3"
                        :class="tier.current ? 'border-tertiary bg-tertiary/10' : i < currentTierIndex ? 'border-tertiary/30 bg-surface-container-low/70' : 'border-surface-container-highest'"
                    >
                        <div class="flex items-center gap-1 text-xs font-bold" :class="tier.current || i < currentTierIndex ? 'text-tertiary' : 'text-on-surface-variant'">
                            <span class="material-symbols-outlined text-[16px]">{{ i < currentTierIndex ? 'check_circle' : tier.current ? 'radio_button_checked' : 'radio_button_unchecked' }}</span>
                            {{ tier.range }}
                        </div>
                        <div class="font-mono text-lg font-black text-on-surface">{{ pct(tier.percent) }}</div>
                        <div v-if="tier.current" class="text-xs font-semibold text-tertiary">Đang ở mốc này</div>
                    </li>
                </ol>
                <p v-if="commission.to_next" class="text-xs font-semibold text-on-surface">
                    Còn {{ commission.to_next }} HS nữa sang {{ commission.next_range }}: {{ pct(commission.next_percent) }} cho mỗi HS từ đó.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-xs">
                        <thead>
                            <tr class="text-on-surface-variant">
                                <th class="py-2 font-semibold">Học viên</th>
                                <th class="py-2 font-semibold">HS thứ</th>
                                <th class="py-2 text-right font-semibold">%</th>
                                <th class="py-2 text-right font-semibold">Học phí (không sách)</th>
                                <th class="py-2 text-right font-semibold">Đã thu</th>
                                <th class="py-2 text-right font-semibold">HH khi thu đủ</th>
                                <th class="py-2 text-right font-semibold">HH đã ghi nhận</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in commission.rows" :key="row.student + row.rank" class="border-t border-surface-container-highest">
                                <td class="py-2 font-bold text-on-surface">
                                    {{ row.student }}
                                    <div v-if="!row.closed_this_month" class="font-normal text-on-surface-variant">Chốt tháng trước, có tiền về tháng này</div>
                                </td>
                                <td class="py-2 text-on-surface-variant">
                                    #{{ row.rank }}
                                    <span v-if="row.closed_at">· chốt {{ row.closed_at }}</span>
                                </td>
                                <td class="py-2 text-right font-mono">{{ pct(row.percent) }}</td>
                                <td class="py-2 text-right">
                                    <UiMoney v-if="row.tuition !== null" :value="row.tuition" />
                                    <span v-else class="text-on-surface-variant">Chưa có học phí</span>
                                </td>
                                <td class="py-2 text-right">
                                    <UiMoney :value="row.paid_total" />
                                    <div v-if="row.fully_paid"><UiBadge color="success">Đã thu đủ</UiBadge></div>
                                    <div v-else-if="row.remaining" class="text-on-surface-variant">còn {{ formatMoney(row.remaining) }}</div>
                                </td>
                                <td class="py-2 text-right"><UiMoney v-if="row.expected !== null" :value="row.expected" /><span v-else class="text-on-surface-variant">—</span></td>
                                <td class="py-2 text-right font-bold"><UiMoney :value="row.earned_total" tone="success" /></td>
                            </tr>
                            <tr v-if="!commission.rows.length">
                                <td colspan="7" class="py-3"><UiEmptyState icon="trending_up" title="Tháng này chưa có học viên chốt hay học phí thu của khách mới." /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-on-surface-variant">
                    Tiền về trong tháng {{ commission.month_label }}: học phí <span class="font-mono">{{ formatMoney(commission.base) }}</span> → hoa hồng
                    <span class="font-mono">{{ formatMoney(commission.amount) }}</span>, ghi vào sổ hoa hồng kỳ lương tháng này; chi trả khi khách đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc.
                </p>
            </section>

            <!-- KPI Học vụ TẠM TÍNH tháng này theo từng đầu mục (KPI thực vào bảng lương khi đánh giá tháng được chốt) -->
            <section v-if="kpi" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm" data-kpi-panel>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h2 class="flex flex-wrap items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-[20px] text-primary">insights</span>
                            KPI tháng {{ kpi.month_label }}
                            <UiBadge color="warning" pill>Tạm tính</UiBadge>
                            <UiBadge :color="kpiStatus[0]">{{ kpiStatus[1] }}</UiBadge>
                        </h2>
                        <p class="text-xs text-on-surface-variant">
                            Mỗi đầu mục: đạt Ngưỡng 50 nhận 50% quỹ mục, đạt Ngưỡng 100 nhận đủ quỹ mục. Số tiền theo điểm đang chấm<span v-if="kpi.evaluator"> ({{ kpi.evaluator }})</span>; KPI thực vào bảng lương khi đánh giá tháng được chốt.
                        </p>
                    </div>
                    <Link v-if="kpi.url" :href="kpi.url" class="text-xs font-bold text-primary hover:underline">Xem phiếu đánh giá &rarr;</Link>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">Quỹ KPI tháng</div>
                        <UiMoney :value="kpi.fund" align="left" class="!text-xl font-black" />
                    </div>
                    <div class="rounded-2xl border border-primary-container/30 bg-primary-container/10 p-4">
                        <div class="text-xs text-on-surface-variant">KPI tạm tính</div>
                        <UiMoney :value="kpi.amount" align="left" tone="success" class="!text-xl font-black" />
                        <div class="text-xs text-on-surface-variant">{{ kpi.score !== null ? pct(kpi.score) + ' · ' + kpi.grade : 'Chưa chấm' }}</div>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">Mục đạt Ngưỡng 100</div>
                        <div class="font-mono text-xl font-black text-on-surface">{{ kpi.counts.full }}/{{ kpiTotal }}</div>
                        <div class="text-xs text-on-surface-variant">{{ kpi.counts.half }} mục đạt Ngưỡng 50</div>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                        <div class="text-xs text-on-surface-variant">Chưa đạt / chưa chấm</div>
                        <div class="font-mono text-xl font-black text-on-surface">{{ kpi.counts.below }} / {{ kpi.counts.pending }}</div>
                    </div>
                </div>

                <div aria-label="Tiến độ quỹ KPI">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-surface-container">
                        <div class="h-2 rounded-full bg-tertiary" :style="{ width: barWidth(kpi.amount, kpi.fund) }"></div>
                    </div>
                </div>

                <div v-for="group in kpi.groups" :key="group.name" class="space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-black uppercase tracking-wider text-on-surface-variant">{{ group.name }}</span>
                        <span class="font-mono text-on-surface-variant"><span class="font-mono">{{ formatMoney(group.amount) }}</span> / <span class="font-mono">{{ formatMoney(group.max) }}</span></span>
                    </div>
                    <div v-for="item in group.items" :key="item.id" class="rounded-2xl border border-surface-container-highest p-3" data-kpi-item>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div class="text-xs font-bold text-on-surface">
                                <span v-if="item.code" class="text-on-surface-variant">{{ item.code }}</span> {{ item.name }}
                                <UiBadge :color="kpiLevel[item.level][0]" class="ml-1">{{ kpiLevel[item.level][1] }}</UiBadge>
                            </div>
                            <div class="text-xs"><span class="font-mono font-bold text-tertiary">{{ formatMoney(item.amount) }}</span> / <span class="font-mono">{{ formatMoney(item.max) }}</span></div>
                        </div>
                        <!-- Thanh mốc: vạch 50% (Ngưỡng 50) và 100% (Ngưỡng 100) -->
                        <div class="relative mt-2 h-2 w-full rounded-full bg-surface-container">
                            <div class="h-2 rounded-full" :class="item.level === 'full' ? 'bg-tertiary' : item.level === 'half' ? 'bg-primary-container' : 'bg-warning'" :style="{ width: barWidth(item.score ?? 0, 100) }"></div>
                            <span class="absolute top-[-2px] h-3 w-0.5 bg-on-surface-variant/50" style="left: 50%" aria-hidden="true"></span>
                        </div>
                        <div class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-on-surface-variant">
                            <span>
                                Ngưỡng 50: {{ item.threshold_half ?? '—' }} · Ngưỡng 100: {{ item.threshold_full ?? '—' }}
                                <span v-if="item.actual"> · Thực tế: {{ item.actual }}</span>
                                <span v-if="item.score !== null"> · Đạt {{ pct(item.score) }}</span>
                            </span>
                            <span v-if="item.next_gain" class="font-semibold text-on-surface">Đạt {{ item.next_label }} để nhận thêm <span class="font-mono">{{ formatMoney(item.next_gain) }}</span></span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <div class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-[20px] text-primary">assignment</span>
                                Nhiệm vụ &amp; Công việc được giao
                            </h2>
                            <Link v-if="tasksUrl" :href="tasksUrl" class="text-xs font-bold text-primary hover:underline">Xem tất cả việc &rarr;</Link>
                        </div>

                        <div class="space-y-2.5">
                            <div v-for="task in myTasks" :key="task.id" class="flex items-center justify-between gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-3.5 transition hover:border-surface-container-highest">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="truncate text-xs font-bold text-on-surface">{{ task.title }}</span>
                                        <UiBadge :color="taskBadge(task.status)[0]" pill>{{ taskBadge(task.status)[1] }}</UiBadge>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-on-surface-variant">
                                        <span v-if="task.due_date" class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs text-on-surface-subtle">event</span>
                                            <span>Hạn: {{ task.due_date }}</span>
                                        </span>
                                        <span v-if="task.time_slot_category">Ca: {{ task.time_slot_category }}</span>
                                    </div>
                                </div>
                                <UiButton v-if="task.url" variant="secondary" size="sm" :href="task.url">Chi tiết</UiButton>
                            </div>
                            <UiEmptyState v-if="!myTasks.length" icon="task" title="Hiện tại bạn không có nhiệm vụ tồn đọng nào cần xử lý." />
                        </div>
                    </div>

                    <!-- Lớp đang dạy / trợ giảng (chỉ người đứng lớp) -->
                    <div v-if="teaches" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-[20px] text-secondary">school</span>
                                Lớp học đang phụ trách
                            </h2>
                            <Link v-if="can('attendance_student.record')" :href="route('teacher.home')" class="text-xs font-bold text-primary hover:underline">Mở Cổng Giáo viên &rarr;</Link>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div v-for="cls in assignedClasses" :key="cls.id" class="space-y-2 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-xs font-extrabold text-on-surface">{{ cls.code }}</span>
                                    <UiBadge color="secondary" pill>{{ cls.status_label }}</UiBadge>
                                </div>
                                <div class="truncate text-xs font-bold text-on-surface">{{ cls.name }}</div>
                                <div class="space-y-0.5 text-xs text-on-surface-variant">
                                    <div>Khóa: {{ cls.course_name ?? 'Chưa cập nhật' }}</div>
                                    <div>Lịch: {{ cls.schedule_text ?? 'Chưa cập nhật' }}</div>
                                </div>
                            </div>
                            <UiEmptyState v-if="!assignedClasses.length" class="col-span-2" icon="meeting_room" title="Chưa có lớp học được gán trực tiếp cho tài khoản này." />
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <!-- Ca dạy gần nhất (chỉ người đứng lớp) -->
                    <div v-if="teaches" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                        <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-[20px] text-tertiary">history_toggle_off</span>
                            Ca dạy gần nhất
                        </h2>
                        <div class="space-y-2.5">
                            <div v-for="ts in recentTimesheets" :key="ts.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/60 p-3 text-xs">
                                <div>
                                    <div class="font-bold text-on-surface">{{ ts.class_code ?? 'Lớp giảng dạy' }}</div>
                                    <div class="text-xs text-on-surface-subtle">{{ ts.date }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-mono font-extrabold text-on-surface">{{ ts.hours }}h</div>
                                    <div class="text-xs font-bold text-tertiary">{{ ts.status_label }}</div>
                                </div>
                            </div>
                            <UiEmptyState v-if="!recentTimesheets.length" icon="history_toggle_off" title="Chưa ghi nhận ca dạy gần đây." />
                        </div>
                    </div>

                    <!-- Nhật ký thao tác -->
                    <div class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                        <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-[20px] text-secondary">history</span>
                            Nhật ký thao tác gần đây
                        </h2>
                        <div class="space-y-3">
                            <div v-for="act in myActivities" :key="act.id" class="space-y-0.5 border-l-2 border-secondary/30 py-0.5 pl-3 text-xs">
                                <div class="font-medium text-on-surface">{{ act.description }}</div>
                                <div class="font-mono text-xs text-on-surface-subtle">{{ act.ago }}</div>
                            </div>
                            <UiEmptyState v-if="!myActivities.length" icon="history" title="Chưa có nhật ký hoạt động hệ thống." />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: LƯƠNG & PHIẾU LƯƠNG CÁ NHÂN -->
        <div v-if="showPayroll" v-show="activeTab === 'payroll'" class="space-y-6">
            <div class="space-y-6 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm sm:p-8">
                <div class="flex flex-col gap-4 border-b border-surface-container-highest pb-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-black text-on-surface">
                            <span class="material-symbols-outlined text-primary">receipt_long</span>
                            Phiếu Lương Cá Nhân Chi Tiết
                        </h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-xl bg-secondary/10 px-3 py-1 text-xs font-bold text-secondary">Kỳ: {{ payrollPeriodLabel }}</span>
                        <UiButton size="sm" icon="visibility" :href="route('portal.my-salary')">Mở Cổng Lương</UiButton>
                    </div>
                </div>

                <template v-if="latestPayroll">
                    <div class="grid grid-cols-1 gap-6 text-xs md:grid-cols-2">
                        <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-5">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-base text-tertiary">add_circle</span>
                                1. Các khoản thu nhập
                            </h3>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Lương cơ bản:</span>
                                <UiMoney :value="latestPayroll.base_salary" class="font-bold" />
                            </div>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Thù lao giảng dạy:</span>
                                <UiMoney :value="latestPayroll.teaching_salary" class="font-bold" />
                            </div>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Thưởng KPI / Doanh số:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ latestPayroll.kpi_bonus }}</span>
                            </div>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Thưởng tái tục học viên:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ latestPayroll.renew_bonus }}</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-on-surface-variant">Phụ cấp &amp; Trợ cấp:</span>
                                <span class="font-mono font-bold text-tertiary">+{{ latestPayroll.allowance }}</span>
                            </div>
                        </div>

                        <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-5">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-base text-error">remove_circle</span>
                                2. Các khoản giảm trừ
                            </h3>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Bảo hiểm XH &amp; Y tế:</span>
                                <span class="font-mono font-bold text-error">-{{ latestPayroll.insurance_deduction }}</span>
                            </div>
                            <div class="flex justify-between border-b border-surface-container-highest py-1.5">
                                <span class="text-on-surface-variant">Thuế TNCN tạm tính:</span>
                                <span class="font-mono font-bold text-error">-{{ latestPayroll.tax_deduction }}</span>
                            </div>
                            <div class="flex justify-between pt-1">
                                <span class="text-on-surface-variant">Giảm trừ phạt / Vi phạm:</span>
                                <span class="font-mono font-bold text-error">-{{ latestPayroll.penalty_deduction }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between gap-4 rounded-2xl bg-gradient-to-r from-inverse-surface via-inverse-surface to-secondary p-6 text-white shadow-xl sm:flex-row sm:items-center">
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-white/70">Tổng thực lĩnh chuyển khoản:</span>
                            <span class="font-mono text-2xl font-black text-primary sm:text-3xl">{{ latestPayroll.net_salary }}</span>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="block text-xs text-white/70">Trạng thái phiếu lương:</span>
                            <span class="flex items-center gap-1 text-sm font-bold text-tertiary sm:justify-end">
                                <span class="material-symbols-outlined text-base">verified</span>
                                <span>{{ latestPayroll.status_label }}</span>
                            </span>
                        </div>
                    </div>
                </template>
                <UiEmptyState
                    v-else
                    class="rounded-2xl border border-surface-container-highest bg-surface-container-low/50"
                    icon="receipt"
                    title="Chưa có bản ghi phiếu lương nào cho tài khoản này."
                    description="Phiếu lương sẽ tự động hiển thị sau khi bộ phận Kế toán / HR chốt bảng lương định kỳ hàng tháng."
                />

                <div class="space-y-3 pt-4">
                    <h3 class="text-sm font-black uppercase tracking-wider text-on-surface">Lịch sử các kỳ lương gần đây</h3>
                    <UiDataTable>
                        <table>
                            <thead>
                                <tr>
                                    <th>Kỳ lương</th>
                                    <th class="text-right">Lương cơ bản</th>
                                    <th class="text-right">Giảng dạy &amp; KPI</th>
                                    <th class="text-right">Giảm trừ</th>
                                    <th class="text-right">Thực lĩnh</th>
                                    <th class="text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in recentPayrolls" :key="p.id">
                                    <td class="font-bold text-on-surface">{{ p.title }}</td>
                                    <td class="text-right"><UiMoney :value="p.base_salary" /></td>
                                    <td class="text-right font-mono text-tertiary">+{{ p.income }}</td>
                                    <td class="text-right font-mono text-error">-{{ p.deduction }}</td>
                                    <td class="text-right font-mono font-black text-primary">{{ p.net_salary }}</td>
                                    <td class="text-center"><UiBadge :color="p.paid ? 'success' : 'secondary'" pill>{{ p.status_label }}</UiBadge></td>
                                </tr>
                                <tr v-if="!recentPayrolls.length">
                                    <td colspan="6"><UiEmptyState icon="receipt_long" title="Chưa có lịch sử kỳ lương nào." /></td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>
                </div>
            </div>
        </div>

        <!-- TAB 3: BÁO LỖI & TICKET CÁ NHÂN -->
        <div v-if="showTickets" v-show="activeTab === 'tickets'" class="space-y-6">
            <div class="space-y-5 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm sm:p-8">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-base font-black text-on-surface">
                            <span class="material-symbols-outlined text-error">bug_report</span>
                            Yêu cầu hỗ trợ &amp; Ticket báo lỗi của bạn
                        </h2>
                    </div>
                    <UiButton icon="add" :href="route('tickets.create')">Tạo Ticket Mới</UiButton>
                </div>

                <div class="space-y-3">
                    <Link v-for="ticket in myTickets" :key="ticket.id" :href="route('tickets.show', ticket.id)" class="group block rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4 shadow-2xs transition hover:border-outline-variant hover:bg-surface-container-lowest">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-primary">{{ ticket.code }}</span>
                                    <span class="text-xs font-bold text-on-surface transition group-hover:text-primary">{{ ticket.title }}</span>
                                    <UiBadge :color="priorityColor(ticket.priority)" pill>{{ ticket.priority_label }}</UiBadge>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-on-surface-variant">
                                    <span>Danh mục: {{ ticket.category_label }}</span>
                                    <span>Gửi lúc: {{ ticket.created_at }}</span>
                                    <span v-if="ticket.assignee_name">Phụ trách: {{ ticket.assignee_name }}</span>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <UiBadge :color="ticketColor(ticket.status)">{{ ticket.status_label }}</UiBadge>
                                <span class="material-symbols-outlined text-on-surface-subtle transition group-hover:text-primary">chevron_right</span>
                            </div>
                        </div>
                    </Link>
                    <UiEmptyState v-if="!myTickets.length" icon="task_alt" title="Bạn chưa gửi yêu cầu hỗ trợ hoặc báo lỗi nào." />
                </div>
            </div>
        </div>

        <!-- TAB 4: CÀI ĐẶT TÀI KHOẢN & ĐỔI MẬT KHẨU -->
        <div v-show="activeTab === 'settings'" class="space-y-6">
            <!-- Học viên: trang này chỉ có tài khoản; lối tắt đưa về các màn của cổng học viên -->
            <div v-if="portal === 'student' && quickLinks.length" class="space-y-4 rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-sm font-black uppercase tracking-wider text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary">apps</span>
                    Cổng học viên
                </h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <Link v-for="link in quickLinks" :key="link.url" :href="link.url" class="flex items-center gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-3 text-xs font-bold text-on-surface transition hover:border-primary-container hover:bg-surface-container-lowest">
                        <span class="material-symbols-outlined text-[20px] text-primary">{{ link.icon }}</span>
                        <span class="min-w-0">{{ link.label }}</span>
                    </Link>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Thông tin tài khoản -->
                <div class="rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm sm:p-8">
                    <section>
                        <header class="space-y-1">
                            <h2 class="text-base font-bold text-on-surface">Thông tin tài khoản</h2>
                            <p class="text-xs text-on-surface-variant">Cập nhật tên hiển thị và địa chỉ email đăng nhập của bạn</p>
                        </header>

                        <UiForm :action="route('profile.update')" method="patch" class="mt-5 space-y-4 text-xs" @success="onSaved">
                            <div>
                                <UiInput id="name" name="name" type="text" label="Họ và tên" :value="account.name" required autofocus autocomplete="name" />
                            </div>

                            <div>
                                <UiInput id="email" name="email" type="email" label="Email đăng nhập" :value="account.email" required autocomplete="username" />

                                <div v-if="account.email_unverified">
                                    <p class="mt-2 text-xs text-on-surface">
                                        Địa chỉ email của bạn chưa được xác thực.
                                        <button v-if="canSendVerification" form="send-verification" class="rounded-md text-xs text-primary underline hover:text-primary-hover focus:outline-none">Bấm vào đây để gửi lại email xác thực.</button>
                                    </p>
                                    <p v-if="shownStatus === 'verification-link-sent' || status === 'verification-link-sent'" class="mt-2 text-xs font-medium text-tertiary">Đã gửi liên kết xác thực mới đến email của bạn.</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 pt-2">
                                <UiButton type="submit">Lưu thay đổi</UiButton>
                                <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
                                    <p v-if="shownStatus === 'profile-updated'" class="flex items-center gap-1 text-xs font-bold text-tertiary">
                                        <span class="material-symbols-outlined text-sm">check_circle</span>
                                        <span>Đã cập nhật thông tin thành công!</span>
                                    </p>
                                </Transition>
                            </div>
                        </UiForm>
                        <UiForm v-if="canSendVerification && account.email_unverified" id="send-verification" :action="route('verification.send')" method="post" class="hidden" @success="onSaved" />
                    </section>
                </div>

                <!-- Đổi mật khẩu -->
                <div class="rounded-3xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm sm:p-8">
                    <section>
                        <header class="space-y-1">
                            <h2 class="text-base font-bold text-on-surface">Đổi mật khẩu</h2>
                            <p class="text-xs text-on-surface-variant">Đảm bảo tài khoản của bạn đang sử dụng mật khẩu dài và ngẫu nhiên để duy trì bảo mật</p>
                        </header>

                        <UiForm :action="route('password.update')" method="put" error-bag="updatePassword" reset-on-success class="mt-5 space-y-4 text-xs" @success="onSaved">
                            <div>
                                <UiInput id="update_password_current_password" name="current_password" type="password" label="Mật khẩu hiện tại" bag="updatePassword" autocomplete="current-password" />
                            </div>
                            <div>
                                <UiInput id="update_password_password" name="password" type="password" label="Mật khẩu mới" bag="updatePassword" autocomplete="new-password" />
                            </div>
                            <div>
                                <UiInput id="update_password_password_confirmation" name="password_confirmation" type="password" label="Xác nhận mật khẩu mới" bag="updatePassword" autocomplete="new-password" />
                            </div>

                            <div class="flex items-center gap-3 pt-2">
                                <UiButton type="submit">Cập nhật mật khẩu</UiButton>
                                <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
                                    <p v-if="shownStatus === 'password-updated'" class="flex items-center gap-1 text-xs font-bold text-tertiary">
                                        <span class="material-symbols-outlined text-sm">check_circle</span>
                                        <span>Đã đổi mật khẩu thành công!</span>
                                    </p>
                                </Transition>
                            </div>
                        </UiForm>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>
