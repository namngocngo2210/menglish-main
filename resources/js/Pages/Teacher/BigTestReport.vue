<script setup>
/**
 * Bảng điểm Big Test các lớp GV phụ trách (GV chính hoặc GVNN).
 */
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';

defineOptions({ layout: { title: 'Bảng điểm Big Test' } });

defineProps({
    results: { type: Object, required: true },
});

const show = (v) => (v === null || v === undefined ? '—' : v);
</script>

<template>
    <UiPageHeader title="Bảng điểm Big Test các lớp tôi phụ trách" icon="military_tech" :back="route('teacher.home')" />

    <UiDataTable class="shadow-sm">
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Học viên</th>
                    <th>Kỳ thi / Lớp</th>
                    <th class="text-center">Nghe</th>
                    <th class="text-center">Đọc</th>
                    <th class="text-center">Viết</th>
                    <th class="text-center">Nói</th>
                    <th class="text-center">Tổng</th>
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
                    <td class="text-center font-mono">{{ show(r.listening_score) }}</td>
                    <td class="text-center font-mono">{{ show(r.reading_score) }}</td>
                    <td class="text-center font-mono">{{ show(r.writing_score) }}</td>
                    <td class="text-center font-mono">{{ show(r.speaking_score) }}</td>
                    <td class="text-center font-mono font-bold text-primary">{{ show(r.overall_score) }}</td>
                    <td>
                        <UiBadge :color="r.approved ? 'success' : 'warning'" pill>{{ r.approved ? 'Đã duyệt' : 'Chờ duyệt' }}</UiBadge>
                    </td>
                </tr>
                <tr v-if="!results.data.length">
                    <td colspan="8"><UiEmptyState icon="military_tech" title="Chưa có kết quả Big Test nào cho các lớp của bạn." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="results" /></template>
    </UiDataTable>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    <TeacherBottomNav />
</template>
