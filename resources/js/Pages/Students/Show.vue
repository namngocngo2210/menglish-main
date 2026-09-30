<script setup>
/** Chi tiết hồ sơ học sinh (mockup epic-6/chi-tiet-ho-so-hoc-sinh-desktop). Nội dung: Components/Students/StudentProfile. */
import { Link } from '@inertiajs/vue3';
import StudentProfile from '@/Components/Students/StudentProfile.vue';

defineOptions({ layout: { title: 'Chi tiết hồ sơ học sinh' } });

defineProps({
    student: { type: Object, required: true },
});
</script>

<template>
    <UiPageHeader title="Chi tiết hồ sơ học sinh" :description="`${student.name} · ${student.code}`">
        <template #breadcrumbs>
            <Link :href="route('dashboard')" class="hover:text-primary">Trang chủ</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <Link :href="route('students.index')" class="hover:text-primary">Hồ sơ học sinh</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span class="font-semibold text-primary">Chi tiết</span>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="admin_panel_settings" :href="route('students.scoped', student.id)">Xem theo phân quyền</UiButton>
            <UiButton variant="secondary" icon="arrow_back" :href="route('students.index')">Danh sách học sinh</UiButton>
        </template>
    </UiPageHeader>

    <StudentProfile v-bind="$attrs" :student="student" />
</template>
