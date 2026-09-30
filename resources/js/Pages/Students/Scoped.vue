<script setup>
/**
 * Hồ sơ học sinh theo phân quyền (mockup epic-6/chi-tiet-ho-so-hoc-sinh-phan-quyen): cùng bố cục màn Chi tiết, server chỉ
 * gửi các module người xem có quyền (lớp học / điểm danh, liên hệ, học phí); thao tác không có quyền hiển thị ở trạng thái khóa.
 */
import { Link } from '@inertiajs/vue3';
import StudentProfile from '@/Components/Students/StudentProfile.vue';

defineOptions({ layout: { title: 'Hồ sơ học sinh (phân quyền)' } });

defineProps({
    student: { type: Object, required: true },
    viewerRoleLabel: { type: String, default: 'Người dùng' },
    canViewAcademic: { type: Boolean, default: false },
    canViewContact: { type: Boolean, default: false },
    canViewTuition: { type: Boolean, default: false },
});
</script>

<template>
    <UiPageHeader title="Chi tiết hồ sơ học sinh" :description="`${student.name} (${student.code}) — chỉ hiển thị các mục bạn được phân quyền xem.`">
        <template #breadcrumbs>
            <Link :href="route('dashboard')" class="hover:text-primary">Trang chủ</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <Link :href="route('students.index')" class="hover:text-primary">Hồ sơ học sinh</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span class="font-semibold text-primary">Chi tiết</span>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="arrow_back" :href="route('students.show', student.id)">Quay lại hồ sơ</UiButton>
        </template>
    </UiPageHeader>

    <div class="mb-lg flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Đang xem với vai trò</span>
        <UiBadge color="info" pill>{{ viewerRoleLabel }}</UiBadge>
        <span class="ml-auto flex flex-wrap gap-xs">
            <UiBadge :color="canViewAcademic ? 'success' : 'neutral'" :dot="false">{{ canViewAcademic ? '✓' : '✕' }} Lớp học &amp; điểm danh</UiBadge>
            <UiBadge :color="canViewContact ? 'success' : 'neutral'" :dot="false">{{ canViewContact ? '✓' : '✕' }} Liên hệ</UiBadge>
            <UiBadge :color="canViewTuition ? 'success' : 'neutral'" :dot="false">{{ canViewTuition ? '✓' : '✕' }} Học phí</UiBadge>
        </span>
    </div>

    <StudentProfile
        v-bind="$attrs"
        :student="student"
        :viewer-role-label="viewerRoleLabel"
        :can-view-academic="canViewAcademic"
        :can-view-contact="canViewContact"
        :can-view-tuition="canViewTuition"
    />
</template>
