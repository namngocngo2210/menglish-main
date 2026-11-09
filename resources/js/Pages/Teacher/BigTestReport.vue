<script setup>
/**
 * Bảng điểm Big Test các lớp GV phụ trách (GV chính hoặc GVNN).
 */
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';

defineOptions({ layout: { title: 'Bảng điểm Big Test' } });

defineProps({
    results: { type: Object, required: true },
});
</script>

<template>
    <UiPageHeader title="Bảng điểm Big Test các lớp tôi phụ trách" icon="military_tech" :back="route('teacher.home')" />

    <UiDataTable class="shadow-sm">
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Học viên</th>
                    <th>Kỳ thi / Lớp</th>
                    <th>Điểm thành phần</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in results.data" :key="r.id">
                    <td class="font-bold">{{ r.student_name ?? '—' }}</td>
                    <td>
                        <div class="font-semibold text-primary">{{ r.test_title ?? '—' }}</div>
                        <div class="text-xs text-on-surface-subtle">{{ r.class_name }}</div>
                    </td>
                    <td class="font-mono">{{ r.components.length ? r.components.map((c) => `${c.name}: ${c.score}`).join(' · ') : '—' }}</td>
                    <td>
                        <UiBadge :color="r.approved ? 'success' : 'warning'" pill>{{ r.approved ? 'Đã duyệt' : 'Chờ duyệt' }}</UiBadge>
                    </td>
                </tr>
                <tr v-if="!results.data.length">
                    <td colspan="4"><UiEmptyState icon="military_tech" title="Chưa có kết quả Big Test nào cho các lớp của bạn." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="results" /></template>
    </UiDataTable>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    <TeacherBottomNav />
</template>
