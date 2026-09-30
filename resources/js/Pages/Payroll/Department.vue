<script setup>
/**
 * Bảng lương theo khối của một kỳ: GV Full-time · Khối Học thuật · Khối Học vụ & Vận hành (công thức Q3, A6 25/09/2026).
 * Ba trang cùng bố cục — tiêu đề, màu thẻ tổng và phần quy chế lấy theo `department`.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trimNumber } from './format';
import PeriodTabs from './PeriodTabs.vue';

defineOptions({ layout: { title: 'Bảng lương theo khối' } });

const props = defineProps({
    department: { type: String, required: true },
    period: { type: Object, required: true },
    records: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
});

// Chuỗi class viết đầy đủ để Tailwind quét được.
const configs = {
    fulltime: {
        title: 'Bảng lương giáo viên full-time',
        icon: 'badge',
        listIcon: 'work',
        listTitle: 'Danh Sách Giáo Viên Cơ Hữu (Full-Time)',
        emptyText: 'Chưa có bản ghi lương giáo viên full-time trong kỳ này.',
        avatarClass: 'bg-primary-container/10 text-primary',
        showCommission: false,
        showRenewal: true,
        guideIcon: 'functions',
        guideTitle: 'Quy Chế Tính Lương GV Cơ Hữu MEnglish',
        guides: [
            ['1. Lương cơ bản & khấu trừ', 'BHXH, Công đoàn tự động trên lương cơ bản (tỉ lệ ở Tham số tính lương); thuế TNCN Admin nhập tay. Không trả thêm theo giờ dạy — buổi dạy chỉ để đối soát.'],
            ['2. KPI (nhập tự do)', 'GV Full-time: Admin / Kế toán nhập số tiền KPI trên phiếu lương, giữ khi tính lại.'],
            ['3. Thưởng tái tục', '% theo số HS nghỉ trong lớp phụ trách (giữ đủ → 1%, nghỉ 1 → 0,7%, các mốc khác chờ BA) × doanh thu lớp trong kỳ.'],
        ],
        cardClass: 'from-warning to-warning',
        accentClass: 'text-warning-container',
        cardTitle: 'Tổng chi GV Full-Time',
        cardIcon: 'account_balance',
        countText: 'giáo viên cơ hữu trong kỳ',
        figures: (t) => [['Thưởng tái tục:', t.renew_bonus], ['Tổng KPI thưởng:', t.kpi_bonus]],
    },
    academic: {
        title: 'Bảng lương khối Học thuật (R&D / Khảo thí)',
        icon: 'school',
        listIcon: 'psychology',
        listTitle: 'Danh Sách Nhân Sự Khối Học Thuật',
        emptyText: 'Chưa có bản ghi lương nhân sự khối học thuật trong kỳ này.',
        avatarClass: 'bg-accent-container text-accent',
        showCommission: false,
        showRenewal: true,
        guideIcon: 'menu_book',
        guideTitle: 'Cơ Cấu Thu Nhập Khối Học Thuật',
        guides: [
            ['1. Lương cơ bản & khấu trừ', 'BHXH, Công đoàn tự động trên lương cơ bản; thuế TNCN Admin nhập tay; trừ vi phạm quá hạn nộp.'],
            ['2. KPI (nhập tự do)', 'Học thuật: Admin / Kế toán nhập số tiền KPI trên phiếu lương.'],
            ['3. Phụ cấp & thưởng tái tục', 'Phụ cấp / thưởng là các dòng tự do có tên. Thưởng tái tục nếu phụ trách lớp.'],
        ],
        cardClass: 'from-on-info-container to-on-accent-container',
        accentClass: 'text-accent-container',
        cardTitle: 'Tổng chi Khối Học Thuật',
        cardIcon: 'school',
        countText: 'chuyên viên học thuật',
        figures: (t) => [['Lương cứng:', t.base_salary], ['KPI & Phụ cấp:', t.kpi_bonus + t.allowance]],
    },
    operations: {
        title: 'Bảng lương khối Học vụ & Vận hành (CSKH / Sales)',
        icon: 'support_agent',
        listIcon: 'support_agent',
        listTitle: 'Danh Sách Nhân Sự Học Vụ & Vận Hành',
        emptyText: 'Chưa có bản ghi lương nhân sự học vụ trong kỳ này.',
        avatarClass: 'bg-secondary/10 text-secondary',
        showCommission: true,
        showRenewal: false,
        guideIcon: 'receipt_long',
        guideTitle: 'Cách tính hoa hồng khối vận hành',
        guides: [
            // Theo SalesCommissionService / cấu hình mốc hoa hồng; không ghi cứng tỷ lệ ở đây.
            ['1. Hoa hồng tuyển mới', '% theo bậc số HS chốt trong kỳ (mặc định 3% / 4% / 5%) × tiền thực thu của khách mới. Chỉ trả khi đủ 30 ngày từ ngày chốt và đủ 3/3 mốc chăm sóc; chưa đủ thì hoãn sang kỳ sau.', true],
            ['2. KPI Học vụ (tự động)', 'Quỹ KPI × điểm KPI 6 nhóm / 15 mục của đánh giá tháng đã chốt. Nhân viên không tự chấm. Vận hành khác: KPI nhập tay.'],
            ['3. Thu hồi hoa hồng', 'Khi hoàn phí có chọn thu hồi, khoản thu hồi được trừ ở lần tính lương kế tiếp.'],
        ],
        cardClass: 'from-on-info-container to-inverse-surface',
        accentClass: 'text-info-container',
        cardTitle: 'Tổng chi Khối Vận Hành',
        cardIcon: 'support_agent',
        countText: 'nhân viên vận hành & học vụ',
        figures: (t) => [['Lương cứng:', t.base_salary], ['Tổng hoa hồng:', t.commission_bonus]],
    },
};
const config = computed(() => configs[props.department] ?? configs.fulltime);
const colspan = computed(() => 9 + (config.value.showCommission ? 1 : 0) + (config.value.showRenewal ? 1 : 0));
const initial = (name) => Array.from(name ?? 'N')[0] ?? '';
</script>

<template>
    <UiPageHeader :title="config.title" :icon="config.icon" :back="route('payroll.periods.show', period.id)">
        <template #badges>
            <span :class="['text-xs px-2.5 py-0.5 rounded-full border font-bold', period.status_badge]">{{ period.status_label }}</span>
        </template>
        <template #meta>Kỳ tính lương: {{ period.title }} ({{ period.code }})</template>
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="route('payroll.periods.export', { id: period.id, department })" native>Xuất Excel</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <PeriodTabs :period-id="period.id" :current="department" department />

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            <div class="xl:col-span-8 space-y-6">
                <UiDataTable class="shadow-sm">
                    <template #header>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary text-base">{{ config.listIcon }}</span>
                            <span>{{ config.listTitle }}</span>
                        </h3>
                        <span class="text-xs font-bold text-on-surface-variant font-mono">{{ records.length }} nhân sự</span>
                    </template>
                    <!-- Lương CB + KPI + (hoa hồng) + (thưởng tái tục) + phụ cấp tự do − BHXH − Công đoàn − thuế TNCN − phạt & trừ khác -->
                    <table class="text-xs">
                        <thead>
                            <tr>
                                <th>Nhân sự</th>
                                <th class="text-right">Lương cơ bản</th>
                                <th class="text-right">KPI</th>
                                <th v-if="config.showCommission" class="text-right">Hoa hồng</th>
                                <th v-if="config.showRenewal" class="text-right">Thưởng tái tục</th>
                                <th class="text-right">Phụ cấp tự do</th>
                                <th class="text-right">BHXH</th>
                                <th class="text-right">Công đoàn</th>
                                <th class="text-right">Thuế TNCN</th>
                                <th class="text-right">Phạt &amp; trừ khác</th>
                                <th class="text-right font-black">Thực lĩnh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in records" :key="r.id">
                                <td class="font-bold">
                                    <div class="flex items-center gap-2">
                                        <div :class="['w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs', config.avatarClass]">{{ initial(r.name) }}</div>
                                        <div>
                                            <Link :href="route('payroll.records.show', r.id)" class="text-xs font-bold text-on-surface hover:text-primary hover:underline" title="Xem phiếu lương">{{ r.name }}</Link>
                                            <p class="text-xs text-on-surface-subtle font-mono">{{ r.email }} · {{ r.salary_role_label }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right font-mono font-semibold">
                                    {{ formatMoney(r.base_salary + r.teaching_salary) }}
                                    <p v-if="r.teaching_sessions > 0" class="text-xs text-on-surface-subtle">{{ r.teaching_sessions }} buổi dạy</p>
                                </td>
                                <td class="text-right font-mono text-warning font-semibold">
                                    {{ formatMoney(r.kpi_bonus) }}
                                    <p v-if="r.kpi_source === 'academic_kpi'" class="text-xs text-on-surface-subtle">{{ r.kpi_score !== null ? trimNumber(r.kpi_score, 2, '.', ',') + '% × quỹ' : 'chưa chấm KPI' }}</p>
                                    <p v-else-if="r.kpi_source === 'manual'" class="text-xs text-on-surface-subtle">{{ r.kpi_manual_amount !== null ? 'nhập tay' : 'chưa nhập' }}</p>
                                </td>
                                <td v-if="config.showCommission" class="text-right font-mono text-tertiary font-semibold">
                                    {{ formatMoney(r.commission_bonus) }}
                                    <p v-if="r.commission_deferred > 0" class="text-xs text-warning font-semibold">Hoãn {{ formatMoney(r.commission_deferred) }}</p>
                                </td>
                                <td v-if="config.showRenewal" class="text-right font-mono text-tertiary font-semibold">{{ formatMoney(r.renew_bonus) }}</td>
                                <td class="text-right font-mono">{{ formatMoney(r.free_allowance) }}</td>
                                <td class="text-right font-mono text-error">-{{ formatMoney(r.insurance_deduction) }}</td>
                                <td class="text-right font-mono text-error">-{{ formatMoney(r.union_deduction) }}</td>
                                <td class="text-right font-mono text-error">-{{ formatMoney(r.tax_deduction) }}</td>
                                <td class="text-right font-mono text-error">-{{ formatMoney(r.other_deductions) }}</td>
                                <td class="text-right font-mono font-black text-primary text-sm">{{ formatMoney(r.net_salary) }}</td>
                            </tr>
                            <tr v-if="!records.length">
                                <td :colspan="colspan"><UiEmptyState :title="config.emptyText" /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-base">{{ config.guideIcon }}</span>
                        <span>{{ config.guideTitle }}</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div v-for="[title, text, tiersLink] in config.guides" :key="title" class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest space-y-1">
                            <span class="font-bold text-on-surface">{{ title }}</span>
                            <p class="text-on-surface-variant text-xs">
                                {{ text }}
                                <Link v-if="tiersLink && can('commission_config.manage')" :href="route('payroll.config.commission-tiers')" class="text-primary font-semibold hover:underline">Xem mốc hoa hồng</Link>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-4 space-y-4">
                <div :class="['bg-gradient-to-br text-white rounded-2xl p-6 shadow-md space-y-4', config.cardClass]">
                    <div class="flex items-center justify-between">
                        <span :class="['text-xs font-bold uppercase tracking-wider', config.accentClass]">{{ config.cardTitle }}</span>
                        <span :class="['material-symbols-outlined text-2xl', config.accentClass]">{{ config.cardIcon }}</span>
                    </div>
                    <div>
                        <div class="text-3xl font-black font-mono tracking-tight">{{ formatMoney(totals.net_salary) }}</div>
                        <p :class="['text-xs mt-1', config.accentClass]">{{ records.length }} {{ config.countText }}</p>
                    </div>
                    <div class="pt-3 border-t border-white/20 grid grid-cols-2 gap-2 text-xs">
                        <div v-for="[label, value] in config.figures(totals)" :key="label">
                            <span :class="['block text-xs uppercase font-bold', config.accentClass]">{{ label }}</span>
                            <span class="font-bold font-mono text-sm">{{ formatMoney(value) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
