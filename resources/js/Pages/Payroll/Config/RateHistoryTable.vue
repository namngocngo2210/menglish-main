<script setup>
/**
 * Bảng lịch sử phiên bản đơn giá GV (danh sách chung có cột GV + phân trang; hoặc lịch sử riêng một GV trong modal chi tiết).
 * Mỗi dòng: loại GV, đơn giá, đơn vị, hiệu lực từ / đến ngày (ngày trước phiên bản kế tiếp), trạng thái, ghi chú + người tạo.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    rows: { type: Array, required: true },
    withTeacher: { type: Boolean, default: false },
    title: { type: String, default: null },
    paginator: { type: Object, default: null },
});
</script>

<template>
    <UiDataTable min-width="1000px" :sticky="withTeacher ? 'first' : null">
        <template v-if="title" #header>
            <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">history</span>{{ title }}
            </h3>
        </template>
        <table>
            <thead>
                <tr>
                    <th v-if="withTeacher">Giáo viên</th>
                    <th>Loại GV</th>
                    <th class="text-right">Đơn giá</th>
                    <th>Đơn vị tính</th>
                    <th>Hiệu lực từ</th>
                    <th>Đến ngày</th>
                    <th>Trạng thái</th>
                    <th class="min-w-[16rem]">Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td v-if="withTeacher" class="font-semibold"><Link :href="route('payroll.config.teacher-rates', { teacher_id: row.user_id })" class="hover:text-primary">{{ row.user_name ?? '—' }}</Link></td>
                    <td>{{ row.teacher_type_label }}</td>
                    <td><UiMoney :value="row.hourly_rate" suffix="" /></td>
                    <td>{{ row.unit }} <span class="sr-only">{{ row.unit_label }}</span></td>
                    <td class="font-code text-code">{{ row.effective_from }}</td>
                    <td class="font-code text-code">{{ row.end ?? 'Hiện tại' }}</td>
                    <td><UiBadge :color="row.state_color">{{ row.state_label }}</UiBadge></td>
                    <td>
                        {{ row.note ?? '—' }}
                        <span class="block font-caption text-caption text-on-surface-variant">{{ row.creator }} · {{ row.created_at }}</span>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="withTeacher ? 8 : 7"><UiEmptyState icon="history" title="Chưa có lịch sử đơn giá" description="Bấm “Cập nhật đơn giá” để thêm đơn giá mới." /></td>
                </tr>
            </tbody>
        </table>
        <template v-if="paginator" #footer><UiPagination :paginator="paginator" /></template>
    </UiDataTable>
</template>
