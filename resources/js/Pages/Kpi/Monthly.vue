<script setup>
/**
 * Phiếu KPI tháng: mỗi nhân sự một dòng (vai trò có tiêu chí KPI), lọc theo kỳ lương / vai trò / cơ sở.
 * Bấm dòng → mở phiếu trong modal (Kpi/Evaluate): số liệu từng tiêu chí, điền tay phần còn trống, Duyệt / Không duyệt.
 * Phiếu tự tạo đầu tháng cho mọi nhân sự có tiêu chí KPI (lệnh kpi:create-sheets).
 */
defineOptions({ layout: { title: 'Phiếu KPI tháng' } });

defineProps({
    staff: { type: Object, required: true },
    filters: { type: Object, required: true },
    currentPeriod: { type: String, required: true },
    periodOptions: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
    branchOptions: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <UiPageHeader title="Phiếu KPI tháng" description="Mỗi nhân sự một phiếu, tự tạo đầu tháng theo tiêu chí KPI của vai trò. Bấm vào một dòng để mở phiếu, điền số liệu còn trống rồi Duyệt hoặc Không duyệt." />

        <UiFilterBar :search="false" submit-label="Xem">
            <UiSelect name="period" :options="periodOptions" :value="filters.period" label="Kỳ lương" :searchable="false" />
            <UiSelect name="role" :options="roleOptions" :value="filters.role" placeholder="Tất cả vai trò" label="Vai trò" />
            <UiSelect name="branch_id" :options="branchOptions" :value="filters.branch_id" placeholder="Tất cả cơ sở" label="Cơ sở" />
        </UiFilterBar>

        <UiDataTable min-width="720px">
            <table>
                <thead>
                    <tr>
                        <th>Nhân sự</th>
                        <th>Vai trò</th>
                        <th class="text-right">Tỉ lệ đạt</th>
                        <th class="text-right">Thưởng KPI</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in staff.data" :key="s.id" :data-href="s.url" data-modal="4xl" class="cursor-pointer">
                        <td>
                            <p class="font-semibold text-on-surface">{{ s.name }}</p>
                            <p v-if="s.branch" class="font-caption text-caption text-on-surface-variant">{{ s.branch }}</p>
                        </td>
                        <td class="whitespace-nowrap">{{ s.role }}</td>
                        <td class="whitespace-nowrap text-right font-mono">{{ s.rate_label }}</td>
                        <td class="whitespace-nowrap text-right font-mono">{{ s.amount_label }}</td>
                        <td><UiBadge :color="s.status_color">{{ s.status_label }}</UiBadge></td>
                    </tr>
                    <tr v-if="!staff.data.length">
                        <td colspan="5">
                            <UiEmptyState icon="group_off" title="Không có phiếu KPI nào" description="Chỉ nhân sự có vai trò đã có tiêu chí KPI (Cài đặt → Tiêu chí KPI) mới có phiếu." />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="staff" unit="phiếu" /></template>
        </UiDataTable>
    </div>
</template>
