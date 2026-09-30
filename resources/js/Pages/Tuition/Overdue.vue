<script setup>
/**
 * Quá hạn & Nhắc phí (mockup epic-8-thu-phi-qua-han): đôn đốc từng khoản quá hạn / sắp đến hạn,
 * khoản đang khất nợ / bảo lưu và thống kê công nợ theo chi nhánh / lớp.
 */
import { computed } from 'vue';
import DueGroups from './Partials/DueGroups.vue';

defineOptions({ layout: { title: 'Quá hạn & Nhắc phí' } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    statsByBranch: { type: Array, default: () => [] },
    statsByClass: { type: Array, default: () => [] },
    paused: { type: Array, default: () => [] },
    seriousOverdue: { type: Array, default: () => [] },
    newOverdue: { type: Array, default: () => [] },
    upcoming: { type: Object, required: true },
    overdueCount: { type: Number, default: 0 },
    type: { type: String, default: 'all' },
    seriousDays: { type: Number, default: 7 },
    upcomingDays: { type: Number, default: 14 },
});

const typeOptions = [
    { value: 'all', label: 'Quá hạn & sắp đến hạn' },
    { value: 'overdue', label: 'Chỉ quá hạn' },
    { value: 'upcoming', label: 'Chỉ sắp đến hạn' },
];
const dueGroupProps = computed(() => ({
    seriousOverdue: props.seriousOverdue,
    newOverdue: props.newOverdue,
    upcoming: props.upcoming,
    overdueCount: props.overdueCount,
    type: props.type,
    seriousDays: props.seriousDays,
    upcomingDays: props.upcomingDays,
}));
</script>

<template>
    <UiPageHeader title="Quá hạn & Nhắc phí" description="Đôn đốc thu phí: lập phiếu thu, gửi nhắc nợ, ghi nhận liên hệ cho khoản quá hạn và sắp đến hạn.">
        <template #actions>
            <UiButton variant="ghost" icon="menu_book" :href="route('tuition.students')">Xem sổ công nợ</UiButton>
            <UiButton v-if="can('fee_reminder_config.manage')" variant="secondary" icon="settings" :href="route('system-config.debt-reminders')">Cấu hình nhắc nợ</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :action="route('tuition.overdue')" placeholder="Họ tên, SĐT hoặc mã học viên...">
        <UiSelect name="branch_id" :options="branches" placeholder="Tất cả chi nhánh" label="Chi nhánh" />
        <UiSelect name="class_id" :options="classes" placeholder="Tất cả lớp học" label="Lớp" />
        <UiSelect name="type" :options="typeOptions" label="Nhóm" />
    </UiFilterBar>

    <div id="tuition-overdue-list" class="space-y-xl">
        <DueGroups v-bind="dueGroupProps" />

        <!-- Đang khất nợ / bảo lưu -->
        <section v-if="paused.length" class="space-y-md">
            <div class="flex items-center gap-sm border-l-4 border-outline pl-sm">
                <h2 class="font-h3 text-h3 text-on-surface">Đang khất nợ / bảo lưu (tạm dừng nhắc nợ)</h2>
            </div>
            <UiDataTable min-width="720px">
                <table>
                    <thead>
                        <tr><th>Học sinh</th><th>Lớp</th><th class="text-right">Còn nợ</th><th>Hạn đóng</th><th>Tạm dừng nhắc tới</th><th>Ghi chú</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="ot in paused" :key="ot.id">
                            <td>{{ ot.student?.name }} <UiCode :value="ot.student?.code" class="font-caption text-caption text-on-surface-variant" /></td>
                            <td>{{ ot.class_name ?? '—' }}</td>
                            <td><UiMoney :value="ot.debt_amount" /></td>
                            <td class="font-code text-code">{{ ot.due_date }}</td>
                            <td class="font-code text-code">{{ ot.reminder_paused_until }}</td>
                            <td>
                                <UiBadge v-if="ot.deferred_until" color="info">Bảo lưu tới {{ ot.deferred_until }}</UiBadge>
                                <UiBadge v-else color="warning">Khất nợ</UiBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </section>

        <!-- Thống kê -->
        <section class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <UiDataTable>
                <template #header><h3 class="font-h3 text-h3">Công nợ theo chi nhánh</h3></template>
                <table>
                    <thead><tr><th>Chi nhánh</th><th class="text-center">Đang nợ</th><th class="text-center">Quá hạn</th><th class="text-right">Tổng nợ</th></tr></thead>
                    <tbody>
                        <tr v-for="(sb, i) in statsByBranch" :key="i">
                            <td>{{ sb.branch_name }}</td>
                            <td class="text-center font-code">{{ sb.count }}</td>
                            <td class="text-center font-code text-error">{{ sb.overdue_count }}</td>
                            <td><UiMoney :value="sb.total_debt" /></td>
                        </tr>
                        <tr v-if="!statsByBranch.length">
                            <td colspan="4"><UiEmptyState icon="account_balance_wallet" title="Chưa có công nợ." /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
            <UiDataTable>
                <template #header><h3 class="font-h3 text-h3">Công nợ theo lớp</h3></template>
                <table>
                    <thead><tr><th>Lớp</th><th class="text-center">Đang nợ</th><th class="text-center">Quá hạn</th><th class="text-right">Tổng nợ</th></tr></thead>
                    <tbody>
                        <tr v-for="(sc, i) in statsByClass" :key="i">
                            <td>{{ sc.class_name }} <span class="font-caption text-caption text-on-surface-variant">{{ sc.class_code }}</span></td>
                            <td class="text-center font-code">{{ sc.count }}</td>
                            <td class="text-center font-code text-error">{{ sc.overdue_count }}</td>
                            <td><UiMoney :value="sc.total_debt" /></td>
                        </tr>
                        <tr v-if="!statsByClass.length">
                            <td colspan="4"><UiEmptyState icon="account_balance_wallet" title="Chưa có công nợ." /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </section>
    </div>
</template>
