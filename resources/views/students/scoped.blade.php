{{-- Hồ sơ học sinh theo phân quyền (mockup epic-6/chi-tiet-ho-so-hoc-sinh-phan-quyen): cùng bố cục màn Chi tiết, server chỉ
     render các module người xem có quyền (lớp học / điểm danh, liên hệ, học phí); thao tác không có quyền hiển thị ở trạng thái khóa. --}}
@php
    $roleName = auth()->user()?->getRoleNames()->first();
    $roleLabel = $roleName ? \App\Helpers\AclHelper::roleLabel($roleName) : 'Người dùng';
@endphp
<x-app-layout title="Hồ sơ học sinh (phân quyền)">
    <x-ui.page-header title="Chi tiết hồ sơ học sinh" :description="$student->name.' ('.$student->code.') — chỉ hiển thị các mục bạn được phân quyền xem.'">
        <x-slot:breadcrumbs>
            <a href="{{ route('dashboard') }}" class="hover:text-primary">Trang chủ</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <a href="{{ route('students.index') }}" class="hover:text-primary">Hồ sơ học sinh</a>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span class="font-semibold text-primary">Chi tiết</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow_back" :href="route('students.show', $student->id)">Quay lại hồ sơ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-lg flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Đang xem với vai trò</span>
        <x-ui.badge color="info" pill>{{ $roleLabel }}</x-ui.badge>
        <span class="ml-auto flex flex-wrap gap-xs">
            <x-ui.badge :color="$canViewAcademic ? 'success' : 'neutral'" :dot="false">{{ $canViewAcademic ? '✓' : '✕' }} Lớp học &amp; điểm danh</x-ui.badge>
            <x-ui.badge :color="$canViewContact ? 'success' : 'neutral'" :dot="false">{{ $canViewContact ? '✓' : '✕' }} Liên hệ</x-ui.badge>
            <x-ui.badge :color="$canViewTuition ? 'success' : 'neutral'" :dot="false">{{ $canViewTuition ? '✓' : '✕' }} Học phí</x-ui.badge>
        </span>
    </div>

    @include('students.partials.profile')
</x-app-layout>
