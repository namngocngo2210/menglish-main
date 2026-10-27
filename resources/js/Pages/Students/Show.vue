<script setup>
/** Chi tiết hồ sơ học sinh. Nội dung (đầu trang, số chính, tab): Components/Students/StudentProfile. */
import { Link } from '@inertiajs/vue3';
import StudentProfile from '@/Components/Students/StudentProfile.vue';
import { useBackLink } from '@/lib/backLink';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Chi tiết hồ sơ học sinh' } });

defineProps({
    student: { type: Object, required: true },
});
const back = useBackLink(() => route('students.index'), 'Danh sách học sinh');
</script>

<template>
    <!-- Tên, trạng thái, thao tác nằm ở đầu hồ sơ (StudentProfile) — ở đây chỉ còn đường dẫn + quay lại. -->
    <div class="mb-md flex flex-wrap items-center justify-between gap-sm">
        <nav aria-label="Đường dẫn" class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
            <Link :href="route('students.index')" class="hover:text-primary">Học viên</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span class="font-semibold text-on-surface">{{ student.name }}</span>
        </nav>
        <div class="flex flex-wrap items-center gap-sm">
            <UiButton variant="ghost" size="sm" icon="admin_panel_settings" :href="route('students.scoped', student.id)">Xem theo phân quyền</UiButton>
            <UiButton variant="ghost" size="sm" icon="arrow_back" :href="back.href" data-back-link>{{ back.label }}</UiButton>
        </div>
    </div>

    <StudentProfile v-bind="$attrs" :student="student" />
</template>
