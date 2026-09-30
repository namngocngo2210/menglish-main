<script setup>
/**
 * Tổng hợp KPI & đánh giá tháng (roundcuoi 02/03_tong_hop_kpi_danh_gia_thang): danh sách nhân sự của kỳ với tổng KPI đạt,
 * xếp loại, tiền KPI dự tính (Học vụ), trạng thái (chưa đánh giá / bản nháp / đã chốt) → mở phiếu đánh giá.
 */
defineOptions({ layout: { title: 'Tổng hợp KPI & Đánh giá tháng' } });

const props = defineProps({
    staff: { type: Object, required: true },
    month: { type: Number, required: true },
    year: { type: Number, required: true },
    periodValue: { type: String, required: true },
    periodOptions: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
});

const title = `Tổng hợp KPI & Đánh giá tháng ${String(props.month).padStart(2, '0')}/${String(props.year).padStart(4, '0')}`;
const scoreClass = (s) => (s.score === null ? 'text-on-surface-variant' : s.score >= 85 ? 'text-tertiary' : s.score >= 70 ? 'text-warning' : 'text-error');
</script>

<template>
    <div>
        <UiPageHeader :title="title" description="Đánh giá hiệu suất nhân sự theo bộ chỉ số KPI (Học vụ: 6 nhóm / 15 mục, quỹ KPI tính lương tự động).">
            <template #actions>
                <UiButton variant="secondary" icon="tune" :href="route('kpi.criteria')">Cấu hình chỉ số</UiButton>
            </template>
        </UiPageHeader>

        <UiFilterBar placeholder="Tìm nhân sự (tên, mã NV)..." submit-label="Xem">
            <UiSelect name="period" :options="periodOptions" :value="periodValue" label="Kỳ đánh giá" />
            <UiSelect name="role" :options="roleOptions" placeholder="Mọi vai trò" label="Vai trò" />
        </UiFilterBar>

        <UiDataTable min-width="860px">
            <table>
                <thead>
                    <tr>
                        <th>Nhân sự</th>
                        <th>Vai trò</th>
                        <th class="text-right">Tổng KPI đạt</th>
                        <th class="text-center">Xếp loại</th>
                        <th class="text-right">Tiền KPI dự tính</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in staff.data" :key="s.id">
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="s.name" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ s.name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ s.code }}</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ s.role }}</td>
                        <td :class="['text-right font-mono font-semibold', scoreClass(s)]">{{ s.score_label }}</td>
                        <td class="text-center">
                            <span v-if="s.grade" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary-fixed font-semibold text-primary" :title="s.grade_label">{{ s.grade }}</span>
                            <template v-else>—</template>
                        </td>
                        <td class="text-right">
                            <UiMoney v-if="s.kpi_amount !== null" :value="s.kpi_amount" />
                            <span v-else class="font-mono">—</span>
                        </td>
                        <td>
                            <UiBadge v-if="!s.evaluated" color="neutral">Chưa đánh giá</UiBadge>
                            <UiBadge v-else-if="s.status === 'confirmed'" color="success">Đã chốt</UiBadge>
                            <UiBadge v-else color="warning">Bản nháp</UiBadge>
                        </td>
                        <td class="text-right">
                            <UiButton :variant="s.evaluated ? 'ghost' : 'secondary'" size="sm" :icon="s.evaluated ? 'visibility' : 'rate_review'" :href="s.evaluate_url">{{ s.evaluated ? 'Xem / Sửa' : 'Đánh giá' }}</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!staff.data.length">
                        <td colspan="7"><UiEmptyState icon="group_off" title="Chưa có nhân sự nào để đánh giá" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="staff" unit="nhân sự" /></template>
        </UiDataTable>
    </div>
</template>
