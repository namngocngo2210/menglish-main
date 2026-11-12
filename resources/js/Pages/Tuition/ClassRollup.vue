<script setup>
/**
 * Rollup thu học phí theo lớp (chỉ đọc): mỗi học viên một dòng, mỗi đợt thu một cụm cột
 * (Thực thu / Tình trạng / Hình thức nộp / Số hóa đơn), dòng cuối là tổng "Thực thu".
 */
defineOptions({ layout: { title: 'Rollup thu học phí theo lớp' } });

defineProps({
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    rows: { type: Array, default: () => [] },
    roundCount: { type: Number, default: 0 },
    totals: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
});
</script>

<template>
    <UiPageHeader title="Rollup thu học phí theo lớp" description="Số tiền thực thu (phiếu đã duyệt) của từng học viên theo từng đợt thu trong lớp." />

    <UiFilterBar :action="route('tuition.class-rollup')" :search="false">
        <UiSelect name="class_id" :options="classes" placeholder="Chọn lớp học" label="Lớp" />
    </UiFilterBar>

    <UiEmptyState v-if="!classId" icon="account_balance_wallet" title="Chọn một lớp để xem rollup thu học phí" />
    <UiEmptyState v-else-if="!rows.length" icon="group_off" title="Lớp chưa có học viên hoặc khoản học phí" />

    <div v-else class="overflow-x-auto rounded-lg border border-surface-container-highest">
        <table class="w-full text-body-sm">
            <thead class="bg-surface-container-low">
                <tr>
                    <th rowspan="2" class="px-md py-sm text-left">Học viên</th>
                    <th v-for="n in roundCount" :key="n" colspan="4" class="border-l border-surface-container-highest px-md py-sm text-center">Đợt thu {{ n }}</th>
                </tr>
                <tr>
                    <template v-for="n in roundCount" :key="n">
                        <th class="border-l border-surface-container-highest px-md py-sm text-right">Thực thu</th>
                        <th class="px-md py-sm text-left">Tình trạng</th>
                        <th class="px-md py-sm text-left">Hình thức nộp</th>
                        <th class="px-md py-sm text-left">Số hóa đơn</th>
                    </template>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id" class="border-t border-surface-container-highest">
                    <td class="px-md py-sm whitespace-nowrap">{{ row.name }} <span class="text-on-surface-variant">{{ row.code }}</span></td>
                    <template v-for="(_, i) in roundCount" :key="i">
                        <template v-if="row.rounds[i]">
                            <td class="border-l border-surface-container-highest px-md py-sm"><UiMoney :value="row.rounds[i].amount" /></td>
                            <td class="px-md py-sm whitespace-nowrap">{{ row.rounds[i].status }}</td>
                            <td class="px-md py-sm">{{ row.rounds[i].methods || '—' }}</td>
                            <td class="px-md py-sm">{{ row.rounds[i].invoices || '—' }}</td>
                        </template>
                        <td v-else colspan="4" class="border-l border-surface-container-highest px-md py-sm text-center text-on-surface-variant">—</td>
                    </template>
                </tr>
            </tbody>
            <tfoot v-if="roundCount > 0" class="bg-surface-container-low font-semibold">
                <tr class="border-t border-surface-container-highest">
                    <td class="px-md py-sm">Thực thu (tổng: <UiMoney :value="total" align="left" class="inline" />)</td>
                    <template v-for="(sum, i) in totals" :key="i">
                        <td class="border-l border-surface-container-highest px-md py-sm"><UiMoney :value="sum" /></td>
                        <td colspan="3" />
                    </template>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
